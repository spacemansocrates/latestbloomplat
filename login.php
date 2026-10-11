<?php
session_start();

// If the user is already logged in, redirect them based on their role
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    if ($_SESSION['user_role'] === 'Student') {
        header("Location: student/dashboard.php"); exit();
    } elseif ($_SESSION['user_role'] === 'Tutor') {
        header("Location: tutor/dashboard.php"); exit();
    } elseif ($_SESSION['user_role'] === 'Administrator') {
        header("Location: admin/admin_dashboard.php"); exit();
    } elseif ($_SESSION['user_role'] === 'QualityControl') {
        header("Location: qc/dashboard.php"); exit();
    }
}

$error_message = '';
if (!empty($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Sign in · Bloom</title>
  <link rel="icon" type="image/png" href="favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Tailwind + Inter -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html,body { height: 100%; }
    body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; }
    /* Subtle grid + gradient background */
    .bg-grid {
      background-image:
        radial-gradient(1200px 600px at 80% 10%, rgba(124,58,237,.15), rgba(245,158,11,.08) 40%, transparent 60%),
        radial-gradient(800px 400px at 10% 90%, rgba(245,158,11,.12), transparent 55%),
        linear-gradient(to bottom right, rgba(99,102,241,.10), rgba(236,72,153,.06)),
        linear-gradient(#0b1220, #0b1220);
      background-blend-mode: screen, screen, overlay, normal;
    }
    .glass {
      background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
      border: 1px solid rgba(255,255,255,.08);
      backdrop-filter: blur(10px);
    }
    .input {
      background: #0f172a;
      border: 1px solid #243244;
      color: #e5e7eb;
      border-radius: .75rem;
      padding: .85rem 1rem .85rem 2.5rem;
      width: 100%;
    }
    .input:focus {
      outline: none;
      border-color: #6366f1;
      box-shadow: 0 0 0 3px rgba(99,102,241,.2);
    }
    .icon {
      position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
      color: #94a3b8;
    }
    .btn-grad {
      background-image: linear-gradient(135deg, #7C3AED 0%, #6366F1 55%, #F59E0B 130%);
      transition: filter .2s ease, transform .02s ease;
    }
    .btn-grad:hover { filter: brightness(1.06); }
    .btn-grad:active { transform: translateY(1px); }
  </style>
</head>
<body class="bg-grid min-h-full text-slate-200">
  <main class="min-h-full flex items-center justify-center p-6">
    <div class="w-full max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-8">
      <!-- Brand / Hero -->
      <section class="glass rounded-2xl p-10 flex items-center justify-center">
        <div class="max-w-md">
          <div class="flex items-center gap-3 mb-6">
            <div class="p-3 rounded-xl bg-gray-800 ring-1 ring-white/10 shadow-sm">
              <img src="logo-white.png"
                   alt="Bloom"
                   class="h-20 md:h-24 w-auto object-contain rounded-md shrink-0" />
            </div>
            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight">Bloom</h1>
          </div>
          <h2 class="text-3xl font-extrabold leading-tight">Welcome back.</h2>
          <p class="mt-3 text-slate-400">
            Sign in to access lessons, materials, homework, and reports—all in one elegant place.
          </p>

          <div class="mt-8 grid grid-cols-3 gap-3 text-xs text-slate-400">
            <div class="bg-gray-900/40 rounded-xl p-3 border border-white/5">
              <p class="font-semibold text-white mb-1">Live Lessons</p>
              <p>Join your lesson in one click.</p>
            </div>
            <div class="bg-gray-900/40 rounded-xl p-3 border border-white/5">
              <p class="font-semibold text-white mb-1">Smart Homework</p>
              <p>Upload, grade & track progress.</p>
            </div>
            <div class="bg-gray-900/40 rounded-xl p-3 border border-white/5">
              <p class="font-semibold text-white mb-1">Resources</p>
              <p>Curated books, papers & videos.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- Auth Card -->
      <section class="glass rounded-2xl p-8 lg:p-10">
        <header class="mb-6">
          <h2 class="text-2xl font-bold tracking-tight">Sign in</h2>
          <p class="text-slate-400 mt-1">Use your Bloom email and password to continue.</p>
        </header>

        <?php if (isset($_GET['reset'])): ?>
          <div class="mb-5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200 flex items-center gap-2">
            <svg style="width:16px;height:16px;flex:none" aria-hidden="true"><use href="assets/icons/sprite.svg#i-check-circle"></use></svg>
            <span>Password updated. Sign in with your new one.</span>
          </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
          <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200">
            <?= h($error_message) ?>
          </div>
        <?php endif; ?>

        <form action="authenticate.php" method="POST" class="space-y-4" autocomplete="on" novalidate>
          <!-- Email -->
          <div class="relative">
            <span class="icon">
              <svg width="18" height="18" aria-hidden="true"><use href="assets/icons/sprite.svg#i-mail"></use></svg>
            </span>
            <label for="email" class="sr-only">Email</label>
            <input id="email" name="email" type="email" required class="input" placeholder="you@example.com" />
          </div>

          <!-- Password -->
          <div class="relative">
            <span class="icon">
              <svg width="18" height="18" aria-hidden="true"><use href="assets/icons/sprite.svg#i-lock"></use></svg>
            </span>
            <label for="password" class="sr-only">Password</label>
            <input id="password" name="password" type="password" required class="input pr-12" placeholder="••••••••" />
            <button type="button" id="togglePwd"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-200"
                    aria-label="Show password">
              <svg id="eyeOpen" width="18" height="18" aria-hidden="true"><use href="assets/icons/sprite.svg#i-eye"></use></svg>
              <svg id="eyeClosed" class="hidden" width="18" height="18" aria-hidden="true"><use href="assets/icons/sprite.svg#i-eye-off"></use></svg>
            </button>
          </div>

          <!-- Actions -->
          <div class="flex items-center justify-between text-sm">
            <label class="inline-flex items-center gap-2 select-none cursor-pointer">
              <input type="checkbox" name="remember" class="h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-500 focus:ring-indigo-500" />
              <span class="text-slate-300">Remember me</span>
            </label>
            <a href="forgot_password.php" class="text-indigo-300 hover:text-indigo-200">Forgot password?</a>
          </div>

          <button class="btn-grad w-full rounded-xl py-3 font-semibold text-white mt-2">
            Sign in
          </button>
        </form>

        <p class="mt-6 text-xs text-slate-500">
          By signing in you agree to our <a href="terms.php" class="text-slate-300 hover:text-white underline">Terms</a> and
          <a href="privacy.php" class="text-slate-300 hover:text-white underline">Privacy Policy</a>.
        </p>
      </section>
    </div>
  </main>

  <script>
    // Autofocus email
    const email = document.getElementById('email');
    if (email) { setTimeout(() => email.focus(), 100); }

    // Show / hide password
    const pwd = document.getElementById('password');
    const toggle = document.getElementById('togglePwd');
    const eyeOpen   = document.getElementById('eyeOpen');
    const eyeClosed = document.getElementById('eyeClosed');
    toggle?.addEventListener('click', () => {
      const showing = pwd.type === 'text';
      pwd.type = showing ? 'password' : 'text';
      eyeOpen.classList.toggle('hidden', !showing);
      eyeClosed.classList.toggle('hidden', showing);
      toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
  </script>
</body>
</html>