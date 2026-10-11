<?php
// privacy.php — Privacy Policy (public)
$brand = 'Bloom';
$support_email = 'contact@bloomeducationonline.com';
$last_updated = 'September 11, 2025'; // update when you make changes
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Privacy Policy — <?= htmlspecialchars($brand) ?></title>
  <link rel="icon" type="image/png" href="favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;background:#0f172a;color:#e5e7eb}
    .prose h2{font-weight:800;color:#fff;margin-top:2.25rem;font-size:1.25rem}
    .prose h3{font-weight:700;color:#fff;margin-top:1.5rem}
    .prose p,.prose li{color:#cbd5e1}
    .card{background:linear-gradient(135deg,rgba(16,185,129,.10),rgba(99,102,241,.08));border:1px solid rgba(255,255,255,.06)}
    a.link{color:#86efac}
    a.link:hover{color:#bbf7d0}
    code{background:#0b1220;border:1px solid #1f2937;padding:.1rem .35rem;border-radius:.35rem}
  </style>
</head>
<body>
  <!-- Header -->
  <header class="border-b border-white/10">
    <div class="max-w-5xl mx-auto px-6 py-5 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="p-2.5 rounded-xl bg-gray-800 ring-1 ring-white/10 shadow-sm">
          <img src="logo.jpg" alt="<?= htmlspecialchars($brand) ?>" class="h-10 md:h-12 w-auto object-contain rounded-md shrink-0">
        </div>
        <div>
          <h1 class="text-xl md:text-2xl font-extrabold tracking-tight text-white"><?= htmlspecialchars($brand) ?></h1>
          <p class="text-xs md:text-sm text-slate-400">Privacy Policy</p>
        </div>
      </div>
      <nav class="hidden sm:flex items-center gap-4 text-sm">
        <a class="link" href="index.php">Home</a>
        <a class="link" href="terms.php">Terms</a>
        <a class="link" href="login.php">Login</a>
      </nav>
    </div>
  </header>

  <!-- Hero -->
  <section class="max-w-5xl mx-auto px-6 pt-8">
    <div class="card rounded-2xl p-6 md:p-8">
      <h2 class="text-2xl md:text-3xl font-extrabold text-white">Privacy Policy</h2>
      <p class="mt-2 text-slate-300">Last updated: <?= htmlspecialchars($last_updated) ?></p>
      <p class="mt-4 text-slate-300 max-w-3xl">
        This Privacy Policy explains how <?= htmlspecialchars($brand) ?> collects, uses, and shares information when you use our platform,
        including lesson scheduling, messaging, homework, materials, and recordings. By using our Services, you agree to this Policy.
      </p>
    </div>
  </section>

  <!-- Content -->
  <main class="max-w-5xl mx-auto px-6 py-10 grid lg:grid-cols-4 gap-8">
    <!-- TOC -->
    <aside class="lg:col-span-1">
      <div class="sticky top-6 space-y-3">
        <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">On this page</h3>
        <nav class="text-sm space-y-2">
          <?php
          $items = [
            'info'      => '1. Information We Collect',
            'use'       => '2. How We Use Information',
            'legal'     => '3. Legal Bases (EEA/UK)',
            'sharing'   => '4. Sharing & Transfers',
            'cookies'   => '5. Cookies & Similar Tech',
            'security'  => '6. Data Security',
            'retention' => '7. Data Retention',
            'children'  => '8. Children',
            'rights'    => '9. Your Rights',
            'changes'   => '10. Changes to this Policy',
            'contact'   => '11. Contact Us'
          ];
          foreach ($items as $id=>$label) {
            echo '<a class="block link" href="#'.$id.'">'.htmlspecialchars($label).'</a>';
          }
          ?>
        </nav>
      </div>
    </aside>

    <!-- Body -->
    <section class="lg:col-span-3 prose">
      <h2 id="info">1. Information We Collect</h2>
      <ul>
        <li><strong>Account & Profile</strong>: name, email, role (Administrator/Tutor/Student), timezone, currency, profile photo.</li>
        <li><strong>Scheduling & Lessons</strong>: lesson times, tutor/student associations, status, notes, attendance timestamps.</li>
        <li><strong>Homework</strong>: assignments, submissions, attachments, grades, tutor feedback.</li>
        <li><strong>Messaging</strong>: in-platform messages and metadata (timestamps, read state).</li>
        <li><strong>Payments</strong>: amount, status, method (limited transaction metadata; payment processors handle card data).</li>
        <li><strong>Recordings</strong>: where enabled, lesson recording URLs and metadata (duration, size, time); video/audio files are stored in our <code>/uploads</code> area or an integrated storage provider.</li>
        <li><strong>Technical</strong>: log data (IP, device, browser), cookies, and similar technologies.</li>
      </ul>

      <h2 id="use">2. How We Use Information</h2>
      <ul>
        <li>Provide and operate scheduling, lessons, homework, and messaging features.</li>
        <li>Detect and resolve issues; ensure safety and quality (including review of recordings or messages where appropriate).</li>
        <li>Send notifications (e.g., new messages, homework, schedule changes, package/payment reminders).</li>
        <li>Improve the Services, develop new features, and conduct analytics (in aggregate or de-identified form where possible).</li>
        <li>Comply with legal obligations and enforce our Terms.</li>
      </ul>

      <h2 id="legal">3. Legal Bases (EEA/UK)</h2>
      <p>
        Where the GDPR/UK GDPR applies, we rely on: <em>Contract</em> (to deliver the platform), <em>Legitimate Interests</em> (safety,
        support, improvement), <em>Consent</em> (e.g., certain cookies or optional communications), and <em>Legal Obligation</em> (record-keeping).
      </p>

      <h2 id="sharing">4. Sharing &amp; International Transfers</h2>
      <ul>
        <li><strong>Service Providers</strong>: hosting, storage, communications, analytics, and payment processors.</li>
        <li><strong>Administrators</strong>: user and activity data necessary for running the school/tutoring organization.</li>
        <li><strong>Legal</strong>: when required by law or to protect rights, safety, or property.</li>
      </ul>
      <p>
        Data may be transferred to countries with different data protection laws. Where required, we use appropriate safeguards (e.g., standard
        contractual clauses).
      </p>

      <h2 id="cookies">5. Cookies &amp; Similar Technologies</h2>
      <p>
        We use necessary cookies for authentication and session management, and may use functional or analytics cookies to improve experience.
        You can control cookies via your browser settings; some features may not function without them.
      </p>

      <h2 id="security">6. Data Security</h2>
      <p>
        We implement technical and organizational measures (role-based access, least privilege, encrypted transport, server hardening).
        No system is 100% secure; report concerns to <a class="link" href="mailto:<?= htmlspecialchars($support_email) ?>"><?= htmlspecialchars($support_email) ?></a>.
      </p>

      <h2 id="retention">7. Data Retention</h2>
      <p>
        We retain data for as long as needed to provide the Services and for legitimate business or legal purposes. For example, lesson records,
        homework, and certain logs may be retained for audit/quality assurance. We periodically review and delete or anonymize data no longer required.
      </p>

      <h2 id="children">8. Children</h2>
      <p>
        If local laws require parental consent for minors, the organization should ensure appropriate consent and supervision. If you believe a child
        used the platform without proper consent, contact us.
      </p>

      <h2 id="rights">9. Your Rights</h2>
      <ul>
        <li><strong>Access/Rectification/Deletion</strong> of personal data, subject to legal exceptions.</li>
        <li><strong>Portability &amp; Restriction</strong> where applicable.</li>
        <li><strong>Objection</strong> to processing based on legitimate interests.</li>
        <li><strong>Withdraw Consent</strong> for consent-based processing.</li>
        <li><strong>Region-specific</strong> rights (e.g., EEA/UK/California) may apply.</li>
      </ul>
      <p>To exercise these rights, email <a class="link" href="mailto:<?= htmlspecialchars($support_email) ?>"><?= htmlspecialchars($support_email) ?></a>.</p>

      <h2 id="changes">10. Changes to this Policy</h2>
      <p>
        We may update this Policy from time to time. If changes are material, we will notify you via the app or email. The “Last updated” date reflects the latest revision.
      </p>

      <h2 id="contact">11. Contact Us</h2>
      <p>
        Questions or requests? Contact our privacy team at <a class="link" href="mailto:<?= htmlspecialchars($support_email) ?>"><?= htmlspecialchars($support_email) ?></a>.
      </p>

      <div class="mt-8">
        <a href="terms.php" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold px-4 py-2 rounded-lg">
          View Terms of Service
          <svg class="w-4 h-4" data-lucide="chevron-right" aria-hidden="true"><use href="assets/icons/sprite.svg#i-chevron-right"></use></svg>
        </a>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="border-t border-white/10">
    <div class="max-w-5xl mx-auto px-6 py-8 text-sm text-slate-400">
      © <?= date('Y') ?> <?= htmlspecialchars($brand) ?>. All rights reserved.
    </div>
  </footer>
</body>
</html>
