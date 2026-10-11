/**
 * tests/classroom/browser_board.mjs
 *
 * Real-browser tests for the whiteboard client against the disposable app copy.
 *
 * What this can and cannot prove, stated up front so the results are not oversold:
 *
 *  CAN   - Excalidraw mounts, the board loads via the workspace API, page identity
 *          and revision arrive on the client, a save round-trips, a stale save is
 *          refused rather than applied, a failed load blocks saving instead of
 *          overwriting, the eraser leaves imported images alone while Delete removes
 *          them, and lesson B shows lesson A's work in a real page.
 *
 *  CANNOT - anything requiring LiveKit. There are no LiveKit credentials in the test
 *          environment, so the two browser contexts do not share a live data channel.
 *          Peer-to-peer sync, remote erase echo, and reconnect behaviour are NOT
 *          covered here and still need two real browsers against a real LiveKit
 *          project. The board itself is exercised because it is designed to work
 *          with room === null.
 *
 * Run:  node tests/classroom/browser_board.mjs
 * Needs: the PHP server on 127.0.0.1:8099 and a prepared database.
 */

import { chromium } from 'playwright';

const BASE = 'http://127.0.0.1:8099';
const PASSWORD = 'ClassroomTest123!';

let passed = 0;
const failed = [];

function check(cond, label) {
  if (cond) { passed++; console.log('PASS  ' + label); }
  else { failed.push(label); console.log('FAIL  ' + label); }
}

/** A browser context signed in as one fixture user. */
async function signIn(browser, email) {
  const context = await browser.newContext();
  // Exercise real authentication without navigating to unrelated dashboards. The
  // classroom schema deliberately lacks some dashboard-only subject columns.
  const response = await context.request.post(BASE + '/authenticate.php', {
    form: { email, password: PASSWORD }, maxRedirects: 0,
  });
  if (response.status() !== 302) throw new Error('Fixture login failed: ' + email);
  const page = await context.newPage();
  page.on('pageerror', (e) => console.log('   [pageerror] ' + e.message));
  return { context, page };
}

/**
 * Drive the whiteboard module directly on a blank page.
 *
 * The room shell cannot be loaded here: it calls initLessonRoom first, which needs
 * LiveKit credentials the test environment deliberately does not have. Loading the
 * same vendor bundles and calling initWhiteboard(null, ...) exercises the real
 * client code -- load, save, merge, eraser -- against the real API and real session.
 */
async function bootBoard(page, lessonId) {
  await page.goto(BASE + '/tests-harness-board.php?lesson_id=' + lessonId);
  await page.waitForFunction(() => window.lessonWhiteboard !== undefined, { timeout: 20000 });
  await page.waitForFunction(() => window.__boardApi !== null && window.__boardApi !== undefined, { timeout: 20000 });
}

const browser = await chromium.launch();

try {
  const tutor = await signIn(browser, 'tara@example.test');
  const student = await signIn(browser, 'sam@example.test');

  console.log('--- the board loads through the workspace API ---');
  await bootBoard(tutor.page, 101);

  const identity = await tutor.page.evaluate(() => ({
    pageId: window.lessonWhiteboard.getPageId(),
    revision: window.lessonWhiteboard.getRevision(),
    workspaceId: window.lessonWhiteboard.getWorkspaceId(),
    loadFailed: window.lessonWhiteboard.isLoadFailed(),
  }));
  check(identity.loadFailed === false, 'the board loaded without error');
  check(Number.isInteger(identity.pageId) && identity.pageId > 0, 'the client received a page id');
  check(Number.isInteger(identity.revision), 'the client received a revision');
  check(Number.isInteger(identity.workspaceId) && identity.workspaceId > 0, 'the client received a workspace id');

  console.log('\n--- drawing saves and the revision advances ---');
  const before = identity.revision;
  await tutor.page.evaluate(() => {
    const api = window.__boardApi;
    api.updateScene({
      elements: [{
        id: 'browser-stroke', type: 'rectangle', x: 10, y: 10, width: 100, height: 50,
        angle: 0, strokeColor: '#000', backgroundColor: 'transparent', fillStyle: 'solid',
        strokeWidth: 2, strokeStyle: 'solid', roughness: 1, opacity: 100, groupIds: [],
        frameId: null, roundness: null, seed: 1, version: 5, versionNonce: 7,
        isDeleted: false, boundElements: null, updated: Date.now(), link: null, locked: false,
      }],
    });
  });
  const savedOk = await tutor.page.evaluate(() => window.lessonWhiteboard.flush());
  check(savedOk === true, 'an explicit flush reports success');

  const after = await tutor.page.evaluate(() => window.lessonWhiteboard.getRevision());
  check(after > before, 'the revision advanced after saving (' + before + ' -> ' + after + ')');

  console.log('\n--- the other participant sees it after reload ---');
  await bootBoard(student.page, 101);
  const studentSees = await student.page.evaluate(() =>
    window.__boardApi.getSceneElementsIncludingDeleted().map((e) => e.id));
  check(studentSees.includes('browser-stroke'), "the student's board loads the tutor's work");

  console.log('\n--- continuity: a different lesson, same pair ---');
  await bootBoard(tutor.page, 103);
  const lessonBSees = await tutor.page.evaluate(() => ({
    ids: window.__boardApi.getSceneElementsIncludingDeleted().map((e) => e.id),
    ws: window.lessonWhiteboard.getWorkspaceId(),
  }));
  check(lessonBSees.ids.includes('browser-stroke'),
    "lesson B opens with lesson A's work on the board");
  check(lessonBSees.ws === identity.workspaceId, 'and resolves to the same workspace');

  console.log('\n--- a stale client cannot overwrite ---');
  // Force the client's revision backwards, then try to save an empty board: exactly
  // the "my tab was open all afternoon" case.
  const staleResult = await tutor.page.evaluate(async () => {
    const res = await fetch(window.__saveUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        page_id: window.lessonWhiteboard.getPageId(),
        revision: 0,
        scene: [], files: {},
      }),
    });
    return res.status;
  });
  check(staleResult === 409, 'the server refuses a save based on a superseded revision');

  await bootBoard(tutor.page, 101);
  const survived = await tutor.page.evaluate(() =>
    window.__boardApi.getSceneElementsIncludingDeleted().map((e) => e.id));
  check(survived.includes('browser-stroke'), 'and the work is still there afterwards');

  console.log('\n--- a failed load blocks saving instead of overwriting ---');
  const blocked = await student.page.evaluate(async () => {
    // Make the board module's next load fail the way a network blip would.
    window.__forceLoadFailure = true;
    return true;
  });
  // Re-boot the harness with the failure flag set before initWhiteboard runs.
  await student.page.goto(BASE + '/tests-harness-board.php?lesson_id=101&fail_load=1');
  await student.page.waitForFunction(() => window.lessonWhiteboard !== undefined, { timeout: 20000 });
  const failState = await student.page.evaluate(() => ({
    failed: window.lessonWhiteboard.isLoadFailed(),
    flush: null,
  }));
  check(failState.failed === true, 'the client latches into a load-failed state');

  const refusedFlush = await student.page.evaluate(() => window.lessonWhiteboard.flush());
  check(refusedFlush === false, 'and refuses to flush, so it cannot write an empty board');

  // Prove nothing was destroyed.
  await tutor.page.goto(BASE + '/api/whiteboard.php?lesson_id=101');
  const raw = await tutor.page.evaluate(() => document.body.innerText);
  check(raw.includes('browser-stroke'), 'the stored board is untouched after the failed load');

  console.log('\n--- eraser leaves imported images alone; Delete removes them ---');
  await bootBoard(tutor.page, 101);
  await tutor.page.evaluate(() => {
    const api = window.__boardApi;
    const els = api.getSceneElementsIncludingDeleted();
    api.addFiles([{
      id: 'testfile1',
      dataURL: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMfsAAAAASUVORK5CYII=',
      mimeType: 'image/png', created: Date.now(),
    }]);
    api.updateScene({
      elements: els.concat([{
        id: 'imported-page', type: 'image', x: 0, y: 200, width: 300, height: 200,
        angle: 0, strokeColor: 'transparent', backgroundColor: 'transparent',
        fillStyle: 'hachure', strokeWidth: 1, strokeStyle: 'solid', roughness: 1,
        opacity: 100, groupIds: [], frameId: null, roundness: null, seed: 2,
        version: 1, versionNonce: 9, isDeleted: false, boundElements: null,
        updated: Date.now(), link: null, locked: false, status: 'saved',
        fileId: 'testfile1', scale: [1, 1],
        customData: { assetKind: 'import', origin: 'pdf' },
      }]),
    });
  });

  // Simulate the eraser deleting both a stroke and the image, the way Excalidraw
  // does: mark isDeleted while the eraser tool is active.
  const eraseOutcome = await tutor.page.evaluate(async () => {
    const api = window.__boardApi;
    api.setActiveTool({ type: 'eraser' });
    await new Promise((r) => setTimeout(r, 50));
    const els = api.getSceneElementsIncludingDeleted().map((e) =>
      (e.id === 'imported-page' || e.id === 'browser-stroke')
        ? Object.assign({}, e, { isDeleted: true, version: (e.version || 1) + 1 })
        : e);
    api.updateScene({ elements: els });
    await new Promise((r) => setTimeout(r, 200));
    const now = api.getSceneElementsIncludingDeleted();
    return {
      image: now.find((e) => e.id === 'imported-page')?.isDeleted,
      stroke: now.find((e) => e.id === 'browser-stroke')?.isDeleted,
    };
  });
  check(eraseOutcome.image === false, 'the eraser did NOT remove the imported image');
  check(eraseOutcome.stroke === true, 'but the hand-drawn stroke over it WAS erased');

  const deleteOutcome = await tutor.page.evaluate(async () => {
    const api = window.__boardApi;
    api.setActiveTool({ type: 'selection' });
    await new Promise((r) => setTimeout(r, 50));
    const els = api.getSceneElementsIncludingDeleted().map((e) =>
      e.id === 'imported-page'
        ? Object.assign({}, e, { isDeleted: true, version: (e.version || 1) + 1 })
        : e);
    api.updateScene({ elements: els });
    await new Promise((r) => setTimeout(r, 200));
    return api.getSceneElementsIncludingDeleted().find((e) => e.id === 'imported-page')?.isDeleted;
  });
  check(deleteOutcome === true, 'a deliberate delete (selection tool) DOES remove the image');

  const persisted = await tutor.page.evaluate(() => window.lessonWhiteboard.flush());
  check(persisted === true, 'and that deletion saves');

  await bootBoard(tutor.page, 101);
  const afterReload = await tutor.page.evaluate(() => {
    const img = window.__boardApi.getSceneElementsIncludingDeleted().find((e) => e.id === 'imported-page');
    return img ? img.isDeleted : 'absent';
  });
  check(afterReload === true || afterReload === 'absent',
    'the deleted image stays deleted after a reload');

} finally {
  await browser.close();
}

console.log('\npassed: ' + passed);
if (failed.length) {
  console.log('FAILED: ' + failed.length);
  failed.forEach((f) => console.log('  - ' + f));
  process.exit(1);
}
console.log('all green');
