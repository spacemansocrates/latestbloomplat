<?php
/**
 * Ask for a password reset link.
 *
 * The response is deliberately identical whether or not the address is registered.
 * Telling a stranger which emails have accounts would hand them a list of Bloom's
 * students and tutors.
 */
session_start();

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/utils/password_reset.php';
require_once __DIR__ . '/utils/email_helper.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$sent  = false;
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'That form expired. Please try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter the email address you sign in with.';
    } elseif (!($pdo instanceof PDO)) {
        $error = 'We could not reach the system just now. Please try again shortly.';
    } else {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            $st = $pdo->prepare('SELECT id, first_name, last_name, email, is_active
                                 FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch(PDO::FETCH_ASSOC);

            // Only actually send for a real, active account. Either way the page says
            // the same thing below.
            if ($user && !empty($user['is_active'])
                && !pr_rate_limited($pdo, (int)$user['id'], $ip)) {

                $token = pr_issue_token($pdo, (int)$user['id'], $ip);
                $url   = pr_reset_url($token);
                $name  = trim($user['first_name'] . ' ' . $user['last_name']) ?: 'there';

                $res = send_email(
                    $user['email'], $name,
                    'Reset your Bloom password',
                    pr_build_email_html($name, $url),
                    pr_build_email_plain($name, $url)
                );
                if (empty($res['success'])) {
                    error_log('password reset email failed for user ' . $user['id'] . ': ' . ($res['message'] ?? ''));
                }
            }
            $sent = true;
        } catch (Throwable $e) {
            error_log('forgot_password failed: ' . $e->getMessage());
            $error = 'Something went wrong on our side. Please try again shortly.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Reset password · Bloom</title>
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
    .input{background:#0f172a;border:1px solid #243244;color:#e5e7eb;border-radius:.75rem;padding:.85rem 1rem .85rem 2.5rem;width:100%}
    .input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2)}
    .icon{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);color:#94a3b8;width:18px;height:18px}
    .btn-grad{background-image:linear-gradient(135deg,#7C3AED 0%,#6366F1 55%,#F59E0B 130%);transition:filter .2s ease,transform .02s ease}
    .btn-grad:hover{filter:brightness(1.06)} .btn-grad:active{transform:translateY(1px)}
    .btn-grad[aria-busy="true"]{opacity:.8;cursor:progress}
    @keyframes spin{to{transform:rotate(360deg)}}
    .spin{animation:spin .75s linear infinite}
    @media (prefers-reduced-motion: reduce){ .spin{animation-duration:2.4s} }
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

      <?php if ($sent): ?>
        <header class="mb-4">
          <h2 class="text-2xl font-bold tracking-tight">Check your inbox</h2>
        </header>
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-100 mb-6">
          <p class="mb-2">If <strong><?= h($email) ?></strong> has a Bloom account, a reset link is on its way.</p>
          <p class="text-emerald-200/80">The link works once and expires in <?= PR_TTL_MINUTES ?> minutes.</p>
        </div>
        <p class="text-sm text-slate-400 mb-6">
          Nothing after a few minutes? Check spam, and make sure you used the address you sign in with.
        </p>
        <a href="login.php" class="inline-block text-indigo-300 hover:text-indigo-200 text-sm">&larr; Back to sign in</a>

      <?php else: ?>
        <header class="mb-6">
          <h2 class="text-2xl font-bold tracking-tight">Forgot your password?</h2>
          <p class="text-slate-400 mt-1 text-sm">
            Enter your email and we will send you a link to choose a new one.
          </p>
        </header>

        <?php if ($error): ?>
          <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200">
            <?= h($error) ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php" class="space-y-4" novalidate id="forgotForm">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <div class="relative">
            <svg class="icon" aria-hidden="true"><use href="assets/icons/sprite.svg#i-mail"></use></svg>
            <label for="email" class="sr-only">Email</label>
            <input id="email" name="email" type="email" required autofocus
                   value="<?= h($email) ?>" class="input" placeholder="you@example.com" />
          </div>
          <button type="submit" id="submitBtn"
                  class="btn-grad w-full rounded-xl py-3 font-semibold text-white mt-2 inline-flex items-center justify-center gap-2">
            <svg class="spin hidden" id="submitSpinner" style="width:18px;height:18px" aria-hidden="true"><use href="assets/icons/sprite.svg#i-loader-2"></use></svg>
            <span id="submitLabel">Send reset link</span>
          </button>
        </form>

        <p class="mt-6 text-sm">
          <a href="login.php" class="text-indigo-300 hover:text-indigo-200">&larr; Back to sign in</a>
        </p>
      <?php endif; ?>

    </section>
  </main>

  <script>
    // Show the work happening in the button, and stop a double submit creating two links.
    var f = document.getElementById('forgotForm');
    if (f) {
      f.addEventListener('submit', function () {
        var b = document.getElementById('submitBtn');
        document.getElementById('submitSpinner').classList.remove('hidden');
        document.getElementById('submitLabel').textContent = 'Sending…';
        b.setAttribute('aria-busy', 'true');
        setTimeout(function () { b.disabled = true; }, 0);
      });
    }
  </script>
</body>
</html>
