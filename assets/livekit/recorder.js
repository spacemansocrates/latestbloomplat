/**
 * assets/livekit/recorder.js
 * Automatic lesson recording for the LiveKit classroom (runs on the tutor side).
 *
 * Recording is NOT manual: it starts by itself the moment both participants are
 * in the room (lessons are recorded by agreement, for integrity and so students
 * can review them later). No screen-capture picker is involved — the recording
 * is composited programmatically:
 *   video: an offscreen canvas redrawn ~15x/s with the visible whiteboard, book
 *          page, or shared screen, plus both camera tiles
 *   audio: tutor mic + student audio mixed through WebAudio
 * Chunks stream to tutor/api/upload_recording.php every 5s (the pipeline the
 * recordings pages already read), so a crash loses seconds, not the lesson.
 *
 * Usage: initRecorder(room, { lessonId, uploadUrl, indicator })
 */
(function () {
  'use strict';

  var W = 1280, H = 720, SIDE = 320, FPS = 15;

  window.initRecorder = function (room, cfg) {
    var LK = LivekitClient;
    var recorder = null;
    var audioCtx = null;
    var drawTimer = null;
    var uiTimer = null;
    var startedAt = 0;
    var started = false;
    var finalized = false;
    var firstChunk = true;
    var uploading = Promise.resolve(); // serialises uploads: server appends chunks in order

    var indicator = cfg.indicator;
    var timerEl = indicator ? indicator.querySelector('[data-timer]') : null;

    function fmt(s) {
      return Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0');
    }

    function uploadChunk(blob, isLast, keepalive) {
      uploading = uploading.then(function () {
        var fd = new FormData();
        fd.append('lesson_id', cfg.lessonId);
        if (blob) fd.append('chunk', blob, 'chunk.webm');
        if (firstChunk) { fd.append('reset', '1'); firstChunk = false; }
        if (isLast) {
          fd.append('is_last', '1');
          fd.append('duration_seconds', Math.round((Date.now() - startedAt) / 1000));
        }
        return fetch(cfg.uploadUrl, {
          method: 'POST', credentials: 'same-origin', body: fd, keepalive: !!keepalive,
        }).catch(function (e) { console.warn('recording chunk upload failed', e); });
      });
      return uploading;
    }

    // draw a video/canvas source into a region, preserving aspect ratio
    function drawFit(ctx, src, sw, sh, x, y, w, h) {
      if (!sw || !sh) return;
      var scale = Math.min(w / sw, h / sh);
      var dw = sw * scale, dh = sh * scale;
      ctx.drawImage(src, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    }

    function makeVideoTrack() {
      var canvas = document.createElement('canvas');
      canvas.width = W; canvas.height = H;
      var ctx = canvas.getContext('2d');
      var mainW = W - SIDE;

      drawTimer = setInterval(function () {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, W, H);

        // main region: shared screen when visible, otherwise the whiteboard layers
        var screenPane = document.getElementById('screenPane');
        var screenVideo = document.getElementById('remoteScreen');
        var bookPane = document.getElementById('bookPane');
        var bookCanvas = document.getElementById('bookCanvas');
        if (screenPane && !screenPane.classList.contains('hidden') && screenVideo && screenVideo.videoWidth) {
          ctx.fillStyle = '#000';
          ctx.fillRect(0, 0, mainW, H);
          drawFit(ctx, screenVideo, screenVideo.videoWidth, screenVideo.videoHeight, 0, 0, mainW, H);
        } else if (bookPane && !bookPane.classList.contains('hidden')) {
          if (bookCanvas && !bookCanvas.classList.contains('hidden') && bookCanvas.width) {
            drawFit(ctx, bookCanvas, bookCanvas.width, bookCanvas.height, 0, 0, mainW, H);
          }
        } else {
          var layers = document.querySelectorAll('#whiteboard canvas');
          for (var i = 0; i < layers.length; i++) {
            drawFit(ctx, layers[i], layers[i].width, layers[i].height, 0, 0, mainW, H);
          }
        }

        // sidebar: student on top, tutor below
        ctx.fillStyle = '#111827';
        ctx.fillRect(mainW, 0, SIDE, H);
        var remote = document.getElementById('remoteCamera');
        var local = document.getElementById('localVideo');
        if (remote && remote.videoWidth) drawFit(ctx, remote, remote.videoWidth, remote.videoHeight, mainW, 10, SIDE, 230);
        if (local && local.videoWidth) drawFit(ctx, local, local.videoWidth, local.videoHeight, mainW, 250, SIDE, 230);
      }, Math.round(1000 / FPS));

      return canvas.captureStream(FPS).getVideoTracks()[0];
    }

    function makeAudioTrack() {
      audioCtx = new AudioContext();
      var dest = audioCtx.createMediaStreamDestination();

      function addTrack(mediaStreamTrack) {
        if (!mediaStreamTrack) return;
        try {
          audioCtx.createMediaStreamSource(new MediaStream([mediaStreamTrack])).connect(dest);
        } catch (e) { console.warn('audio mix failed', e); }
      }

      // tutor mic
      var micPub = room.localParticipant.getTrackPublication(LK.Track.Source.Microphone);
      if (micPub && micPub.track) addTrack(micPub.track.mediaStreamTrack);
      room.on(LK.RoomEvent.LocalTrackPublished, function (pub) {
        if (pub.source === LK.Track.Source.Microphone && pub.track) addTrack(pub.track.mediaStreamTrack);
      });

      // student audio: anything already subscribed + anything that arrives later
      room.remoteParticipants.forEach(function (p) {
        p.audioTrackPublications.forEach(function (pub) {
          if (pub.track) addTrack(pub.track.mediaStreamTrack);
        });
      });
      room.on(LK.RoomEvent.TrackSubscribed, function (track) {
        if (track.kind === LK.Track.Kind.Audio) addTrack(track.mediaStreamTrack);
      });

      // browsers keep fresh AudioContexts suspended until a user gesture
      if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(function () {});
        var resume = function () {
          audioCtx.resume().catch(function () {});
          document.removeEventListener('pointerdown', resume);
        };
        document.addEventListener('pointerdown', resume);
      }

      return dest.stream.getAudioTracks()[0];
    }

    function start() {
      if (started) return;
      started = true;
      startedAt = Date.now();
      firstChunk = true;

      var stream = new MediaStream([makeVideoTrack(), makeAudioTrack()]);
      var mime = MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')
        ? 'video/webm;codecs=vp8,opus' : 'video/webm';
      recorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 1000000 });

      recorder.ondataavailable = function (ev) {
        if (ev.data && ev.data.size > 0) uploadChunk(ev.data, false);
      };
      recorder.onstop = function () {
        clearInterval(drawTimer);
        clearInterval(uiTimer);
        if (audioCtx) { audioCtx.close().catch(function () {}); audioCtx = null; }
        if (!finalized) { finalized = true; uploadChunk(null, true); }
        if (indicator) indicator.classList.add('hidden');
      };

      recorder.start(5000);

      if (indicator) {
        indicator.classList.remove('hidden');
        uiTimer = setInterval(function () {
          if (timerEl) timerEl.textContent = fmt((Date.now() - startedAt) / 1000);
        }, 1000);
      }
    }

    function stop(viaUnload) {
      if (recorder && recorder.state === 'recording') {
        if (viaUnload && !finalized) {
          // page is going away: finalize now with keepalive; the very last
          // <=5s chunk may be lost, the rest is already on the server
          finalized = true;
          uploadChunk(null, true, true);
        }
        recorder.stop();
      }
    }

    // Deliberate finish (tutor clicks Finish/Leave): flush the current buffer, stop,
    // and resolve only once every chunk — including the final one — has uploaded, so
    // the caller can safely navigate without losing the tail of the recording.
    window.lessonRecorderFinish = function () {
      if (!recorder || recorder.state !== 'recording') return Promise.resolve();
      return new Promise(function (resolve) {
        recorder.onstop = function () {
          clearInterval(drawTimer);
          clearInterval(uiTimer);
          if (audioCtx) { audioCtx.close().catch(function () {}); audioCtx = null; }
          if (!finalized) { finalized = true; uploadChunk(null, true); }
          if (indicator) indicator.classList.add('hidden');
          uploading.then(function () { resolve(); });
        };
        try { recorder.requestData(); } catch (e) {} // flush the last partial chunk
        recorder.stop();
      });
    };

    // Start recording as soon as the tutor is in the room — the lesson is recorded
    // for integrity even if the student never joins (the composite still captures
    // the whiteboard, the tutor's video and audio). start() is idempotent, so the
    // ParticipantConnected handler is just a safety net if the first attempt was
    // too early (e.g. before the local camera track was ready).
    start();
    room.on(LK.RoomEvent.ParticipantConnected, start);

    var leaveBtn = document.getElementById('btnLeave');
    if (leaveBtn) leaveBtn.addEventListener('click', function () { stop(false); }, { capture: true });
    window.addEventListener('beforeunload', function () { stop(true); });
    room.on(LK.RoomEvent.Disconnected, function () { stop(false); });
  };
})();
