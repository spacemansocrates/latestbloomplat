<?php
require_once 'auth_tutor_only.php';
$current_page = 'messages';

// DB Connection
require_once __DIR__ . '/../config/db_connect.php';

$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.profile_photo_url
    FROM users u
    JOIN tutor_student_assignments tsa ON tsa.student_id = u.id
    WHERE tsa.tutor_id = ? AND (tsa.end_date IS NULL OR tsa.end_date >= CURDATE()) AND u.is_active = 1 AND u.role = 'Student'
    ORDER BY u.first_name, u.last_name
");
$stmt->execute([$tutor_id]);
$assigned_students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Tutor – Messages</title>
<link rel="icon" type="image/png" href="../favicon.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<script src="https://cdn.tailwindcss.com"></script>
<?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
<style>
</style>
</head>
<body class="flex min-h-screen">
  <?php 
  // Include centralized page loader component (if not already included by sidebar)
  if (!defined('PAGE_LOADER_INCLUDED')) {
    define('PAGE_LOADER_INCLUDED', true);
    include __DIR__ . '/../components/page_loader.php';
  }
  ?>

  <?php include 'components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
    <?php 
        $header_title = "Messages";
        $header_subtitle = "Chat with your students. You’ll get notifications for new messages.";
        include 'components/header.php';
    ?>
    
    <div class="flex justify-end mb-4">
      <button id="btnNewChat" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-4 py-2.5 rounded-lg whitespace-nowrap">
        New Chat
      </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 h-[72vh]">
      <!-- Conversations -->
      <aside class="card-base rounded-xl p-0 flex flex-col overflow-hidden">
        <div class="p-4 border-b border-slate-200 dark:border-slate-700">
          <input id="convSearch" class="input-field" placeholder="Search conversations..." />
        </div>
        <div id="conversations" class="scroll-y pr-1" style="min-height:0; flex:1;">
          <!-- Filled by JS -->
        </div>
      </aside>

      <!-- Thread -->
      <section class="lg:col-span-2 card-base rounded-xl flex flex-col overflow-hidden p-0">
        <!-- Thread header -->
        <div id="threadHeader" class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div id="threadAvatar" class="w-10 h-10 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold"></div>
            <div>
              <div id="threadName" class="font-semibold">Select a conversation</div>
              <div id="threadMeta" class="text-xs text-slate-500 dark:text-slate-400"></div>
            </div>
          </div>
          <div id="threadActions" class="hidden">
            <button id="btnSeeProfile" class="text-xs bg-slate-700 hover:bg-slate-600 px-3 py-1.5 rounded-md text-white">See profile</button>
          </div>
        </div>

        <!-- Messages -->
        <div id="threadBody" class="flex-1 scroll-y px-4 py-4 space-y-2">
          <div class="text-slate-500 dark:text-slate-400 text-center py-10">Pick someone from the left to start chatting.</div>
        </div>

        <!-- Composer -->
        <form id="composer" class="px-4 py-3 border-t border-slate-200 dark:border-slate-700 flex gap-3 items-end">
           <input type="hidden" id="activeUserId" />
           <textarea id="messageInput" class="input-field resize-none h-12 flex-1" placeholder="Type a message..." disabled></textarea>
           <button id="sendBtn" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-4 py-2.5 rounded-lg disabled:opacity-50 flex items-center gap-2" disabled>
             <span>Send</span>
             <svg id="sendLoader" class="animate-spin h-4 w-4 text-white hidden" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
           </button>
           <span id="sendIndicator" class="text-green-500 text-sm font-medium hidden ml-2 self-center">Sent!</span>
        </form>
      </section>
    </div>
  </main>

  <!-- New Chat Modal -->
  <div id="newChatModal" class="fixed inset-0 hidden flex items-center justify-center p-4 z-50 modal-backdrop">
    <div class="card-base-sub w-full max-w-lg p-6 relative shadow-xl">
      <button id="closeNewChat" class="absolute top-3 right-3 text-slate-400 hover:text-gray-600 dark:hover:text-white">
        ✕
      </button>
      <h3 class="text-xl font-bold mb-1">Start a new chat</h3>
      <p class="text-slate-500 dark:text-slate-400 mb-4">Choose a student you’re assigned to.</p>
      <div class="mb-3">
        <input id="studentSearch" class="input-field" placeholder="Search students..." />
      </div>
      <div id="studentsList" class="scroll-y space-y-2 max-h-80 pr-1">
        <?php if (empty($assigned_students)): ?>
          <div class="text-slate-500 dark:text-slate-400">No assigned students.</div>
        <?php else: ?>
          <?php foreach ($assigned_students as $s): 
            $initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
            $avatar = resolve_photo($s['profile_photo_url'], $s['first_name'].' '.$s['last_name']);
          ?>
          <button 
            class="w-full text-left flex items-center gap-3 p-3 rounded-lg"
            data-user-id="<?= (int)$s['id'] ?>"
            data-photo="<?= h($avatar) ?>"
            data-user-name="<?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>">
            <?php // Stored photo paths point at two earlier installs (/tutsys2/ and
                  // /kassoom/tutsys2.2.1/) and 404, so fall back to the initials. ?>
            <img src="<?= htmlspecialchars($avatar) ?>" class="w-9 h-9 rounded-full object-cover" alt=""
                 onerror="this.onerror=null;this.src='../assets/avatar.php?s=40&amp;bg=4f46e5&amp;fg=ffffff&amp;text=<?= urlencode($initials) ?>'">
            <div>
              <div class="font-semibold"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
            </div>
          </button>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php include __DIR__ . '/components/student_profile.php'; ?>
<script>
const api = 'api/messages_api.php';
let activeUserId = null;
let activeRole = '';
let threadVersion = 0;
let conversationVersion = 0;
const drafts = new Map();
let pollTimer = null;

// Utilities
function esc(s){ return s.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
function initials(name){ return name.split(' ').map(p=>p[0]||'').join('').slice(0,2).toUpperCase(); }
function fmtTime(iso){
  try{
    const d = new Date(iso);
    return d.toLocaleString();
  }catch(e){ return iso; }
}

async function fetchJSON(url, opts={}) {
  try {
    const res = await fetch(url, {credentials:'same-origin', ...opts});
    const data = await res.json();
    return res.ok ? data : {success:false, message:data.message || 'Request failed. Please try again.'};
  } catch (e) { return {success:false, message:'Could not reach the server. Please try again.'}; }
}

// Load conversations
async function loadConversations(q=''){
  const version = ++conversationVersion;
  const data = await fetchJSON(`${api}?action=list_conversations&q=${encodeURIComponent(q)}`);
  if (version !== conversationVersion) return;
  const box = document.getElementById('conversations');
  box.innerHTML = '';
  if(!data.success){ box.innerHTML = `<div class="text-slate-500 dark:text-slate-400 p-4">${esc(data.message||'Error')}</div>`; return; }
  if(data.items.length===0){ box.innerHTML = `<div class="text-slate-500 dark:text-slate-400 p-4">No conversations yet.</div>`; return; }

  data.items.forEach(item=>{
    const wrap = document.createElement('button');
    // Using conversation-item class for borders. Removing rounded corners and margin from individual items to make them flush.
    wrap.className = 'conversation-item w-full text-left flex items-center gap-3 p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors';
    wrap.dataset.userId = item.other_id;

    const av = item.profile_photo || `../assets/avatar.php?s=40&bg=4f46e5&fg=ffffff&text=${initials(item.name)}`;
    wrap.innerHTML = `
      <img src="${esc(av)}" class="w-10 h-10 rounded-full object-cover" alt=""
           onerror="this.onerror=null;this.src='../assets/avatar.php?s=40&bg=4f46e5&fg=ffffff&text=${encodeURIComponent(initials(item.name))}'" />
      <div class="flex-1 min-w-0">
        <div class="flex justify-between items-center">
          <div class="font-semibold truncate">${esc(item.name)}</div>
          <div class="flex items-center gap-2">
            <div class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">${item.last_time ? esc(fmtTime(item.last_time)) : ''}</div>
            ${item.unread > 0 ? `
              <span class="flex items-center justify-center min-w-5 h-5 rounded-full bg-rose-600 text-white text-xs font-semibold">
                ${item.unread > 9 ? '9+' : item.unread}
              </span>
            ` : ''}
          </div>
        </div>
        <div class="text-sm text-slate-500 dark:text-slate-400 truncate mt-0.5">${item.last_snippet ? esc(item.last_snippet) : ''}</div>
      </div>
    `;
    wrap.addEventListener('click', ()=> openThread(item.other_id, item.name, item.profile_photo, item.role));
    box.appendChild(wrap);
  });
}

// Open thread
async function openThread(userId, name, photo, role){
  if (activeUserId) drafts.set(String(activeUserId), document.getElementById('messageInput').value);
  activeUserId = String(userId);
  activeRole = role;
  ++threadVersion;
  document.getElementById('messageInput').value = drafts.get(activeUserId) || '';
  document.getElementById('activeUserId').value = userId;
  document.getElementById('messageInput').disabled = false;
  document.getElementById('sendBtn').disabled = false;

  // Header
  const avEl = document.getElementById('threadAvatar');
  avEl.innerHTML = '';
  avEl.style.backgroundImage = '';
  const avatar = document.createElement('img');
  avatar.className = 'w-10 h-10 rounded-full object-cover';
  avatar.alt = name;
  const fallback = '../assets/avatar.php?s=40&text=' + encodeURIComponent(initials(name));
  avatar.onerror = () => { avatar.onerror = null; avatar.src = fallback; };
  avatar.src = photo || fallback;
  avEl.append(avatar);
  document.getElementById('threadName').textContent = name;
  document.getElementById('threadMeta').textContent = role || '';
  document.getElementById('threadActions').classList.toggle('hidden', role !== 'Student');

  const body = document.getElementById('threadBody');
  body.innerHTML = '<div class="text-slate-500 dark:text-slate-400 text-center py-4">Loading…</div>';

  await loadMessages(true);
  // Start/refresh polling
  if(pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(()=> loadMessages(false), 8000);
}

// Load messages for active user
async function loadMessages(scrollToBottom){
  if(!activeUserId) return;
  const version = threadVersion;
  const body = document.getElementById('threadBody');
  const data = await fetchJSON(`${api}?action=get_messages&other_id=${encodeURIComponent(activeUserId)}`);
  if (version !== threadVersion) return;
  if(!data.success){ document.getElementById('threadActions').classList.add('hidden'); body.innerHTML = `<div class="text-slate-500 dark:text-slate-400 text-center py-6">${esc(data.message||'Error')}</div>`; return; }

  body.innerHTML = '';
  if(data.items.length === 0){
    body.innerHTML = `<div class="text-slate-500 dark:text-slate-400 text-center py-6">Say hi 👋</div>`;
  }else{
    data.items.forEach(m=>{
      const row = document.createElement('div');
      row.className = `flex ${m.is_me ? 'justify-end':'justify-start'}`;
      const bubble = document.createElement('div');
      bubble.className = `${m.is_me ? 'msg-me':'msg-them'} px-3 py-2 rounded-2xl max-w-[80%]`;
      bubble.style.borderTopRightRadius = m.is_me ? '0.4rem' : '1rem';
      bubble.style.borderTopLeftRadius  = m.is_me ? '1rem' : '0.4rem';
      bubble.innerHTML = `<div class="text-sm leading-5">${esc(m.content)}</div>
                          <div class="text-[10px] opacity-75 mt-1">${esc(fmtTime(m.sent_at))}${m.is_me ? (m.is_read ? ' · Read' : ' · Sent') : ''}</div>`;
      row.appendChild(bubble);
      body.appendChild(row);
    });
  }
  if(scrollToBottom){
    body.scrollTop = body.scrollHeight;
  }else{
    // keep view near bottom if already close
    if (body.scrollHeight - body.scrollTop - body.clientHeight < 120) {
      body.scrollTop = body.scrollHeight;
    }
  }

  // Refresh conversation list (unread counts)
  loadConversations(document.getElementById('convSearch').value || '');
}

// Send message
document.getElementById('composer').addEventListener('submit', async (e)=>{
  e.preventDefault();
  if(!activeUserId) return;
  const ta = document.getElementById('messageInput');
  const sendingTo = activeUserId;
  const text = ta.value.trim();
  if(!text) return;
  document.getElementById('sendBtn').disabled = true;
  const loader = document.getElementById('sendLoader');
  const indicator = document.getElementById('sendIndicator');
  
  // Show loader
  loader.classList.remove('hidden');

  // Optimistically add message to UI immediately
  const body = document.getElementById('threadBody');
  const row = document.createElement('div');
  row.className = 'flex justify-end';
  const bubble = document.createElement('div');
  bubble.className = 'msg-me px-3 py-2 rounded-2xl max-w-[80%]';
  bubble.style.borderTopRightRadius = '0.4rem';
  bubble.style.borderTopLeftRadius = '1rem';
  bubble.innerHTML = `<div class="text-sm leading-5">${esc(text)}</div>
                      <div class="text-[10px] opacity-75 mt-1">Sending...</div>`;
  row.appendChild(bubble);
  body.appendChild(row);
  body.scrollTop = body.scrollHeight;
  
  // Clear textarea immediately
  ta.value = '';
  drafts.set(sendingTo, '');

  const form = new FormData();
  form.append('action','send');
  form.append('receiver_id', sendingTo);
  form.append('content', text);
  const res = await fetchJSON(api, { method:'POST', body: form });
  
  // Hide loader, enable button
  loader.classList.add('hidden');
  document.getElementById('sendBtn').disabled = false;

  if(!res.success){ 
    // Remove optimistic message on failure
    row.remove();
    drafts.set(sendingTo, text);
    if (activeUserId === sendingTo && !ta.value) ta.value = text;
    alert(res.message || 'Failed to send'); 
    return; 
  }
  
  // Show "Sent!" temporarily
  indicator.classList.remove('hidden');
  setTimeout(() => indicator.classList.add('hidden'), 2000);

  // Reload messages to get the actual server version with timestamp
  await loadMessages(true);
});

// The API authorizes and marks read when opening; profiles do not touch the composer.
document.getElementById('btnSeeProfile').addEventListener('click', () => {
  if (activeUserId && activeRole === 'Student') window.openStudentProfile(activeUserId);
});

// Conversation search
document.getElementById('convSearch').addEventListener('input', (e)=>{
  loadConversations(e.target.value);
});

// New Chat Modal
const modal = document.getElementById('newChatModal');
document.getElementById('btnNewChat').addEventListener('click', ()=> modal.classList.remove('hidden','opacity-0'));
document.getElementById('closeNewChat').addEventListener('click', ()=> modal.classList.add('hidden'));
modal.addEventListener('click', (e)=> { if(e.target===modal) modal.classList.add('hidden'); });

// Filter assigned students client side
document.getElementById('studentSearch').addEventListener('input', (e)=>{
  const q = e.target.value.toLowerCase();
  document.querySelectorAll('#studentsList > button').forEach(b=>{
    const name = (b.dataset.userName||'').toLowerCase();
    b.style.display = name.includes(q) ? '' : 'none';
  });
});
// Click student -> open chat
document.querySelectorAll('#studentsList > button').forEach(b=>{
  b.addEventListener('click', ()=>{
    const id = b.dataset.userId;
    const name = b.dataset.userName;
    modal.classList.add('hidden');
    openThread(id, name, b.dataset.photo, 'Student');
  });
});

// Init
loadConversations();
</script>
</body>
</html>
