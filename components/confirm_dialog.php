<?php
/**
 * components/confirm_dialog.php
 *
 * An in-app confirmation dialog, used instead of the browser's confirm() popup.
 * Include once per page, anywhere inside <body>.
 *
 * Two ways to use it:
 *   1. Declaratively, on a submit button (or a form):
 *        <button type="submit" name="action" value="approve"
 *                data-confirm="This report goes to the parent straight away."
 *                data-confirm-title="Approve this report?"
 *                data-confirm-ok="Approve and send">Approve</button>
 *      The click is held, the dialog opens, and on OK the form is submitted with
 *      the same submitter (so name/value still reach the server).
 *   2. From script:  if (await bloomConfirm({title, message, ok, cancel, danger})) { ... }
 *
 * Styling follows the page theme: `html.dark` (student and tutor pages) or
 * $confirm_force_dark = true before including (admin, which is dark only).
 * Text is set with textContent, so messages can never inject markup.
 */
$bcDark = !empty($confirm_force_dark);
?>
<style>
  .bc-dialog { border: 0; padding: 0; border-radius: 16px; width: min(440px, calc(100vw - 32px));
    background: #ffffff; color: #1e293b; box-shadow: 0 24px 60px rgba(15, 23, 42, .28); }
  .bc-dialog::backdrop { background: rgba(15, 23, 42, .55); }
  .bc-body { padding: 24px 24px 8px; }
  .bc-title { font-size: 18px; font-weight: 700; line-height: 1.35; margin: 0 0 8px; }
  .bc-msg { font-size: 15px; line-height: 1.6; color: #475569; margin: 0; white-space: pre-line; }
  .bc-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 18px 24px 22px; flex-wrap: wrap; }
  .bc-btn { font: inherit; font-size: 14px; font-weight: 600; padding: 10px 18px; border-radius: 10px; cursor: pointer; border: 1px solid transparent; }
  .bc-cancel { background: transparent; border-color: #cbd5e1; color: #334155; }
  .bc-cancel:hover { background: #f1f5f9; }
  .bc-ok { background: #4f46e5; color: #fff; }
  .bc-ok:hover { background: #4338ca; }
  .bc-ok.is-danger { background: #dc2626; }
  .bc-ok.is-danger:hover { background: #b91c1c; }
  .bc-btn:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; }
  html.dark .bc-dialog, .bc-force-dark.bc-dialog { background: #1f2937; color: #f3f4f6; }
  html.dark .bc-msg, .bc-force-dark .bc-msg { color: #cbd5e1; }
  html.dark .bc-cancel, .bc-force-dark .bc-cancel { border-color: #4b5563; color: #e5e7eb; }
  html.dark .bc-cancel:hover, .bc-force-dark .bc-cancel:hover { background: #374151; }
  @media (prefers-reduced-motion: no-preference) {
    .bc-dialog[open] { animation: bc-in .14s ease-out; }
    @keyframes bc-in { from { opacity: 0; transform: translateY(6px) scale(.98); } to { opacity: 1; transform: none; } }
  }
</style>
<dialog class="bc-dialog<?= $bcDark ? ' bc-force-dark' : '' ?>" id="bc-dialog" aria-labelledby="bc-title" aria-describedby="bc-msg">
  <div class="bc-body">
    <h2 class="bc-title" id="bc-title"></h2>
    <p class="bc-msg" id="bc-msg"></p>
  </div>
  <div class="bc-actions">
    <button type="button" class="bc-btn bc-cancel" id="bc-cancel"></button>
    <button type="button" class="bc-btn bc-ok" id="bc-ok"></button>
  </div>
</dialog>
<script>
(function () {
  if (window.bloomConfirm) return;
  const dlg = document.getElementById('bc-dialog');
  const els = {
    title: document.getElementById('bc-title'), msg: document.getElementById('bc-msg'),
    ok: document.getElementById('bc-ok'), cancel: document.getElementById('bc-cancel'),
  };
  let settle = null;

  function close(result) {
    if (!settle) return;
    const s = settle; settle = null;
    if (dlg.open) dlg.close();
    s(result);
  }
  els.ok.addEventListener('click', () => close(true));
  els.cancel.addEventListener('click', () => close(false));
  dlg.addEventListener('cancel', e => { e.preventDefault(); close(false); });   // Esc
  dlg.addEventListener('click', e => { if (e.target === dlg) close(false); });  // backdrop

  window.bloomConfirm = function (opts) {
    opts = opts || {};
    if (settle) close(false);
    els.title.textContent = opts.title || 'Are you sure?';
    els.msg.textContent = opts.message || '';
    els.msg.hidden = !opts.message;
    els.ok.textContent = opts.ok || 'Confirm';
    els.cancel.textContent = opts.cancel || 'Cancel';
    els.ok.classList.toggle('is-danger', !!opts.danger);
    return new Promise(resolve => {
      settle = resolve;
      dlg.showModal();
      (opts.danger ? els.cancel : els.ok).focus();
    });
  };

  // Declarative use: hold the submit, ask, then submit with the same submitter.
  document.addEventListener('click', async function (e) {
    const btn = e.target.closest('[data-confirm]');
    if (!btn || btn.dataset.bcConfirmed === '1') return;
    const form = btn.form || btn.closest('form');
    if (!form) return;
    e.preventDefault();
    const ok = await window.bloomConfirm({
      title: btn.dataset.confirmTitle, message: btn.dataset.confirm,
      ok: btn.dataset.confirmOk, cancel: btn.dataset.confirmCancel,
      danger: btn.dataset.confirmDanger === '1',
    });
    if (!ok) return;
    btn.dataset.bcConfirmed = '1';
    if (form.requestSubmit) form.requestSubmit(btn); else btn.click();
    setTimeout(() => { delete btn.dataset.bcConfirmed; }, 0);
  });
})();
</script>
