<?php
/**
 * components/ui_page_skeleton.php
 * Include right AFTER <body>. Shows a full-page skeleton instantly (critical inline
 * CSS, so it renders before Tailwind/Chart.js load) and removes it on window 'load'.
 * Also provides the reusable .skeleton shimmer and .btn-spinner utilities used for
 * in-page loading (modals, buttons).
 */
?>
<style>
  #page-skeleton { position:fixed; inset:0; z-index:100000; background:#171717; padding:20px; overflow:hidden; display:flex; gap:20px; }
  html[data-theme="light"] #page-skeleton { background:#f7f7fb; }
  #page-skeleton.hide { opacity:0; visibility:hidden; transition:opacity .35s ease, visibility .35s ease; }
  #page-skeleton .sk { background:rgba(148,163,184,0.14); border-radius:12px; position:relative; overflow:hidden; }
  #page-skeleton .sk::after { content:''; position:absolute; inset:0; transform:translateX(-100%); background:linear-gradient(90deg, transparent 0, rgba(148,163,184,0.22) 50%, transparent 100%); animation:sk-shimmer 1.3s infinite; }
  @keyframes sk-shimmer { 100% { transform:translateX(100%); } }
  #page-skeleton .ps-side { width:240px; flex-shrink:0; display:none; }
  @media (min-width:1024px){ #page-skeleton .ps-side { display:block; } }
  #page-skeleton .ps-main { flex:1; display:flex; flex-direction:column; gap:18px; }
  #page-skeleton .ps-row { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }
  @media (max-width:767px){ #page-skeleton .ps-row { grid-template-columns:1fr; } }

  /* Reusable in-page loading utilities */
  .skeleton { position:relative; overflow:hidden; background:rgba(148,163,184,0.12); border-radius:.5rem; }
  .skeleton::after { content:''; position:absolute; inset:0; transform:translateX(-100%); background:linear-gradient(90deg, transparent 0, rgba(148,163,184,0.18) 50%, transparent 100%); animation:sk-shimmer 1.3s infinite; }
  .btn-spinner { display:inline-block; width:1rem; height:1rem; border:2px solid currentColor; border-top-color:transparent; border-radius:9999px; animation:btn-spin .6s linear infinite; vertical-align:-2px; }
  @keyframes btn-spin { to { transform:rotate(360deg); } }
  button[data-loading="1"], a[data-loading="1"] { opacity:.75; pointer-events:none; }
  .live-updated { animation: live-flash 0.9s ease-out; }
  @keyframes live-flash { 0% { background-color: rgba(99,102,241,0.18); } 100% { background-color: transparent; } }
</style>
<div id="page-skeleton" aria-hidden="true">
  <div class="ps-side"><div class="sk" style="height:100%"></div></div>
  <div class="ps-main">
    <div class="sk" style="height:56px"></div>
    <div class="sk" style="height:200px"></div>
    <div class="ps-row">
      <div class="sk" style="height:120px"></div>
      <div class="sk" style="height:120px"></div>
      <div class="sk" style="height:120px"></div>
    </div>
    <div class="ps-row" style="grid-template-columns:2fr 1fr;">
      <div class="sk" style="height:260px"></div>
      <div class="sk" style="height:260px"></div>
    </div>
  </div>
</div>
<script>
  (function(){
    var sk = document.getElementById('page-skeleton');
    if (!sk) return;
    function done(){ sk.classList.add('hide'); setTimeout(function(){ sk.remove(); }, 400); }
    if (document.readyState === 'complete') { done(); }
    else { window.addEventListener('load', done); setTimeout(done, 4000); }
  })();
</script>
