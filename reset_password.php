<?php
/**
 * Choose a new password using a token from the reset email.
 *
 * The token is validated on GET and again on POST, so an expired or already-used link
 * cannot be submitted from a page that was left open. After a successful reset the
 * person is sent to the sign-in page rather than logged in automatically: possession of
 * a mailbox should not by itself create a session.
 */
session_start();

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/utils/password_reset.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$token   = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$error   = '';
$invalid = false;
$reset   = null;

if (!($pdo instanceof PDO)) {
    $invalid = true;
    $error   = 'We could not reach the system just now. Please try again shortly.';
} else {
    $reset = pr_lookup($pdo, $token);
    if (!$reset) {
        $invalid = true;
    }
}

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw      = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'That form expired. Please open the link from your email again.';
    } else {
        $error = pr_password_problem($pw, $confirm);
        if ($error === '') {
            try {
                pr_complete($pdo, $reset, $pw);
                header('Location: login.php?reset=1');
                exit();
            } catch (Throwable $e) {
                error_log('password reset failed for user ' . $reset['user_id'] . ': ' . $e->getMessage());
                $error = 'We could not save that password. Please try again.';
            }
        }
    }
}

$name = $reset ? trim($reset['first_name'] . ' ' . $reset['last_name']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Choose a new password · Bloom</title>
  <link rel="icon" type="image/png" href="favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html,body { height:100%; }
    body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; }
    .bg-grid{
      background-image:
        radial-gradient(1200px 600px at 80% 10%, rgba(124,58,237,.15), rgba(245,158,11,.08) 40%, transparent 60%),
        radial-gradient(800px 400px at 10% 90%, rgba(245,158,11,.12), transparent 55%),
        linear-gradient(to bottom right, rgba(99,102,241,.10), rgba(236,72,153,.06)),
        linear-gradient(#0b1220,#0b1220);
      background-blend-mode: screen, screen, overlay, normal;
    }
    .glass{background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03));border:1px solid rgba(255,255,255,.08);backdrop-filter:blur(10px)}
    .input{background:#0f172a;border:1px solid #243244;color:#e5e7eb;border-radius:.75rem;padding:.85rem 2.75rem .85rem 2.5rem;width:100%}
    .input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2)}
    .icon{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);color:#94a3b8;width:18px;height:18px}
    .btn-grad{background-image:linear-gradient(135deg,#7C3AED 0%,#6366F1 55%,#F59E0B 130%);transition:filter .2s ease,transform .02s ease}
    .btn-grad:hover{filter:brightness(1.06)} .btn-grad:active{transform:translateY(1px)}
    @keyframes spin{to{transform:rotate(360deg)}}
    .spin{animation:spin .75s linear infinite}
    .meter{height:4px;border-radius:999px;background:#243244;overflow:hidden}
    .meter i{display:block;height:100%;width:0;background:#ef4444;transition:width .25s ease,background .25s ease}
    @media (prefers-reduced-motion: reduce){ .spin{animation-duration:2.4s} .meter i{transition:none} }
    a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid #818cf8;outline-offset:2px}
  </style>
</head>
<body class="bg-grid min-h-full text-slate-200">
  <main class="min-h-full flex items-center justify-center p-6">
    <section class="glass rounded-2xl p-8 lg:p-10 w-full max-w-md">

      <div class="flex items-center gap-3 mb-8">
        <div class="p-2.5 rounded-xl bg-gray-800 ring-1 ring-white/10 shadow-sm">
          <img src="logo-white.png" alt="Bloom" class="h-[30px] w-[30px] object-contain select-none" />
        </div>
        <h1 class="text-xl font-extrabold">Bloom</h1>
      </div>

      <?php if ($invalid): ?>
        <header class="mb-4">
          <h2 class="text-2xl font-bold tracking-tight">This link has expired</h2>
        </header>
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-100 mb-6">
          <?php if ($error): ?>
            <?= h($error) ?>
          <?php else: ?>
            Reset links work once and last <?= PR_TTL_MINUTES ?> minutes. This one has been used already,
            has run out, or was never valid.
          <?php endif; ?>
        </div>
        <a href="forgot_password.php"
           class="btn-grad inline-block w-full text-center rounded-xl py-3 font-semibold text-white">
          Send me a new link
        </a>
        <p class="mt-6 text-sm">
          <a href="login.php" class="text-indigo-300 hover:text-indigo-200">&larr; Back to sign in</a>
        </p>

      <?php else: ?>
        <header class="mb-6">
          <h2 class="text-2xl font-bold tracking-tight">Choose a new password</h2>
          <p class="text-slate-400 mt-1 text-sm">
            For <strong class="text-slate-300"><?= h($reset['email']) ?></strong><?= $name ? ' (' . h($name) . ')' : '' ?>.
          </p>
        </header>

        <?php if ($error): ?>
          <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200">
            <?= h($error) ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="reset_password.php" class="space-y-4" novalidate id="resetForm">
          <input type="hidden" name="csrf"  value="<?= h($csrf) ?>" />
          <input type="hidden" name="token" value="<?= h($token) ?>" />

          <div>
            <div class="relative">
              <svg class="icon" aria-hidden="true"><use href="assets/icons/sprite.svg#i-lock"></use></svg>
              <label for="password" class="sr-only">New password</label>
              <input id="password" name="password" type="password" required autofocus
                     class="input" placeholder="New password" autocomplete="new-password" />
              <button type="button" id="togglePwd"
                      class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-200"
                      aria-label="Show password">
                <svg id="eyeOpen"   style="width:18px;height:18px" aria-hidden="true"><use href="assets/icons/sprite.svg#i-eye"></use></svg>
                <svg id="eyeClosed" class="hidden" style="width:18px;height:18px" aria-hidden="true"><use href="assets/icons/sprite.svg#i-eye-off"></use></svg>
              </button>
            </div>
            <div class="meter mt-2"><i id="meterBar"></i></div>
            <p id="pwHint" class="text-xs text-slate-500 mt-1.5">At least <?= PR_MIN_PASSWORD ?> characters.</p>
          </div>

          <div class="relative">
            <svg class="icon" aria-hidden="true"><use href="assets/icons/sprite.svg#i-lock"></use></svg>
            <label for="password_confirm" class="sr-only">Repeat new password</label>
            <input id="password_confirm" name="password_confirm" type="password" required
                   class="input" placeholder="Repeat new password" autocomplete="new-password" />
          </div>

          <button type="submit" id="submitBtn"
                  class="btn-grad w-full rounded-xl py-3 font-semibold text-white mt-2 inline-flex items-center justify-center gap-2">
            <svg class="spin hidden" id="submitSpinner" style="width:18px;height:18px" aria-hidden="true"><use href="assets/icons/sprite.svg#i-loader-2"></use></svg>
            <span id="submitLabel">Save new password</span>
          </button>
        </form>
      <?php endif; ?>

    </section>
  </main>

  <script>
    (function () {
      var pwd = document.getElementById('password');
      if (!pwd) return;

      var toggle = document.getElementById('togglePwd');
      var eyeOpen = document.getElementById('eyeOpen');
      var eyeClosed = document.getElementById('eyeClosed');
      toggle.addEventListener('click', function () {
        var showing = pwd.type === 'text';
        pwd.type = showing ? 'password' : 'text';
        eyeOpen.classList.toggle('hidden', !showing);
        eyeClosed.classList.toggle('hidden', showing);
        toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      });

      // Strength is a hint, not a gate. The server decides what is acceptable.
      var min = <?= PR_MIN_PASSWORD ?>;
      var bar = document.getElementById('meterBar');
      var hint = document.getElementById('pwHint');
      var confirmEl = document.getElementById('password_confirm');

      function score(v) {
        var s = 0;
        if (v.length >= min) s++;
        if (v.length >= min + 4) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
        if (/[0-9]/.test(v)) s++;
        if (/[^A-Za-z0-9]/.test(v)) s++;
        return Math.min(s, 4);
      }
      function paint() {
        var v = pwd.value, s = score(v);
        var pct = v === '' ? 0 : [12, 30, 55, 80, 100][s];
        var col = ['#ef4444', '#f59e0b', '#f59e0b', '#84cc16', '#22c55e'][s];
        bar.style.width = pct + '%';
        bar.style.background = col;
        if (v === '') hint.textContent = 'At least ' + min + ' characters.';
        else if (v.length < min) hint.textContent = 'Too short, ' + (min - v.length) + ' more to go.';
        else if (confirmEl.value && confirmEl.value !== v) hint.textContent = 'The two passwords do not match yet.';
        else hint.textContent = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'][s] + '.';
      }
      pwd.addEventListener('input', paint);
      confirmEl.addEventListener('input', paint);

      document.getElementById('resetForm').addEventListener('submit', function () {
        var b = document.getElementById('submitBtn');
        document.getElementById('submitSpinner').classList.remove('hidden');
        document.getElementById('submitLabel').textContent = 'Saving…';
        b.setAttribute('aria-busy', 'true');
        setTimeout(function () { b.disabled = true; }, 0);
      });
    })();
  </script>
</body>
</html>
