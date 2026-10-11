import { chromium } from 'playwright';
import assert from 'node:assert/strict';
const BASE = 'http://127.0.0.1:8099';
let passed = 0;
const errors = [], externalRequests = [];
const browser = await chromium.launch();
async function check(label, fn) { await fn(); passed++; console.log('PASS  ' + label); }
async function account(email) {
  const context = await browser.newContext();
  await context.route('**/*', route => {
    if (new URL(route.request().url()).origin === BASE) return route.continue();
    externalRequests.push(route.request().url()); return route.abort();
  });
  const res = await context.request.post(BASE + '/authenticate.php', { form: { email, password: 'ClassroomTest123!' }, maxRedirects: 0 });
  assert.equal(res.status(), 302);
  return context;
}
async function boot(context, query = '', size = { width: 1280, height: 900 }) {
  // Every shell load gets a fresh tab and fresh console, including inline JS.
  const page = await context.newPage(); await page.setViewportSize(size);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) errors.push(message.text());
  });
  await page.goto(BASE + '/tutor/tests-harness-books.php?lesson_id=103' + query);
  await page.waitForFunction(() => window.lessonBooks && window.lessonWhiteboard?.getApi());
  return page;
}
async function ready(page, materialId, pageNumber, zoom = null) {
  await page.waitForFunction(([materialId, pageNumber, zoom]) => {
    const canvas = document.getElementById('bookCanvas');
    return canvas && !canvas.classList.contains('hidden') && Number(canvas.dataset.materialId) === materialId
      && Number(canvas.dataset.page) === pageNumber && (zoom === null || Number(canvas.dataset.zoom) === zoom);
  }, [materialId, pageNumber, zoom]);
}
async function open(page, id) {
  await page.locator('#btnBooks').click();
  await page.locator('.book-choice[data-material-id="' + id + '"]').click();
}
async function packet(page, message, identity = 'host-1') {
  await page.evaluate(({message, identity}) => window.__emit(LivekitClient.RoomEvent.DataReceived,
    new TextEncoder().encode(JSON.stringify(message)), { identity }, null, 'book'), { message, identity });
}
try {
  const tutor = await account('tara@example.test');
  const student = await account('sam@example.test');
  const page = await boot(tutor);
  const rangeStatuses = [];
  const cdp = await page.context().newCDPSession(page); await cdp.send('Network.enable');
  const pdfRequests = new Set(); let pdfBytes = 0;
  cdp.on('Network.requestWillBeSent', event => { if (event.request.url.includes('stream=10')) pdfRequests.add(event.requestId); });
  cdp.on('Network.dataReceived', event => { if (pdfRequests.has(event.requestId)) pdfBytes += event.dataLength; });
  page.on('response', response => { if (response.url().includes('stream=10')) rangeStatuses.push(response.status()); });
  await check('full room shell boots with room === null, Board is initially visible', async () => {
    assert.equal(await page.evaluate(() => lessonViews.current()), 'board');
    assert.equal(await page.locator('#bookPane').isVisible(), false);
  });
  await check('picker groups subject books first and renders hostile title/description as text', async () => {
    await page.locator('#btnBooks').click();
    await page.locator('.book-choice[data-material-id="10"]').waitFor();
    assert.deepEqual(await page.locator('#bookList h3').allTextContents(), ['For this subject', 'All books']);
    assert.match(await page.locator('#bookList').innerText(), /<img src=x onerror=alert\(1\)> tutor's/);
    assert.equal(await page.locator('#bookList img, #bookList b').count(), 0);
    await page.locator('#bookSearch').fill('history');
    assert.equal(await page.locator('.book-choice').count(), 1);
    await page.locator('#bookSearch').fill('');
    await page.locator('.book-choice[data-material-id="10"]').click(); await ready(page, 10, 1);
  });
  await check('viewer uses 206 ranges and does not fetch the complete 4 MB fixture', async () => {
    assert.ok(rangeStatuses.includes(206), JSON.stringify(rangeStatuses));
    assert.ok(pdfBytes > 0 && pdfBytes < 2 * 1024 * 1024, 'PDF bytes received: ' + pdfBytes);
    console.log('  PDF bytes received for initial page and adjacent prefetch: ' + pdfBytes);
  });
  await check('next, previous, zoom, fit and jump render the requested page', async () => {
    await page.locator('#bookNext').click(); await ready(page, 10, 2);
    await page.locator('#bookPrev').click(); await ready(page, 10, 1);
    await page.locator('#bookZoomIn').click(); await ready(page, 10, 1, 1.25);
    assert.equal(await page.locator('#bookZoomLabel').innerText(), '125%');
    await page.locator('#bookZoomOut').click(); await ready(page, 10, 1, 1);
    assert.equal(await page.locator('#bookZoomLabel').innerText(), '100%');
    await page.locator('#bookJump').fill('4'); await page.locator('#bookGo').click(); await ready(page, 10, 4);
    assert.equal(await page.locator('#bookNext').isDisabled(), true);
    await page.locator('#bookFit').click(); await ready(page, 10, 4);
  });
  await check('rapid page changes cancel stale renders; keyboard ignores inputs', async () => {
    await page.locator('#bookViewport').focus();
    await page.keyboard.press('ArrowLeft'); await page.keyboard.press('ArrowLeft'); await page.keyboard.press('ArrowRight');
    await ready(page, 10, 3);
    await page.locator('#bookJump').focus(); await page.keyboard.press('ArrowLeft');
    assert.equal(await page.evaluate(() => lessonBooks.getState().page), 3);
    await page.locator('#bookViewport').focus();
  });
  await check('thumbnails are always visible and navigate the book, controls use SVG icons', async () => {
    await page.locator('#bookThumbnails canvas').first().waitFor();
    assert.equal(await page.locator('#bookThumbnails').isVisible(), true);
    assert.equal(await page.locator('#bookThumbToggle').count(), 0);
    assert.equal(await page.locator('#bookPrev svg, #bookNext svg, #bookZoomIn svg, #bookZoomOut svg, #bookFit svg').count(), 5);
    await page.locator('.book-thumb[data-page="2"]').click(); await ready(page, 10, 2);
  });
  await check('an idle page does not redraw repeatedly as scrollbars appear', async () => {
    const redraws = await page.evaluate(async () => {
      let redraws = 0;
      const observer = new MutationObserver(records => { redraws += records.filter(r => r.attributeName === 'width').length; });
      observer.observe(document.getElementById('bookCanvas'), {attributes:true,attributeFilter:['width']});
      await new Promise(resolve => setTimeout(resolve, 800)); observer.disconnect(); return redraws;
    });
    assert.ok(redraws <= 1, 'Unexpected repeated redraws: ' + redraws);
  });
  await check('book page becomes a JPEG board image with library provenance and still saves', async () => {
    const before = await page.evaluate(() => lessonWhiteboard.getApi().getSceneElements().filter(e => e.type === 'image').length);
    await page.locator('#bookToBoard').click();
    await page.waitForFunction(n => lessonWhiteboard.getApi().getSceneElements().filter(e => e.type === 'image').length === n + 1, before);
    assert.equal(await page.evaluate(() => lessonViews.current()), 'board');
    const result = await page.evaluate(async () => {
      const api = lessonWhiteboard.getApi(), image = api.getSceneElements().filter(e => e.type === 'image').at(-1), file = api.getFiles()[image.fileId];
      return { data: image.customData, mime: file.mimeType, bytes: file.dataURL.length, saved: await lessonWhiteboard.flush(), revision: lessonWhiteboard.getRevision() };
    });
    assert.deepEqual(result.data, {assetKind:'import',origin:'library',materialId:10,page:2});
    assert.equal(result.mime, 'image/jpeg'); assert.ok(result.bytes < 600000); assert.equal(result.saved, true); assert.ok(result.revision > 0);
    await page.locator('#view-book').click(); await ready(page, 10, 2);
  });
  await check('full-board guard refuses an import without adding an unsavable image', async () => {
    const before = await page.evaluate(() => {
      const api = lessonWhiteboard.getApi(); window.__realGetFiles = api.getFiles;
      api.getFiles = () => ({padding: {dataURL: 'x'.repeat(24 * 1024 * 1024)}});
      return api.getSceneElements().length;
    });
    try {
      await page.locator('#bookToBoard').click();
      await page.waitForFunction(() => document.getElementById('bookStatusText').textContent.includes('board is full'));
      assert.equal(await page.evaluate(() => lessonViews.current()), 'book');
      assert.equal(await page.evaluate(() => lessonWhiteboard.getApi().getSceneElements().length), before);
    } finally { await page.evaluate(() => { lessonWhiteboard.getApi().getFiles = window.__realGetFiles; }); }
  });
  await check('closing retains memory, next lesson offers Continue without opening over Board', async () => {
    assert.equal(await page.evaluate(() => lessonBookFlush()), true);
    await page.locator('#bookClose').click();
    assert.equal(await page.evaluate(() => lessonViews.current()), 'board');
    const next = await boot(tutor, '&lesson_id=101');
    await next.locator('#bookContinue').waitFor();
    assert.match(await next.locator('#bookContinue').innerText(), /page 2$/);
    assert.equal(await next.evaluate(() => lessonViews.current()), 'board');
    assert.ok(await next.evaluate(() => lessonWhiteboard.getApi().getSceneElements().some(e => e.customData?.origin === 'library' && e.customData?.materialId === 10 && e.customData?.page === 2)));
    await next.locator('#bookContinue').click(); await ready(next, 10, 2);
    await next.close();
  });
  await check('missing file gets an explicit unavailable state', async () => {
    await open(page, 13);
    await page.waitForFunction(() => document.getElementById('bookStatusText').textContent === "This book's file is unavailable");
    assert.equal(await page.locator('#bookCanvas').isVisible(), false);
    assert.equal(await page.locator('#bookToBoard').isDisabled(), true);
  });
  await check('network loss gives Retry; retry restores the same book', async () => {
    await page.route('**/lesson_books.php?*open=11', route => route.abort('internetdisconnected'));
    await open(page, 11);
    await page.waitForFunction(() => document.getElementById('bookStatusText').textContent.startsWith('Network lost'));
    await page.unroute('**/lesson_books.php?*open=11');
    await page.locator('#bookRetry').click(); await ready(page, 11, 1);
  });
  await check('expired signed document is reopened once with a fresh URL at the same page', async () => {
    await page.evaluate(() => {
      const original = pdfjsLib.getDocument;
      let injected = false;
      pdfjsLib.getDocument = function (options) {
        const task = original(options), originalPromise = task.promise;
        // Use a delegating task, since pdf.js exposes promise as a getter.
        return { destroy: () => task.destroy(), promise: originalPromise.then(pdf => {
          const getPage = pdf.getPage.bind(pdf);
          pdf.getPage = async function (number) {
            if (number === 3 && !injected) { injected = true; const e = new Error('Expired range'); e.status = 403; throw e; }
            return getPage(number);
          };
          return pdf;
        }) };
      };
    });
    await open(page, 11); await ready(page, 11, 1);
    await page.locator('#bookJump').fill('3'); await page.locator('#bookGo').click(); await ready(page, 11, 3);
  });
  await check('375 px layout contains long titles and zoom inside the page scroller in both themes', async () => {
    const phone = await boot(tutor, '', { width:375,height:812 });
    await open(phone, 10); await ready(phone, 10, 1);
    assert.equal(await phone.locator('#bookThumbnails').isVisible(), true);
    assert.equal(await phone.locator('.side-tiles').isVisible(), false);
    await phone.locator('#camToggle').click(); assert.equal(await phone.locator('.side-tiles').isVisible(), true);
    await phone.locator('#camToggle').click();
    for (const light of [false,true]) {
      await phone.evaluate(light => document.body.classList.toggle('theme-light', light), light);
      await phone.locator('#bookZoomIn').click(); await ready(phone, 10, 1);
      assert.ok(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'phone document must not overflow');
      await phone.screenshot({path: 'books-phone-' + (light ? 'light' : 'dark') + '.png'});
    }
    await phone.locator('#btnBooks').click(); assert.ok(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await phone.close();
  });
  const follower = await boot(student, '&sync=1');
  let rev = 100;
  const state = (page, browse = false, materialId = 10) => ({t:'state',materialId,page,zoom:1,browse,rev:++rev});
  await check('student asks for state and ignores student-sent control packets', async () => {
    assert.equal(await follower.locator('#btnBooks').count(), 0);
    assert.ok(await follower.evaluate(() => __bookPackets.some(p => p.t === 'req')));
    await packet(follower, state(1), 'student-2');
    assert.equal(await follower.evaluate(() => lessonBooks.getState()), null);
    await packet(follower, state(2)); await ready(follower, 10, 2);
    assert.equal(await follower.locator('#bookNext').isDisabled(), true);
    assert.equal(await follower.locator('#bookToBoard').isVisible(), false);
  });
  await check('student follows tutor page/zoom, including packets received during book load', async () => {
    await packet(follower, {...state(3),zoom:1.25}); await ready(follower, 10, 3);
    assert.equal(await follower.locator('#bookZoomLabel').innerText(), '125%');
    assert.equal(await follower.locator('#bookFollowing').isVisible(), true);
  });
  await check('student always follows, even when an older tutor sends browse permission', async () => {
    await packet(follower, state(3,true)); await ready(follower, 10, 3);
    assert.equal(await follower.locator('#bookPrev').isDisabled(),true);
    assert.equal(await follower.locator('#bookBack').count(),0);
    await packet(follower, state(4,true));
    await ready(follower, 10, 4);
    assert.equal(await follower.locator('#bookFollowing').isVisible(), true);
  });
  await check('page and book switches follow immediately without a student opt-out', async () => {
    await packet(follower, state(1,false)); await ready(follower, 10, 1);
    await packet(follower, state(3,true,11)); await ready(follower, 11, 3);
    assert.equal(await follower.locator('#bookFollowing').isVisible(), true);
  });
  await check('student navigation cannot leave the tutor view', async () => {
    assert.equal(await follower.locator('#bookToBoard').isVisible(),false);
    assert.equal(await follower.locator('#view-board').isDisabled(),true);
    await follower.evaluate(() => lessonViews.select('board'));
    assert.equal(await follower.evaluate(() => lessonViews.current()),'book');
  });
  await check('tutor screen selection waits for the track, then follows switches and close', async () => {
    await packet(follower, {...state(4,false,11),view:'screen'});
    assert.equal(await follower.evaluate(() => lessonViews.current()), 'board');
    await follower.evaluate(() => lessonViews.available('screen',true,true));
    assert.equal(await follower.evaluate(() => lessonViews.current()), 'screen');
    await packet(follower, {...state(4,false,11),view:'book'}); await ready(follower, 11, 4);
    await packet(follower, {t:'close',rev:++rev}, 'student-2'); assert.equal(await follower.locator('#bookPane').isVisible(), true);
    await packet(follower, {t:'close',rev:++rev,view:'screen'}); assert.equal(await follower.locator('#view-book').isVisible(), false);
    assert.equal(await follower.locator('#view-screen').isVisible(), true);
    assert.equal(await follower.evaluate(() => lessonViews.current()), 'screen');
  });
  await check('two connected lesson clients follow tutor tabs, page imports, board pan/zoom and book scroll', async () => {
    const host = await boot(tutor, '&sync=1');
    const peer = await boot(student, '&sync=1', {width:1000,height:750});
    const relayErrors = [];
    let active = true;
    async function relay(target, bytes, topic, identity) {
      if (!active) return;
      try {
        await target.evaluate(({bytes,topic,identity}) => __emit(LivekitClient.RoomEvent.DataReceived,
          new Uint8Array(bytes), {identity}, null, topic), {bytes,topic,identity});
      } catch (error) { if (active) relayErrors.push(error.message); }
    }
    await host.exposeBinding('__relay', (_, bytes, topic) => relay(peer, bytes, topic, 'host-1'));
    await peer.exposeBinding('__relay', (_, bytes, topic) => relay(host, bytes, topic, 'student-2'));
    try {
      await open(host, 10); await ready(host,10,1); await ready(peer,10,1);
      await host.locator('#bookNext').click(); await ready(peer,10,2);
      await host.locator('#view-board').click();
      await peer.waitForFunction(() => lessonViews.current() === 'board');
      await host.locator('#view-book').click();
      await peer.waitForFunction(() => lessonViews.current() === 'book');
      await host.locator('#bookToBoard').click();
      await peer.waitForFunction(() => lessonViews.current() === 'board' && lessonWhiteboard.getApi().getSceneElements()
        .some(e => e.type === 'image' && e.customData?.materialId === 10 && e.customData?.page === 2));
      await host.evaluate(() => lessonWhiteboard.getApi().updateScene({appState:{scrollX:-350,scrollY:-2000,zoom:{value:1.5}}}));
      const pose = await host.evaluate(() => {
        const a = lessonWhiteboard.getApi().getAppState();
        return {x:a.width/(2*a.zoom.value)-a.scrollX,y:a.height/(2*a.zoom.value)-a.scrollY,zoom:a.zoom.value};
      });
      await peer.waitForFunction(p => {
        const a = lessonWhiteboard.getApi().getAppState();
        return Math.abs(a.width/(2*a.zoom.value)-a.scrollX-p.x)<1 && Math.abs(a.height/(2*a.zoom.value)-a.scrollY-p.y)<1 && a.zoom.value===p.zoom;
      },pose);
      await host.locator('#view-book').click(); await ready(host,10,2);
      await host.locator('#bookZoomIn').click(); await ready(host,10,2,1.25); await ready(peer,10,2,1.25);
      await host.evaluate(() => {const v=document.getElementById('bookViewport');v.scrollTop=(v.scrollHeight-v.clientHeight)*0.7;});
      await peer.waitForFunction(() => {const v=document.getElementById('bookViewport');return Math.abs(v.scrollTop/(v.scrollHeight-v.clientHeight)-0.7)<0.02;});
      // A later PDF load must not bring the student back to Book after the tutor moved on.
      await peer.route('**/lesson_books.php?*open=11', async route => {await new Promise(r=>setTimeout(r,400));await route.continue();});
      await open(host,11);
      await host.locator('#view-board').click();
      await peer.waitForFunction(() => lessonViews.current()==='board' && lessonBooks.getState()?.materialId===11);
      await new Promise(r=>setTimeout(r,700));
      assert.equal(await peer.evaluate(() => lessonViews.current()),'board');
      // Joining/reconnecting while the tutor is on Board must restore Board, even with an open PDF.
      await peer.evaluate(() => __emit(LivekitClient.RoomEvent.Reconnected));
      await new Promise(r=>setTimeout(r,250));
      assert.equal(await peer.evaluate(() => lessonViews.current()),'board');
      assert.deepEqual(relayErrors,[]);
    } finally {active=false;await host.close();await peer.close();}
  });
  await check('real room handlers preview local and remote screen shares with tutor priority and clean fallback', async () => {
    for (const isHost of [true,false]) {
      const screenPage = await boot(isHost ? tutor : student);
      await screenPage.route('**/test-room-token', route => route.fulfill({json:{success:true,data:{
        peerName:'Peer',displayName:'Self',hostIdentity:'host-1',url:'test',token:'test'
      }}}));
      const results = await screenPage.evaluate(async isHost => {
        const LK = window.LivekitClient;
        class Room {
          constructor() {
            this.handlers={};this.remoteParticipants=new Map();
            this.localParticipant={isCameraEnabled:false,isMicrophoneEnabled:false,isScreenShareEnabled:false,
              enableCameraAndMicrophone:async()=>{},publishData:async()=>{}};
          }
          on(event,fn){(this.handlers[event] ||= []).push(fn);return this;}
          emit(event,...args){(this.handlers[event] || []).forEach(fn=>fn(...args));}
          async connect(){} disconnect(){}
        }
        window.LivekitClient=Object.assign({},LK,{Room});
        const room=await __realInitLessonRoom({tokenUrl:'test-room-token',leaveUrl:'dashboard.php',isHost});
        if (!isHost) lessonViews.follow('screen');
        const screen=document.getElementById('remoteScreen');
        const track=name=>({kind:LK.Track.Kind.Video,source:LK.Track.Source.ScreenShare,
          attach:el=>{el.dataset.source=name;},detach:()=>{if(screen.dataset.source===name)delete screen.dataset.source;}});
        const local=track('local'),remote=track('remote'),out=[];
        room.emit(LK.RoomEvent.LocalTrackPublished,{source:local.source,track:local});
        out.push([lessonViews.current(),screen.dataset.source]);
        room.emit(LK.RoomEvent.TrackSubscribed,remote);
        out.push([lessonViews.current(),screen.dataset.source]);
        room.emit(LK.RoomEvent.LocalTrackUnpublished,{source:local.source,track:local});
        out.push([lessonViews.current(),screen.dataset.source]);
        room.emit(LK.RoomEvent.TrackUnsubscribed,remote);
        out.push([lessonViews.current(),screen.dataset.source || null]);
        return out;
      },isHost);
      assert.deepEqual(results,[['screen','local'],['screen',isHost?'local':'remote'],['screen','remote'],['board',null]]);
      await screenPage.close();
    }
  });
  await check('recorder composites book canvas, then whiteboard when Board is selected', async () => {
    await open(page, 11); await ready(page, 11, 1);
    await page.route('**/test-recording-upload', route => route.fulfill({status:200,contentType:'application/json',body:'{"success":true}'}));
    await page.evaluate(() => {
      const draw = CanvasRenderingContext2D.prototype.drawImage;
      window.__recordedSources = [];
      CanvasRenderingContext2D.prototype.drawImage = function (source, ...args) {
        if (this.canvas.width === 1280 || this.canvas.width === 960) __recordedSources.push(source.id || source.closest?.('#whiteboard')?.id || 'offscreen');
        return draw.call(this,source,...args);
      };
      initRecorder({localParticipant:{getTrackPublication:()=>null},remoteParticipants:new Map(),on:()=>{}},
        {lessonId:103,uploadUrl:'test-recording-upload',indicator:document.getElementById('recIndicator')});
    });
    await page.waitForFunction(() => __recordedSources.includes('bookCanvas'));
    await page.locator('#view-board').click();
    await page.waitForFunction(() => __recordedSources.includes('whiteboard'));
    await page.evaluate(() => lessonRecorderFinish());
  });
  await check('new text and controls meet AA contrast in both themes', async () => {
    const ratios = await page.evaluate(() => {
      function lum(rgb) { return rgb.slice(0,3).map(x => { x/=255; return x<=.04045 ? x/12.92 : ((x+.055)/1.055)**2.4; }).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0); }
      function parse(color) { return color.match(/[\d.]+/g).map(Number); }
      const ratios = [];
      for (const light of [false,true]) {
        document.body.classList.toggle('theme-light',light);
        for (const selector of ['#bookTitle','#bookFollowing','.book-btn.book-primary','#bookStatusText','#bookZoomLabel']) {
          const el=document.querySelector(selector), fg=parse(getComputedStyle(el).color);
          let parent=el,bg;
          do { bg=parse(getComputedStyle(parent).backgroundColor); parent=parent.parentElement; } while (bg[3] === 0 && parent);
          const a=lum(fg),b=lum(bg); ratios.push((Math.max(a,b)+.05)/(Math.min(a,b)+.05));
        }
      }
      return ratios;
    });
    assert.ok(ratios.every(r => r>=4.5), 'ratios=' + ratios.join(','));
  });
  await check('fresh-tab browser consoles are clean and the room loads no CDN resources', async () => {
    assert.deepEqual(errors, []); assert.deepEqual(externalRequests, []);
  });
} catch (error) {
  console.log('Browser errors:', JSON.stringify(errors));
  for (const [index, context] of browser.contexts().entries()) {
    for (const [tab, page] of context.pages().entries()) {
      console.log('Failure state:', await page.evaluate(() => ({
        state: window.lessonBooks?.getState(), status: document.getElementById('bookStatusText')?.textContent,
        view: window.lessonViews?.current(), images: window.lessonWhiteboard?.getApi()?.getSceneElements().filter(e => e.type === 'image').length,
      })).catch(() => ({})));
      await page.screenshot({path:`books-failure-${index}-${tab}.png`}).catch(() => {});
    }
  }
  throw error;
} finally {
  await browser.close();
}
console.log(`Book browser checks: ${passed} passed`);
