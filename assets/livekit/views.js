// Shared main-view ownership. Boots before LiveKit and works without a call.
(function () {
  'use strict';
  window.initLessonViews = function () {
    if (window.lessonViews) return window.lessonViews;
    var current = 'board';
    var following = false, tutorView = 'board';
    var available = { board: true, book: false, screen: false };
    var tabs = document.getElementById('viewToggle');
    function select(view) {
      if (following) view = available[tutorView] ? tutorView : 'board';
      if (!available[view]) return;
      var changed = current !== view;
      if (view === 'book' && current !== 'book' && window.matchMedia('(max-width:639px)').matches) {
        var cameras = document.getElementById('camPanel');
        // Keep page text clear on a phone; the existing camera toggle still works.
        if (cameras && !cameras.classList.contains('is-collapsed')) document.getElementById('camToggle').click();
      }
      current = view;
      Object.keys(available).forEach(function (name) {
        var pane = document.getElementById(name + 'Pane');
        var tab = document.getElementById('view-' + name);
        if (pane) pane.classList.toggle('hidden', name !== current);
        if (tab) {
          tab.classList.toggle('hidden', !available[name]);
          tab.setAttribute('aria-selected', String(name === current));
          tab.tabIndex = name === current ? 0 : -1;
          tab.disabled = following;
          if (following) tab.title = 'Following tutor';
        }
      });
      if (tabs) tabs.classList.toggle('hidden', !available.book && !available.screen);
      document.documentElement.dataset.lessonView = current;
      if (changed) window.dispatchEvent(new CustomEvent('lessonviewchange', { detail: current }));
      // A previously hidden Excalidraw needs its real dimensions again.
      requestAnimationFrame(function () { window.dispatchEvent(new Event('resize')); });
    }
    if (tabs) tabs.querySelectorAll('[data-view]').forEach(function (tab) {
      tab.addEventListener('click', function () { select(tab.dataset.view); });
      tab.addEventListener('keydown', function (event) {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault(); event.stopPropagation();
        var names = Object.keys(available).filter(function (name) { return available[name]; });
        var index = names.indexOf(current);
        if (event.key === 'Home') index = 0;
        else if (event.key === 'End') index = names.length - 1;
        else index = (index + (event.key === 'ArrowRight' ? 1 : -1) + names.length) % names.length;
        select(names[index]); document.getElementById('view-' + names[index]).focus();
      });
    });
    window.lessonViews = {
      select: select,
      current: function () { return current; },
      follow: function (view) {
        following = true;
        tutorView = view;
        select(view);
      },
      available: function (view, on, activate) {
        available[view] = !!on;
        select(activate && on ? view : (available[current] ? current : 'board'));
      },
    };
    select('board');
    return window.lessonViews;
  };
})();
