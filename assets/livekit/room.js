/**
 * assets/livekit/room.js
 * Shared 1:1 lesson room logic (video + layout) for the LiveKit classroom pages.
 * The whiteboard is the main stage; camera tiles live in the sidebar (a floating
 * strip on narrow screens); a remote screen share swaps into the main area with a
 * toggle back to the board.
 *
 * Requires window.LivekitClient (UMD). initLessonRoom(config) resolves to the
 * connected Room so the whiteboard module can reuse the same connection, or to
 * null when the call could not start — the caller still boots the whiteboard, so
 * a video failure never costs the lesson its board.
 *
 * Page contract (element IDs): boardPane, screenPane, viewToggle, remoteCamera,
 * remoteScreen, remoteAudio, localVideo, remotePlaceholder, remoteOff, remoteOffInitials,
 * localOff, localOffInitials, waitingText, peerLabel, shareChip, deviceNotice,
 * deviceNoticeText, deviceRetryBtn, deviceDismissBtn, errorOverlay, errorText,
 * errorReloadBtn, errorContinueBtn, audioUnlock, btnMic, btnCam, btnShare, btnLeave,
 * statusDot, statusText
 */
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);

  // Connection state and device (mic/camera) trouble are independent facts. They
  // used to share one status line, so "Mic/camera blocked" was written after
  // "Connected" and stuck there for the rest of the lesson — the dot never went
  // green again even once permission was granted. Connection owns the status line
  // now; device trouble gets its own banner, where there is room to say what to do.
  const CONN = {
    fetching:     ['Fetching access…', 'bg-yellow-400'],
    connecting:   ['Connecting…',      'bg-yellow-400'],
    connected:    ['Connected',        'bg-emerald-400'],
    reconnecting: ['Reconnecting…',    'bg-yellow-400'],
    disconnected: ['Disconnected',     'bg-red-500'],
    ending:       ['Ending…',          'bg-gray-500'],
    ended:        ['Lesson ended',     'bg-gray-500'],
  };

  function setConn(state) {
    const s = CONN[state] || CONN.connecting;
    $('statusText').textContent = s[0];
    $('statusDot').className = 'inline-block w-2 h-2 rounded-full flex-none ' + s[1];
    $('statusDot').title = s[0];
  }

  // Initials for the "camera off" tiles, so a dark rectangle still says who it is.
  function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    const s = parts.map((w) => w.charAt(0)).join('').toUpperCase();
    return s || '?';
  }

  // recoverable: the call never started, so offer to carry on with the whiteboard
  // alone rather than only "Reload", which strands anyone whose LiveKit is blocked.
  function showError(msg, recoverable) {
    $('errorText').textContent = msg;
    const cont = $('errorContinueBtn');
    if (cont) {
      cont.classList.toggle('hidden', !recoverable);
      cont.onclick = function () { $('errorOverlay').classList.add('hidden'); };
    }
    $('errorOverlay').classList.remove('hidden');
  }

  // A calm "the lesson has ended" overlay — distinct from the red error state.
  // Repurposes the error overlay: swaps the message and turns the Reload button
  // into a "Back to dashboard" link so nobody is told to "reload to rejoin".
  function showEnded(msg, backUrl) {
    $('errorText').textContent = msg;
    const cont = $('errorContinueBtn');
    if (cont) cont.classList.add('hidden');
    var btn = $('errorReloadBtn');
    if (btn) {
      btn.textContent = 'Back to dashboard';
      btn.onclick = function () { window.location.href = backUrl || 'dashboard.php'; };
    }
    $('errorOverlay').classList.remove('hidden');
  }

  function setBtnActive(btn, active, activeClass, inactiveClass, onLabel, offLabel) {
    btn.classList.toggle(activeClass, active);
    btn.classList.toggle(inactiveClass, !active);
    const on = btn.querySelector('[data-on]');
    const off = btn.querySelector('[data-off]');
    if (on) on.classList.toggle('hidden', !active);
    if (off) off.classList.toggle('hidden', active);
    const label = active ? onLabel : offLabel;
    if (label) { btn.setAttribute('aria-label', label); btn.title = label; }
  }

  window.initLessonRoom = async function (config) {
    if (typeof LivekitClient === 'undefined') {
      showError('Failed to load the video library. Check your internet connection and reload.', true);
      return null;
    }
    const LK = LivekitClient;
    const isHost = !!config.isHost; // tutor ends the lesson for both sides
    let intentionalEnd = false;     // true once we're finishing/leaving on purpose

    setConn('fetching');

    let data;
    try {
      const res = await fetch(config.tokenUrl, { credentials: 'same-origin' });
      const body = await res.json();
      if (!body || !body.success) {
        showError((body && body.message) || 'Could not get room access.', true);
        return null;
      }
      data = body.data;
    } catch (e) {
      showError('Could not reach the server: ' + e.message, true);
      return null;
    }

    $('peerLabel').textContent = data.peerName;
    $('waitingText').textContent = 'Waiting for ' + data.peerName + '…';
    $('remoteOffInitials').textContent = initials(data.peerName);
    $('localOffInitials').textContent = initials(data.displayName);

    // The token endpoint only records attendance inside the join window (from 15
    // minutes before the start), because a join time written days early is read
    // by lesson_sweep as real attendance. This page fetches a token exactly once,
    // so if we arrived early nobody would ever record that we turned up: ask
    // again when the window opens. The endpoint is idempotent.
    if (data.attendanceRegistered === false && data.registerInMs > 0) {
      const delay = Math.min(data.registerInMs + 5000, 2147483647);
      setTimeout(() => {
        fetch(config.tokenUrl, { credentials: 'same-origin' }).catch(() => {});
      }, delay);
    }

    const room = new LK.Room({
      adaptiveStream: true,
      dynacast: true,
      videoCaptureDefaults: { resolution: LK.VideoPresets.h720.resolution },
    });

    const views = window.initLessonViews();
    room.lessonHostIdentity = data.hostIdentity;

    function layout() {
      views.select(views.current());
    }

    // Is the peer actually sending camera video right now? Participant presence is
    // not the same thing: someone who joined with their camera off used to dismiss
    // the "Waiting…" placeholder and reveal an empty black <video>.
    function remoteCameraLive() {
      let live = false;
      room.remoteParticipants.forEach((p) => {
        p.videoTrackPublications.forEach((pub) => {
          if (pub.source === LK.Track.Source.Camera && pub.track && !pub.isMuted) live = true;
        });
      });
      return live;
    }

    function updatePeerTile() {
      const hasPeer = room.remoteParticipants.size > 0;
      const cam = hasPeer && remoteCameraLive();
      $('remotePlaceholder').classList.toggle('hidden', hasPeer);  // spinner: not here yet
      $('remoteOff').classList.toggle('hidden', !hasPeer || cam);  // here, camera off
      $('remoteCamera').classList.toggle('hidden', !cam);
    }

    function updateLocalTile() {
      const lp = room.localParticipant;
      $('localOff').classList.toggle('hidden', lp.isCameraEnabled);
      $('localVideo').classList.toggle('hidden', !lp.isCameraEnabled);
      $('shareChip').classList.toggle('hidden', !lp.isScreenShareEnabled);
    }

    let localScreenTrack = null, remoteScreenTrack = null, displayedScreenTrack = null;
    function updateScreen() {
      // Both participants see the same source, including the sharer's own preview.
      const track = config.isHost ? (localScreenTrack || remoteScreenTrack) : (remoteScreenTrack || localScreenTrack);
      const changed = track !== displayedScreenTrack;
      if (changed && displayedScreenTrack) displayedScreenTrack.detach($('remoteScreen'));
      if (changed && track) track.attach($('remoteScreen'));
      displayedScreenTrack = track;
      views.available('screen', !!track, changed && !!track);
    }
    function handleTrack(track, attach) {
      if (track.kind === LK.Track.Kind.Audio) {
        if (attach) track.attach($('remoteAudio')); else track.detach($('remoteAudio'));
        return;
      }
      if (track.source === LK.Track.Source.ScreenShare) {
        if (attach) remoteScreenTrack = track;
        else if (remoteScreenTrack === track) remoteScreenTrack = null;
        updateScreen();
        layout();
      } else {
        if (attach) track.attach($('remoteCamera')); else track.detach($('remoteCamera'));
        updatePeerTile();
      }
    }

    // Any mute/unmute, local or remote: cheap enough to refresh both tiles and the
    // buttons rather than work out whose track it was.
    function onTrackStateChanged() {
      updatePeerTile();
      updateLocalTile();
      refreshButtons();
    }

    room
      .on(LK.RoomEvent.TrackSubscribed, (track) => handleTrack(track, true))
      .on(LK.RoomEvent.TrackUnsubscribed, (track) => handleTrack(track, false))
      .on(LK.RoomEvent.ParticipantConnected, updatePeerTile)
      // A camera switched off mid-lesson mutes the publication rather than dropping
      // it, so without these the peer tile would freeze on the last frame.
      .on(LK.RoomEvent.TrackMuted, onTrackStateChanged)
      .on(LK.RoomEvent.TrackUnmuted, onTrackStateChanged)
      .on(LK.RoomEvent.ParticipantDisconnected, () => {
        remoteScreenTrack = null;
        updateScreen();
        layout();
        updatePeerTile();
      })
      .on(LK.RoomEvent.LocalTrackPublished, (pub) => {
        if (pub.track && pub.source === LK.Track.Source.ScreenShare) {
          localScreenTrack = pub.track; updateScreen();
        }
        if (pub.track && pub.track.kind === LK.Track.Kind.Video && pub.source === LK.Track.Source.Camera) {
          pub.track.attach($('localVideo'));
        }
        refreshButtons();
        updateLocalTile();
      })
      .on(LK.RoomEvent.LocalTrackUnpublished, (pub) => {
        if (pub.source === LK.Track.Source.ScreenShare) { localScreenTrack = null; updateScreen(); }
        if (pub.track) pub.track.detach();
        refreshButtons();
        updateLocalTile();
      })
      .on(LK.RoomEvent.AudioPlaybackStatusChanged, () => {
        $('audioUnlock').classList.toggle('hidden', room.canPlaybackAudio);
      })
      .on(LK.RoomEvent.ConnectionStateChanged, (state) => {
        if (state === LK.ConnectionState.Connected) setConn('connected');
        else if (state === LK.ConnectionState.Reconnecting) setConn('reconnecting');
        else if (state === LK.ConnectionState.Disconnected) setConn('disconnected');
      })
      .on(LK.RoomEvent.DataReceived, (payload, participant, kind, topic) => {
        if (topic !== 'lesson') return; // whiteboard uses topic 'wb'
        // Only the tutor ends the lesson. This used to accept 'ended' from anyone
        // in the room, so a student could publish one packet and throw the tutor
        // into the "the tutor has ended this lesson" overlay. data.hostIdentity
        // comes from the lessons row server-side, and participant.identity is
        // signed into the LiveKit token, so neither end is client-controlled.
        // A participant with no identity (or a mismatched one) is ignored.
        if (!participant || !data.hostIdentity || participant.identity !== data.hostIdentity) {
          console.warn('ignoring lesson control message from non-host', participant && participant.identity);
          return;
        }
        let msg;
        try { msg = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; }
        if (msg && msg.t === 'ended') {
          intentionalEnd = true;
          setConn('ended');
          showEnded('The tutor has ended this lesson. Thanks for attending!', config.leaveUrl);
        }
      })
      .on(LK.RoomEvent.Disconnected, () => {
        // A deliberate finish/leave (either side) or a tutor-ended lesson must not
        // show the alarming "reload to rejoin" error.
        if (intentionalEnd) {
          setConn('ended');
          return;
        }
        setConn('disconnected');
        showError('You were disconnected from the lesson. Reload the page to rejoin.', false);
      });

    setConn('connecting');
    try {
      await room.connect(data.url, data.token);
    } catch (e) {
      showError('Could not connect to the lesson room: ' + e.message, true);
      return null;
    }
    updatePeerTile();
    layout();

    const btnMic = $('btnMic'), btnCam = $('btnCam'), btnShare = $('btnShare');

    function refreshButtons() {
      const lp = room.localParticipant;
      setBtnActive(btnMic, lp.isMicrophoneEnabled, 'ctl-on', 'ctl-off',
        'Mute microphone', 'Unmute microphone');
      setBtnActive(btnCam, lp.isCameraEnabled, 'ctl-on', 'ctl-off',
        'Turn camera off', 'Turn camera on');
      setBtnActive(btnShare, lp.isScreenShareEnabled, 'ctl-share-on', 'ctl-on',
        'Stop sharing your screen', 'Share your screen');
    }

    // Screen sharing needs getDisplayMedia, which mobile browsers do not have —
    // the button used to sit there anyway and simply threw when tapped.
    if (!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia)) {
      btnShare.classList.add('hidden');
    }

    function deviceNotice(kind) {
      const el = $('deviceNotice');
      if (!el) return;
      $('deviceNoticeText').textContent =
        kind === 'denied'
          ? 'Your browser is blocking the microphone and camera on this page. Open the padlock or camera icon in the address bar, allow both, then choose Try again.'
          : kind === 'missing'
            ? 'No microphone or camera was found. Connect one and choose Try again. The whiteboard works either way.'
            : 'We could not turn on your microphone and camera. Choose Try again. The whiteboard works either way.';
      el.classList.remove('hidden');
    }

    async function enableDevices() {
      try {
        await room.localParticipant.enableCameraAndMicrophone();
        $('deviceNotice').classList.add('hidden');
      } catch (e) {
        const n = e && e.name;
        deviceNotice(n === 'NotAllowedError' ? 'denied' : (n === 'NotFoundError' ? 'missing' : 'other'));
        console.warn('enableCameraAndMicrophone failed:', e);
      }
      refreshButtons();
      updateLocalTile();
    }

    await enableDevices();

    const retryBtn = $('deviceRetryBtn');
    if (retryBtn) {
      retryBtn.addEventListener('click', async () => {
        if (window.setBtnBusy) window.setBtnBusy(retryBtn, true);
        try { await enableDevices(); }
        finally { if (window.setBtnBusy) window.setBtnBusy(retryBtn, false); }
      });
    }
    const dismissBtn = $('deviceDismissBtn');
    if (dismissBtn) {
      dismissBtn.addEventListener('click', () => $('deviceNotice').classList.add('hidden'));
    }

    btnMic.addEventListener('click', async () => {
      try { await room.localParticipant.setMicrophoneEnabled(!room.localParticipant.isMicrophoneEnabled); }
      catch (e) { console.warn(e); }
      refreshButtons();
    });

    btnCam.addEventListener('click', async () => {
      try { await room.localParticipant.setCameraEnabled(!room.localParticipant.isCameraEnabled); }
      catch (e) { console.warn(e); }
      refreshButtons();
      updateLocalTile();
    });

    btnShare.addEventListener('click', async () => {
      if (window.setBtnBusy) window.setBtnBusy(btnShare, true);
      try { await room.localParticipant.setScreenShareEnabled(!room.localParticipant.isScreenShareEnabled); }
      catch (e) { /* user cancelled the picker — ignore */ }
      finally { if (window.setBtnBusy) window.setBtnBusy(btnShare, false); }
      refreshButtons();
      updateLocalTile();
    });

    // End the lesson and leave. When the host (tutor) does this, tell the peer so
    // their side ends gracefully too. We navigate rather than disconnect-then-navigate
    // so the page-unload path finalizes the recording with a keepalive upload (an
    // explicit disconnect first can leave the last chunk's normal fetch to be
    // cancelled by the navigation).
    async function endLesson(navigateUrl) {
      if (intentionalEnd) return;
      intentionalEnd = true;
      setConn('ending');
      // Save the board before navigating. This is the guarantee; the whiteboard's
      // beforeunload beacon is only a fallback and silently drops payloads bigger
      // than ~64KB, which any board with an imported PDF page exceeds. Ending a
      // lesson must store the work, so this is awaited rather than fired and forgotten.
      if (window.lessonWhiteboardFlush) {
        try {
          const saved = await window.lessonWhiteboardFlush();
          if (!saved && !confirm('The whiteboard could not be saved. Leave anyway and lose the latest changes?')) {
            intentionalEnd = false;
            setConn('connected');
            if (window.setBtnBusy) { window.setBtnBusy($('btnLeave'), false); window.setBtnBusy($('btnFinish'), false); }
            return;
          }
        } catch (e) { /* fall through: leaving is still the user's instruction */ }
      }

      // Persist the presented position before ending the peer's room too.
      if (window.lessonBookFlush && !await window.lessonBookFlush()) {
        if (!confirm('The book position could not be saved. Leave anyway?')) {
          intentionalEnd = false; setConn('connected');
          if (window.setBtnBusy) { window.setBtnBusy($('btnLeave'), false); window.setBtnBusy($('btnFinish'), false); }
          return;
        }
      }

      if (isHost) {
        try {
          const bytes = new TextEncoder().encode(JSON.stringify({ t: 'ended' }));
          await room.localParticipant.publishData(bytes, { reliable: true, topic: 'lesson' });
        } catch (e) { /* best effort */ }
        await new Promise((r) => setTimeout(r, 300));
      }
      // Finalize the recording (flush + wait for all uploads) before leaving.
      if (window.lessonRecorderFinish) {
        try { await window.lessonRecorderFinish(); } catch (e) { /* proceed anyway */ }
      }
      window.location.href = navigateUrl || config.leaveUrl;
    }
    window.lessonRoomEnd = endLesson;

    $('btnLeave').addEventListener('click', () => {
      if (isHost && !confirm('End this lesson for you and the student?')) return;
      if (window.setBtnBusy) window.setBtnBusy($('btnLeave'), true);
      endLesson(config.leaveUrl);
    });

    $('audioUnlock').addEventListener('click', async () => {
      const btn = $('audioUnlock');
      if (window.setBtnBusy) window.setBtnBusy(btn, true);
      try { await room.startAudio(); btn.classList.add('hidden'); }
      catch (e) { console.warn('could not start audio', e); }
      finally { if (window.setBtnBusy) window.setBtnBusy(btn, false); }
    });

    window.addEventListener('beforeunload', () => { intentionalEnd = true; room.disconnect(); });

    window.lessonRoom = room;
    return room;
  };
})();
