<?php
/**
 * components/ui_head_boot.php
 * Include in <head> right AFTER the Tailwind CDN <script>. Three jobs:
 *   1. Set the theme (dark/light) on <html> before first paint so there is no colour
 *      flash and the page-load skeleton (ui_page_skeleton.php) is themed correctly.
 *   2. Mirror it onto <body> as `.theme-light` the moment the parser creates <body>
 *      (css/theme.css keys its rules off body.theme-light / body:not(.theme-light),
 *      and <body> does not exist yet while <head> scripts run).
 *   3. Make Tailwind's dark: utilities follow the app theme toggle (the header adds
 *      `.dark` to <html>) instead of the OS preference.
 */
?>
<script>
  (function(){
    var t = localStorage.getItem('theme') || 'dark';
    document.documentElement.classList.toggle('dark', t === 'dark');
    document.documentElement.setAttribute('data-theme', t);
    new MutationObserver(function(_, obs){
      if (document.body) {
        document.body.classList.toggle('theme-light', t !== 'dark');
        obs.disconnect();
      }
    }).observe(document.documentElement, { childList: true });
  })();
  if (window.tailwind) { tailwind.config = { darkMode: 'class' }; }
</script>

<style>
  /* A modal taller than the window used to be unreachable at both ends: the backdrop
     is a centred flex container, so the panel overflowed equally past the top and the
     bottom and nothing scrolled. The student profile measured 1361px tall sitting at
     top -664px. Letting the backdrop scroll is not enough on its own, because
     centring still pushes the top out of reach; `margin: auto` on the panel is what
     makes it centre when there is room and sit at the top when there is not. */
  .modal-backdrop { overflow-y: auto; }
  .modal-backdrop > * { margin-block: auto; }

  /* .scroll-y is used by the conversation list, the message thread and the new-chat
     student list, and was never defined anywhere, so none of them scrolled: the
     student list simply painted outside its modal, and threadBody's
     scrollTop = scrollHeight (scroll to the newest message) silently did nothing
     because there was no scroll container. min-height:0 is required for the two that
     are flex children, or they refuse to shrink below their content. */
  .scroll-y { overflow-y: auto; min-height: 0; }
</style>
