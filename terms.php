<?php
// terms.php — Terms of Service (public)
$brand = 'Bloom';
$support_email = 'contact@bloomeducationonline.com';
$last_updated = 'September 11, 2025'; // update when you make changes
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Terms of Service — <?= htmlspecialchars($brand) ?></title>
  <link rel="icon" type="image/png" href="favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;background:#0f172a;color:#e5e7eb}
    .prose h2{font-weight:800;color:#fff;margin-top:2.25rem;font-size:1.25rem}
    .prose h3{font-weight:700;color:#fff;margin-top:1.5rem}
    .prose p,.prose li{color:#cbd5e1}
    .card{background:linear-gradient(135deg,rgba(99,102,241,.08),rgba(236,72,153,.07));border:1px solid rgba(255,255,255,.06)}
    a.link{color:#a5b4fc}
    a.link:hover{color:#c7d2fe}
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
          <p class="text-xs md:text-sm text-slate-400">Terms of Service</p>
        </div>
      </div>
      <nav class="hidden sm:flex items-center gap-4 text-sm">
        <a class="link" href="index.php">Home</a>
        <a class="link" href="privacy.php">Privacy</a>
        <a class="link" href="login.php">Login</a>
      </nav>
    </div>
  </header>

  <!-- Hero -->
  <section class="max-w-5xl mx-auto px-6 pt-8">
    <div class="card rounded-2xl p-6 md:p-8">
      <h2 class="text-2xl md:text-3xl font-extrabold text-white">Terms of Service</h2>
      <p class="mt-2 text-slate-300">Last updated: <?= htmlspecialchars($last_updated) ?></p>
      <p class="mt-4 text-slate-300 max-w-3xl">
        These Terms govern your access to and use of the <?= htmlspecialchars($brand) ?> platform, including lesson scheduling,
        messaging, homework management, and content (like lesson recordings and materials). By creating an account or using our services,
        you agree to these Terms.
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
            'acceptance' => '1. Acceptance & Eligibility',
            'accounts'   => '2. Accounts & Security',
            'services'   => '3. Platform Features',
            'payments'   => '4. Payments & Refunds',
            'conduct'    => '5. Acceptable Use',
            'ip'         => '6. Intellectual Property',
            'usercontent'=> '7. User Content',
            'recordings' => '8. Lesson Recordings',
            'termination'=> '9. Suspension & Termination',
            'disclaimer' => '10. Disclaimers',
            'liability'  => '11. Limitation of Liability',
            'indemnity'  => '12. Indemnification',
            'law'        => '13. Governing Law',
            'changes'    => '14. Changes to Terms',
            'contact'    => '15. Contact'
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
      <h2 id="acceptance">1. Acceptance &amp; Eligibility</h2>
      <p>
        You must be able to form a binding contract to use the Services. If you use the Services on behalf of an organization,
        you represent that you have authority to bind that organization to these Terms. If you are under the age required by your jurisdiction,
        a parent/guardian must consent and supervise use.
      </p>

      <h2 id="accounts">2. Accounts &amp; Security</h2>
      <p>
        You are responsible for your account credentials and all activity under your account. Keep your password secure and notify us promptly
        at <a class="link" href="mailto:<?= htmlspecialchars($support_email) ?>"><?= htmlspecialchars($support_email) ?></a> of any suspected compromise.
        We may require additional verification to maintain platform integrity.
      </p>

      <h2 id="services">3. Platform Features</h2>
      <h3>Scheduling & Lessons</h3>
      <p>
        Students may schedule lessons with tutors and join sessions in the lesson room, or via a meeting link where one is provided. Attendance and punctuality are your responsibility.
        Tutors may record attendance and notes; admins may review for quality and support.
      </p>
      <h3>Homework & Materials</h3>
      <p>
        Tutors may assign homework and attach files. Students may submit responses and files. Admins can upload learning materials (e.g., books, past papers, videos, PPTs).
      </p>
      <h3>Messaging</h3>
      <p>
        In-platform messaging enables communication between admins, tutors, and students. For safety, messages may be retained and reviewed in line with our Privacy Policy.
      </p>

      <h2 id="payments">4. Payments &amp; Refunds</h2>
      <p>
        Package purchases and payments are processed via listed methods (e.g., Stripe, PayChangu, Manual). Pricing, currency, and duration are shown at checkout.
        Except where required by law or as stated in a specific package policy, fees are non-refundable once the package term begins.
      </p>

      <h2 id="conduct">5. Acceptable Use</h2>
      <ul>
        <li>No harassment, hate speech, or illegal content.</li>
        <li>No unauthorized recording, scraping, or distribution of course materials.</li>
        <li>No attempts to circumvent access controls or disrupt Services.</li>
        <li>Follow reasonable instructions from tutors and admins related to lessons and safety.</li>
      </ul>

      <h2 id="ip">6. Intellectual Property</h2>
      <p>
        The platform, branding, and original content are owned by <?= htmlspecialchars($brand) ?> and its licensors.
        You receive a limited, non-exclusive license to use the Services for personal educational purposes.
      </p>

      <h2 id="usercontent">7. User Content</h2>
      <p>
        You retain ownership of content you upload (e.g., submissions, messages) and grant <?= htmlspecialchars($brand) ?> a worldwide, non-exclusive license
        to host, process, and display it solely to operate and improve the Services. Do not upload content you don’t have rights to share.
      </p>

      <h2 id="recordings">8. Lesson Recordings</h2>
      <p>
        Some sessions may be recorded where enabled. Recordings are used for quality, safety, and review, and may be made available to the enrolled student.
        Unauthorized sharing, download, or redistribution of recordings is prohibited.
      </p>

      <h2 id="termination">9. Suspension &amp; Termination</h2>
      <p>
        We may suspend or terminate accounts that violate these Terms or applicable law. You may stop using the Services at any time.
        Some obligations (e.g., payment, IP, indemnity) survive termination.
      </p>

      <h2 id="disclaimer">10. Disclaimers</h2>
      <p>
        The Services are provided “as is” and “as available.” To the fullest extent permitted by law, we disclaim all warranties, express or implied,
        including merchantability, fitness for a particular purpose, and non-infringement.
      </p>

      <h2 id="liability">11. Limitation of Liability</h2>
      <p>
        To the fullest extent permitted by law, <?= htmlspecialchars($brand) ?> will not be liable for indirect, incidental, special, consequential, or punitive damages,
        or any loss of profits or revenues, whether incurred directly or indirectly, or any loss of data.
      </p>

      <h2 id="indemnity">12. Indemnification</h2>
      <p>
        You agree to indemnify and hold harmless <?= htmlspecialchars($brand) ?> from any claims, liabilities, damages, and expenses (including reasonable legal fees)
        arising out of your use of the Services or violation of these Terms.
      </p>

      <h2 id="law">13. Governing Law</h2>
      <p>
        These Terms are governed by the laws of Malawi, without regard to conflict of laws.
        Venue for disputes will be in courts of that jurisdiction.
      </p>

      <h2 id="changes">14. Changes to Terms</h2>
      <p>
        We may update these Terms occasionally. Material changes will be communicated by posting an update date, in-app notices, or email.
        Continued use of the Services means you accept the changes.
      </p>

      <h2 id="contact">15. Contact</h2>
      <p>
        Questions? Contact us at <a class="link" href="mailto:<?= htmlspecialchars($support_email) ?>"><?= htmlspecialchars($support_email) ?></a>.
      </p>

      <div class="mt-8">
        <a href="privacy.php" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-4 py-2 rounded-lg">
          View Privacy Policy
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
