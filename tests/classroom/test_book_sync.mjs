import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const file = process.argv[2];
if (!file) throw new Error('Pass the production book_sync.js path');
const sandbox = { TextEncoder, TextDecoder, setTimeout, clearTimeout, Date };
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox);
const LK = { RoomEvent: { DataReceived: 'data', ParticipantConnected: 'join', Reconnected: 'rejoin' } };
let count = 0;
function check(label, fn) { fn(); count++; console.log('PASS  ' + label); }
function mock() {
  const handlers = {}, packets = [];
  return { packets, handlers, localParticipant: { publishData: async (payload, options) => packets.push({ message: JSON.parse(new TextDecoder().decode(payload)), options, time: Date.now() }) },
    on(event, fn) { handlers[event] = fn; }, off(event) { delete handlers[event]; } };
}
function send(room, message, identity = 'host', topic = 'book') {
  room.handlers.data(new TextEncoder().encode(JSON.stringify(message)), { identity }, null, topic);
}
const state = { t: 'state', materialId: 10, page: 2, zoom: 1, browse: false, rev: 10 };
const tutorRoom = mock(), studentRoom = mock();
let tutorMoves = 0, studentStates = [], closes = 0, presentations = [];
const tutor = sandbox.createLessonBookSync({ room: tutorRoom, LK, isTutor: true, hostIdentity: 'host', onState: () => tutorMoves++, onClose: () => tutorMoves++ });
const student = sandbox.createLessonBookSync({ room: studentRoom, LK, isTutor: false, hostIdentity: 'host', onState: s => studentStates.push(s), onClose: () => closes++, onPresentation: p => presentations.push(p) });
check('student requests current state on join', () => assert.equal(studentRoom.packets[0].message.t, 'req'));
check('student control packets cannot move tutor, including forged close', () => {
  send(tutorRoom, state, 'student'); send(tutorRoom, { t: 'close', rev: 11 }, 'student'); assert.equal(tutorMoves, 0);
});
check('untrusted and missing host identities cannot move student', () => {
  send(studentRoom, state, 'student'); send(studentRoom, state, ''); send(studentRoom, state, 'host', 'wb'); assert.equal(studentStates.length, 0);
});
check('authentic tutor state is accepted', () => { send(studentRoom, state); assert.equal(studentStates.length, 1); assert.equal(studentStates[0].page, 2); });
check('stale state and stale close are dropped', () => {
  send(studentRoom, { ...state, rev: 9, page: 1 }); send(studentRoom, { t: 'close', rev: 9 }); assert.equal(studentStates.length, 1); assert.equal(closes, 0);
});
check('invalid fields do not consume revision', () => {
  for (const change of [{page: 0}, {page: 1.1}, {materialId: '10'}, {zoom: 0}, {zoom: 9}, {browse: 'true'}, {rev: '12'}]) send(studentRoom, { ...state, rev: 12, ...change });
  assert.equal(studentStates.length, 1); send(studentRoom, { ...state, rev: 11 }); assert.equal(studentStates.length, 2);
});
check('oversized or malformed packets are ignored', () => {
  send(studentRoom, { ...state, rev: 14, extra: 'x'.repeat(5000) });
  studentRoom.handlers.data(new TextEncoder().encode('{broken'), { identity: 'host' }, null, 'book'); assert.equal(studentStates.length, 2);
});
check('host close is revisioned and cannot be undone by an older state', () => {
  send(studentRoom, { t: 'close', rev: 20 }); send(studentRoom, { ...state, rev: 19 }); assert.equal(closes, 1); assert.equal(studentStates.length, 2);
});
check('view, scroll and board viewport arrive together with the book state', () => {
  send(studentRoom, {...state, rev:21, view:'board', scrollX:0.3, scrollY:0.7, board:{x:120,y:1500,zoom:1.25}});
  assert.equal(presentations.at(-1).view, 'board');
  assert.equal(presentations.at(-1).board.y, 1500);
  assert.equal(studentStates.at(-1).scrollY, 0.7);
});
check('invalid presentation fields cannot consume a valid revision or redirect the student', () => {
  const before = presentations.length;
  for (const extra of [{view:'settings'}, {scrollY:2}, {scrollX:-1}, {board:{x:1,y:1,zoom:0}}, {board:null}, {board:{x:'1',y:1,zoom:1}}]) {
    send(studentRoom, {...state,rev:22,...extra});
  }
  send(studentRoom, {...state,rev:22,view:'screen'}, 'student');
  assert.equal(presentations.length,before);
  send(studentRoom, {...state,rev:22,view:'screen'});
  assert.equal(presentations.at(-1).view,'screen');
});
check('closing a book preserves the tutor screen view', () => {
  send(studentRoom,{t:'close',rev:23,view:'screen'});
  assert.equal(presentations.at(-1).view,'screen');
  assert.equal(closes,2);
});
tutor.update(state);
await new Promise(r => setTimeout(r, 30));
for (let page = 1; page <= 20; page++) { tutor.update({ ...state, page }); send(tutorRoom, {t:'req'}, 'student'); }
await new Promise(r => setTimeout(r, 240));
check('bursts coalesce into at most five sends per second, with the latest page', () => {
  assert.equal(tutorRoom.packets.length, 2);
  assert.ok(tutorRoom.packets[1].time - tutorRoom.packets[0].time >= 190);
  assert.equal(tutorRoom.packets[1].message.page, 20);
  assert.ok(tutorRoom.packets[1].message.rev > tutorRoom.packets[0].message.rev);
  assert.equal(tutorRoom.packets[1].options.topic, 'book');
});
tutorRoom.handlers.rejoin(); await new Promise(r => setTimeout(r, 210));
check('reconnection resends current state', () => assert.equal(tutorRoom.packets.at(-1).message.page, 20));
tutor.update(null); await new Promise(r => setTimeout(r, 210));
check('close uses the same ordered revision sequence', () => {
  assert.equal(tutorRoom.packets.at(-1).message.t, 'close');
  assert.ok(tutorRoom.packets.at(-1).message.rev > tutorRoom.packets.at(-2).message.rev);
});
studentRoom.handlers.rejoin();
check('student asks again after reconnecting', () => assert.equal(studentRoom.packets.at(-1).message.t, 'req'));
check('missing hostIdentity fails closed', () => {
  const room = mock(); const s = sandbox.createLessonBookSync({ room, LK, isTutor: false, onState: () => { throw new Error('must not run'); }, onClose: () => { throw new Error('must not run'); } });
  send(room, state); s.dispose();
});
check('no-call mode accepts local tutor updates without sending', () => {
  const local = sandbox.createLessonBookSync({ room: null, LK, isTutor: true }); local.update(state); local.update(null); local.dispose();
});
tutor.dispose(); student.dispose();
console.log(`Book protocol checks: ${count} passed`);
