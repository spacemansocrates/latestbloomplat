<?php
/**
 * components/notification_prefs_card.php
 * "How should we remind you?" card, shared by the student and tutor settings pages.
 *
 * The including page sets:
 *   $np_pdo      PDO
 *   $np_user_id  int, whose preferences
 *   $np_phone    their saved phone number (to warn when WhatsApp cannot reach them)
 *   $np_action   form action URL
 *   $np_hidden   [name => value] hidden fields: the page's CSRF token and whatever
 *                field the page routes its POST handlers by
 * and handles the POST itself with np_save_user($pdo, $userId, $_POST['np'] ?? []).
 */

require_once __DIR__ . '/../utils/notification_prefs.php';
require_once __DIR__ . '/../utils/phone.php';
require_once __DIR__ . '/../integrations/twilio_whatsapp.php';

$__np     = np_for_user($np_pdo, (int)$np_user_id)['prefs'];
$__phone  = phone_to_e164($np_phone ?? null);
$__waLive = whatsapp_enabled($np_pdo);
$__e      = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<section id="reminder-preferences" class="glass rounded-xl p-6 mb-6">
  <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Lesson reminders</h3>
  <p class="text-sm text-slate-600 dark:text-slate-300 mt-1">Choose how we remind you about your lessons.</p>

  <?php if (!$__waLive): ?>
    <p class="mt-4 text-sm rounded-lg px-3 py-2 bg-slate-500/10 border border-slate-500/20 text-slate-700 dark:text-slate-300">
      WhatsApp reminders are not switched on for the school yet. Your choice is saved and applies once they are.
    </p>
  <?php elseif (!$__phone['ok']): ?>
    <p class="mt-4 text-sm rounded-lg px-3 py-2 bg-amber-500/10 border border-amber-500/30 text-amber-800 dark:text-amber-300">
      We cannot send you WhatsApp reminders yet: <?= $__e(phone_reason_text($__phone['reason'])) ?>
      Add your mobile number, starting with the country code, on your profile.
    </p>
  <?php endif; ?>

  <form method="post" action="<?= $__e($np_action) ?>" class="mt-5">
    <?php foreach ($np_hidden as $__n => $__v): ?>
      <input type="hidden" name="<?= $__e($__n) ?>" value="<?= $__e($__v) ?>">
    <?php endforeach; ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-slate-600 dark:text-slate-300">
            <th class="py-2 pr-4 font-medium">Reminder</th>
            <?php foreach (NP_CHANNELS as $__label): ?>
              <th class="py-2 px-3 font-medium text-center w-24"><?= $__e($__label) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach (NP_KINDS as $__kind => [$__title, $__desc]): ?>
            <tr class="border-t border-slate-200 dark:border-slate-700">
              <td class="py-3 pr-4">
                <div class="font-medium text-slate-900 dark:text-white"><?= $__e($__title) ?></div>
                <div class="text-xs text-slate-600 dark:text-slate-400"><?= $__e($__desc) ?></div>
              </td>
              <?php foreach (NP_CHANNELS as $__ch => $__label): ?>
                <td class="py-3 px-3 text-center">
                  <input type="checkbox" class="h-5 w-5 accent-indigo-600 cursor-pointer"
                         name="np[<?= $__e($__kind) ?>][<?= $__e($__ch) ?>]" value="1"
                         aria-label="<?= $__e($__label . ': ' . $__title) ?>"
                         <?= !empty($__np[$__kind][$__ch]) ? 'checked' : '' ?>>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="flex justify-end mt-4">
      <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 px-5 rounded-lg">Save reminder choices</button>
    </div>
  </form>
</section>
