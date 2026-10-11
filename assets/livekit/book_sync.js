// Small, identity-checked protocol. No PDF bytes or client paths go over LiveKit.
(function (root) {
  'use strict';
  root.createLessonBookSync = function (cfg) {
    var room = cfg.room, LK = cfg.LK;
    var active = !!(room && LK && room.localParticipant);
    var revision = Date.now(), receivedRevision = -1;
    var state = null, presentation = { view: 'board' }, timer = null, lastSent = 0;
    function queue() {
      if (!active || !cfg.isTutor || timer) return;
      timer = setTimeout(function () {
        timer = null; lastSent = Date.now();
        publish(Object.assign(state ? Object.assign({ t: 'state', rev: revision }, state) : { t: 'close', rev: revision }, presentation));
      }, Math.max(0, 200 - (Date.now() - lastSent)));
    }
    function publish(message) {
      if (!active) return;
      Promise.resolve(room.localParticipant.publishData(new TextEncoder().encode(JSON.stringify(message)),
        { reliable: true, topic: 'book' })).catch(function () { /* reconnect requests current state */ });
    }
    function receive(payload, participant, kind, topic) {
      if (topic !== 'book' || !participant || !participant.identity || !payload || payload.byteLength > 4096) return;
      var message;
      try { message = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; }
      if (!message || typeof message !== 'object') return;
      // Even a syntactically valid student packet can never drive the tutor.
      if (cfg.isTutor) { if (message.t === 'req') queue(); return; }
      if (!cfg.hostIdentity || participant.identity !== cfg.hostIdentity) return;
      if (!Number.isSafeInteger(message.rev) || message.rev <= receivedRevision) return;
      if (message.view !== undefined && !['board', 'book', 'screen'].includes(message.view)) return;
      if (message.t === 'close' && message.view === 'book') return;
      for (var field of ['scrollX', 'scrollY']) {
        if (message[field] !== undefined && (!Number.isFinite(message[field]) || message[field] < 0 || message[field] > 1)) return;
      }
      if (message.board !== undefined && (!message.board || !Number.isFinite(message.board.x)
          || !Number.isFinite(message.board.y) || Math.abs(message.board.x) > 1e9 || Math.abs(message.board.y) > 1e9
          || !Number.isFinite(message.board.zoom) || message.board.zoom < 0.1 || message.board.zoom > 30)) return;
      if (message.t === 'close') {
        receivedRevision = message.rev;
        if (cfg.onPresentation) cfg.onPresentation(message);
        cfg.onClose(); return;
      }
      if (message.t !== 'state' || !Number.isInteger(message.materialId) || message.materialId < 1 || message.materialId > 2147483647
          || !Number.isInteger(message.page) || message.page < 1 || message.page > 1000000
          || !Number.isFinite(message.zoom) || message.zoom < 0.25 || message.zoom > 3 || typeof message.browse !== 'boolean') return;
      receivedRevision = message.rev;
      if (cfg.onPresentation) cfg.onPresentation(message);
      cfg.onState({ materialId: message.materialId, page: message.page, zoom: message.zoom, browse: false,
        scrollX: message.scrollX || 0, scrollY: message.scrollY || 0, rev: message.rev });
    }
    function request() { if (active && !cfg.isTutor) publish({ t: 'req' }); }
    if (active) {
      room.on(LK.RoomEvent.DataReceived, receive);
      room.on(LK.RoomEvent.ParticipantConnected, cfg.isTutor ? queue : request);
      room.on(LK.RoomEvent.Reconnected, cfg.isTutor ? queue : request);
      request();
    }
    return {
      update: function (next, view) {
        if (!cfg.isTutor) return;
        state = next ? { materialId: next.materialId, page: next.page, zoom: next.zoom, browse: next.browse } : null;
        presentation = view || { view: next ? 'book' : 'board' };
        revision = Math.max(revision + 1, Date.now()); queue();
      },
      dispose: function () {
        clearTimeout(timer);
        if (active) {
          room.off(LK.RoomEvent.DataReceived, receive);
          room.off(LK.RoomEvent.ParticipantConnected, cfg.isTutor ? queue : request);
          room.off(LK.RoomEvent.Reconnected, cfg.isTutor ? queue : request);
        }
      },
    };
  };
})(typeof window !== 'undefined' ? window : globalThis);
