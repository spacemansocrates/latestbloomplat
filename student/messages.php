<?php
// students/messages.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Student') {
    header('Location: ../login.php'); exit();
} 
$student_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../config/db_connect.php';

// For "New message" modal: assigned tutors + admins
try {
    $qTut = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.profile_photo_url
        FROM users u
        JOIN tutor_student_assignments tsa ON tsa.tutor_id = u.id
        WHERE tsa.student_id = ? 
          AND u.role = 'Tutor'
          AND u.is_active = 1
          AND (tsa.end_date IS NULL OR tsa.end_date >= CURDATE())
        ORDER BY u.first_name, u.last_name
    ");
    $qTut->execute([$student_id]);
    $assigned_tutors = $qTut->fetchAll();

    $qAdm = $pdo->query("
        SELECT id, first_name, last_name, profile_photo_url 
        FROM users 
        WHERE role='Administrator' AND is_active=1 
        ORDER BY first_name, last_name
    ");
    $admins = $qAdm->fetchAll();
} catch (PDOException $e) {
    die('Error loading recipients.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Student – Messages</title>
<link rel="icon" type="image/png" href="../favicon.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<script src="https://cdn.tailwindcss.com"></script>
<?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
<style>
</style>
</head>
<body class="flex min-h-screen">

  <?php include __DIR__ . '/components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
      <?php
      $header_config = [
          'page_title' => 'Messages',
          'page_subtitle' => 'Student Messaging Center',
      ];
      include __DIR__ . '/components/header.php';
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 h-[calc(100vh-10rem)]">
      <!-- Conversations -->
      <section class="glass rounded-lg p-4 flex flex-col">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-xl font-bold">Messages</h2>
          <button id="new-msg-btn" class="btn-primary-action text-sm font-semibold px-3 py-2 rounded-lg">New</button>
        </div>
        <!-- Search with theme integration -->
        <div class="relative mb-3">
          <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
             <svg class="h-5 w-5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
          </div>
          <input id="conv-search" type="text" placeholder="Search" class="form-input pl-10">
        </div>
        <div id="conversations" class="scroll-y grow">
          <!-- JS fills -->
        </div>
      </section>

      <!-- Thread -->
      <section class="lg:col-span-2 glass rounded-lg p-0 flex flex-col min-h-0 overflow-hidden">
        <!-- Header -->
        <div id="thread-header" class="px-5 py-4 border-b flex items-center justify-between">
          <div class="flex items-center gap-3">
            <img id="thread-avatar" class="w-10 h-10 rounded-full object-cover" src="../assets/avatar.php?s=80&bg=7c3aed&fg=ffffff&text=?">
            <div>
              <div id="thread-name" class="font-semibold">Select a conversation</div>
              <div id="thread-role" class="text-xs" ></div>
            </div>
          </div>
          <div id="thread-actions" class="text-sm" ></div>
        </div>

        <!-- Messages area -->
        <div id="thread" class="grow overflow-y-auto p-5 space-y-4">
          <div class="text-center mt-10" >
            Choose a conversation on the left or start a new one.
          </div>
        </div>

        <!-- Composer -->
        <form id="composer" class="hidden p-4 border-t flex items-center gap-3">
          <input type="hidden" id="current-other-id" value="">
          
          <div class="flex-1">
            <textarea id="msg-input" rows="2" placeholder="Write a message" class="form-input resize-none max-h-36 overflow-y-auto"></textarea>
          </div>

          <button type="submit"
            class="btn-primary-action font-semibold px-4 py-2 rounded-lg">
            Send
          </button>
        </form>
      </section>


    </div>
  </main>

  <!-- New message modal -->
  <div id="newModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center p-4 z-50">
    <div class="panel rounded-xl w-full max-w-xl p-6 relative shadow-xl">
      <button class="new-close absolute top-4 right-4 transition hover:opacity-70" >
        <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
      <h3 class="text-xl font-bold mb-4">New Message</h3>
      <form id="new-form" class="space-y-4">
        <div>
          <label class="block text-sm mb-1">Recipient</label>
          <select id="recipient" class="form-input">
            <option value="">– Select –</option>
            <?php if (!empty($assigned_tutors)): ?>
              <optgroup label="Tutors">
                <?php foreach ($assigned_tutors as $t): ?>
                  <option value="<?= (int)$t['id'] ?>">
                    <?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
            <?php if (!empty($admins)): ?>
              <optgroup label="Admins">
                <?php foreach ($admins as $a): ?>
                  <option value="<?= (int)$a['id'] ?>">
                    <?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?> (Admin)
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm mb-1">Message</label>
          <textarea id="new-body" rows="4" class="form-input" required></textarea>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="new-close px-4 py-2 rounded-lg transition border">Cancel</button>
          <button type="submit" class="btn-primary-action font-semibold px-4 py-2 rounded-lg">Send</button>
        </div>
      </form>
    </div>
  </div>

<script>
const me = <?= json_encode($student_id) ?>;
let currentOther = null;
let pollHandle = null;

// Sync theme with dashboard preference
(function(){
  // Default to dark for students
  const saved = localStorage.getItem('theme') || 'dark';
  const isDark = saved === 'dark';
  document.documentElement.classList.toggle('dark', isDark);
  document.body.classList.toggle('theme-light', !isDark);
  document.body.classList.toggle('dark', isDark);
})();

const el = s => document.querySelector(s);
const list = el('#conversations');
const thread = el('#thread');
const composer = el('#composer');
const threadName = el('#thread-name');
const threadRole = el('#thread-role');
const threadAvatar = el('#thread-avatar');

function timePretty(iso) {
  const d = new Date(iso?.replace(' ', 'T'));
  return isNaN(d) ? '' : d.toLocaleString();
}
function dateKey(iso){
  const d = new Date(iso?.replace(' ', 'T'));
  return isNaN(d) ? '' : d.toISOString().slice(0,10);
}
function scrollToBottom() { thread.scrollTop = thread.scrollHeight; }

async function loadConversations() {
  const res = await fetch('api/get_conversations.php');
  const data = await res.json();
  if (!data.success) return;

  const term = (el('#conv-search').value || '').toLowerCase();
  list.innerHTML = '';

  data.items
    .filter(c => c.name.toLowerCase().includes(term))
    .forEach(c => {
      const row = document.createElement('button');
      row.className = 'w-full text-left p-3 transition flex items-center gap-3 conversation-item';
      row.onmouseover = function() { this.style.opacity = '0.8'; };
      row.onmouseout = function() { this.style.opacity = '1'; };
      row.dataset.otherId = c.other_id;

      const avatar = c.profile_photo || `../assets/avatar.php?s=40&bg=7c3aed&fg=ffffff&text=${c.initials}`;
      row.innerHTML = `
        <img src="${avatar}" class="w-10 h-10 object-cover">
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between">
            <div class="font-semibold truncate" >${c.name} ${c.role === 'Administrator' ? '<span class="text-xs ml-1">(Admin)</span>':''}</div>
            <div class="text-xs" >${c.last_time_short}</div>
          </div>
          <div class="text-sm truncate" >${c.last_preview || ''}</div>
        </div>
        ${c.unread > 0 ? `<span class="ml-2 bg-indigo-600 text-white text-xs font-bold px-2 py-1 rounded-full">${c.unread}</span>`:''}
      `;
      row.addEventListener('click', ()=> openThread(c.other_id));
      list.appendChild(row);
    });

  if (!data.items.length) {
    list.innerHTML = '<div class="p-6 text-center" >No conversations yet.</div>';
  }
}

async function openThread(otherId){
  currentOther = otherId;
  const res = await fetch('api/get_messages.php?other_id=' + encodeURIComponent(otherId));
  const data = await res.json();
  if (!data.success) { alert(data.message || 'Failed to load messages'); return; }

  threadName.textContent = data.other.name;
  threadRole.textContent = data.other.role;
  threadAvatar.src = data.other.profile_photo || `../assets/avatar.php?s=80&bg=7c3aed&fg=ffffff&text=${data.other.initials}`;
  composer.classList.remove('hidden');
  el('#current-other-id').value = otherId;

  renderMessages(data.messages);

  fetch('api/mark_read.php?other_id=' + encodeURIComponent(otherId)).then(()=> loadConversations());

  if (pollHandle) clearInterval(pollHandle);
  pollHandle = setInterval(async ()=>{
    if (!currentOther) return;
    const r = await fetch('api/get_messages.php?other_id=' + encodeURIComponent(currentOther));
    const d = await r.json();
    if (d.success) renderMessages(d.messages, true);
  }, 10000);
}

let lastRenderedIds = new Set();
function renderMessages(items, incremental=false){
  if (!incremental) { thread.innerHTML = ''; lastRenderedIds = new Set(); }

  let lastDate = null;
  items.forEach(m=>{
    if (lastRenderedIds.has(m.id)) return;

    const k = dateKey(m.sent_at);
    if (k && k !== lastDate) {
      lastDate = k;
      const sep = document.createElement('div');
      sep.className = 'text-center text-xs text-slate-500 dark:text-slate-400 my-2';
      sep.textContent = new Date(m.sent_at.replace(' ','T')).toLocaleDateString();
      thread.appendChild(sep);
    }

    const mine = (m.sender_id === me);
    const wrap = document.createElement('div');
    wrap.className = `flex ${mine ? 'justify-end' : 'justify-start'}`;

    const bubble = document.createElement('div');
    bubble.className = `${mine ? 'bubble-me' : 'bubble-other'} max-w-[78%] px-4 py-2 rounded-2xl ${mine ? 'rounded-br-md' : 'rounded-bl-md'} shadow`;
    bubble.innerHTML = `
      <div class="whitespace-pre-wrap">${m.content}</div>
      <div class="text-[10px] opacity-70 mt-1">${timePretty(m.sent_at)}</div>
    `;
    wrap.appendChild(bubble);
    thread.appendChild(wrap);

    lastRenderedIds.add(m.id);
  });

  scrollToBottom();
}

el('#conv-search').addEventListener('input', loadConversations);

el('#composer').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const txt = el('#msg-input').value.trim();
  const other = el('#current-other-id').value;
  if (!other || !txt) return;

  const fd = new FormData();
  fd.append('receiver_id', other);
  fd.append('body', txt); // server maps to `content`

  const res = await fetch('api/send_message.php', { method:'POST', body: fd });
  const data = await res.json();
  if (!data.success) { alert(data.message || 'Send failed'); return; }

  el('#msg-input').value = '';
  openThread(other);
  loadConversations();
});

// New message modal
const newModal = document.querySelector('#newModal');
function openNew(){ newModal.classList.remove('hidden'); newModal.classList.add('flex'); }
function closeNew(){ newModal.classList.add('hidden'); }

document.querySelector('#new-msg-btn').addEventListener('click', openNew);
document.querySelectorAll('.new-close').forEach(b=> b.addEventListener('click', closeNew));
window.addEventListener('click', (e)=> { if (e.target === newModal) closeNew(); });

document.querySelector('#new-form').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const receiver = document.querySelector('#recipient').value;
  const body     = document.querySelector('#new-body').value.trim();
  if (!receiver || !body) return;

  const fd = new FormData();
  fd.append('receiver_id', receiver);
  fd.append('body', body); // server maps to `content`

  const res = await fetch('api/send_message.php', { method:'POST', body: fd });
  const data = await res.json();
  if (!data.success) { alert(data.message || 'Send failed'); return; }

  closeNew();
  document.querySelector('#new-body').value = ''; 
  document.querySelector('#recipient').value = '';
  loadConversations();
  openThread(receiver);
});

loadConversations();
</script>
</body>
</html>



