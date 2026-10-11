<?php
require_once 'auth_tutor_only.php';

// components/sidebar.php falls back to 'dashboard' when this is unset, so without it
// the sidebar told the tutor they were on the Dashboard while they read Materials.
$current_page = 'materials';

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../integrations/file_storage.php';

// 3) Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function app_url_base_prefix(): string {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $prefix = rtrim(dirname(dirname($script)), '/\\');
  return ($prefix === '/' ? '' : $prefix); // '' or '/tutsys'
}
$prefix = app_url_base_prefix();

// 4) Lookups
$subjects = [];
try {
  $rs = $pdo->query("SELECT id, name FROM subjects ORDER BY name ASC");
  $subjects = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

// 5) Filters
$q          = trim($_GET['q'] ?? '');
$f_type     = $_GET['type'] ?? 'all';
$f_curr     = trim($_GET['curriculum'] ?? '');
$f_grade    = ($_GET['grade'] ?? '') !== '' ? (int)$_GET['grade'] : '';
$f_subject  = ($_GET['subject_id'] ?? '') !== '' ? (int)$_GET['subject_id'] : '';
$types = ['Book','PastPaper','PPT','Video','Worksheet','Audio','Other'];

$where = ["lm.is_published = 1"]; $args = [];
if ($q !== '') {
  $where[] = "(lm.title LIKE ? OR lm.description LIKE ? OR lm.tags LIKE ?)";
  $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%";
}
if ($f_type !== 'all' && $f_type !== '') { $where[] = "lm.material_type = ?"; $args[] = $f_type; }
if ($f_curr !== '') { $where[] = "lm.curriculum = ?"; $args[] = $f_curr; }
if ($f_grade !== '') { $where[] = "lm.grade_level = ?"; $args[] = $f_grade; }
if ($f_subject !== '') { $where[] = "lm.subject_id = ?"; $args[] = $f_subject; }

$sql = "
  SELECT lm.*, s.name AS subject_name, CONCAT(u.first_name,' ',u.last_name) AS admin_name
  FROM learning_materials lm
  LEFT JOIN subjects s ON s.id = lm.subject_id
  LEFT JOIN users u ON u.id = lm.created_by_admin_id
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY lm.created_at DESC LIMIT 300";

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Material type icons, drawn from the shared sprite in assets/icons.
function type_icon(string $type): string {
  $icons = [
    'Book'      => '<svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book"></use></svg>',
    'PastPaper' => '<svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-scroll-text"></use></svg>',
    'PPT'       => '<svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-presentation"></use></svg>',
    'Video'     => '<svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-film"></use></svg>',
    'Worksheet' => '<svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-file-pen"></use></svg>',
    'Audio'     => '<svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-headphones"></use></svg>',
    'Other'     => '<svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-file"></use></svg>',
  ];
  return $icons[$type] ?? $icons['Other'];
}

function type_pill_cls(string $type): string {
  $map = [
    'Book'      => 'bg-emerald-500/15 text-emerald-300 ring-emerald-700/40',
    'PastPaper' => 'bg-amber-500/15 text-amber-300 ring-amber-700/40',
    'PPT'       => 'bg-pink-500/15 text-pink-300 ring-pink-700/40',
    'Video'     => 'bg-indigo-500/15 text-indigo-300 ring-indigo-700/40',
    'Worksheet' => 'bg-blue-500/15 text-blue-300 ring-blue-700/40',
    'Audio'     => 'bg-cyan-500/15 text-cyan-300 ring-cyan-700/40',
    'Other'     => 'bg-slate-500/15 text-slate-300 ring-slate-700/40',
  ];
  return $map[$type] ?? $map['Other'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Tutor — Materials Library</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
  <link rel="stylesheet" href="css/theme.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;}

    /* ---- Light-mode remap ----
       The card bodies are written in dark utilities, so in light mode every material
       title was text-white on a white card: contrast 1.00, completely invisible, and
       the action buttons were dark-tinted pills with pale text at about 1.3.
       Scoped to .material-body on purpose. The preview panel above each body keeps
       its dark gradient in both themes, and its type and subject chips must keep
       their light text, so they are deliberately outside this. */
    body.theme-light .material-body .text-white { color:#111827 !important; }
    body.theme-light .material-body .text-slate-400 { color:#4b5563 !important; }
    body.theme-light .material-body .text-slate-300 { color:#374151 !important; }
    body.theme-light .material-body .text-slate-200 { color:#1f2937 !important; }

    body.theme-light .material-body .bg-indigo-900\/50 { background-color:#e0e7ff !important; }
    body.theme-light .material-body .text-indigo-200 { color:#3730a3 !important; }
    body.theme-light .material-body .hover\:bg-indigo-800\/60:hover { background-color:#c7d2fe !important; }

    body.theme-light .material-body .bg-emerald-900\/40 { background-color:#d1fae5 !important; }
    body.theme-light .material-body .text-emerald-200 { color:#065f46 !important; }
    body.theme-light .material-body .hover\:bg-emerald-800\/50:hover { background-color:#a7f3d0 !important; }

    body.theme-light .material-body .bg-blue-900\/40 { background-color:#dbeafe !important; }
    body.theme-light .material-body .text-blue-200 { color:#1e40af !important; }
    body.theme-light .material-body .hover\:bg-blue-800\/50:hover { background-color:#bfdbfe !important; }

    body.theme-light .material-body .bg-slate-800\/50 { background-color:#e2e8f0 !important; }
    body.theme-light .material-body .hover\:bg-slate-700\/60:hover { background-color:#cbd5e1 !important; }

    /* These buttons all say hover:text-white, which would vanish on the light pills. */
    body.theme-light .material-body .hover\:text-white:hover { color:#0f172a !important; }

    body.theme-light .card-base { border-color:#e2e8f0 !important; }
    .panel{background:#0b1220;border:1px solid #1f2937}
    .form-input{background:#0b1220;border:1px solid #334155;color:#e5e7eb;border-radius:.6rem;padding:.65rem .9rem}
    .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.3)}
    ::-webkit-scrollbar{width:8px} ::-webkit-scrollbar-track{background:#111827} ::-webkit-scrollbar-thumb{background:#374151;border-radius:4px}
    .badge{font-size:11px;padding:.25rem .55rem;border-radius:9999px}
    .chip{font-size:11px;padding:.22rem .5rem;border-radius:9999px;line-height:1;white-space:nowrap}
    .ring-smooth{box-shadow:0 0 0 1px rgba(148,163,184,.15) inset}
    .skeleton{position:relative;overflow:hidden}
    .skeleton::after{content:'';position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,rgba(255,255,255,0) 0,rgba(255,255,255,.06) 50%,rgba(255,255,255,0) 100%);animation:shimmer 1.4s infinite}
    @keyframes shimmer{100%{transform:translateX(100%)}}
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
    $header_title = "Materials Library";
    $header_subtitle = "Browse, preview, and download materials shared by admins.";
    include 'components/header.php'; 
    ?>

    <!-- Modern Integrated Search & Filter Bar -->
    <section class="max-w-7xl mx-auto mb-10">
      <form method="get" class="flex flex-col lg:flex-row items-stretch lg:items-center bg-white dark:bg-[#1a1c23] p-1.5 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm transition-shadow gap-1.5">
        
        <!-- Search Input -->
        <div class="relative flex-1">
          <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
            <svg class="w-5 h-5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
          </div>
          <input type="text" name="q" class="w-full bg-transparent border-none rounded-lg py-3 pl-11 text-slate-700 dark:text-slate-200 placeholder-slate-400" placeholder="Search for materials, books, or topics..." value="<?= h($q) ?>">
        </div>

        <!-- Vertical Divider (Desktop) -->
        <div class="hidden lg:block w-px h-8 bg-slate-200 dark:bg-white/10 mx-1"></div>

        <!-- Filters Inline Group -->
        <div class="flex flex-wrap lg:flex-nowrap items-center gap-1.5 px-2 lg:px-0">
          
          <!-- Type Select -->
          <div class="relative flex-1 lg:flex-none">
            <select name="type" class="w-full lg:w-auto appearance-none bg-slate-50 dark:bg-white/5 border-none rounded-xl text-xs font-semibold px-4 py-2.5 pr-8 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer outline-none">
              <option value="all" <?= $f_type==='all'?'selected':''; ?>>All Types</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= h($t) ?>" <?= $f_type===$t?'selected':''; ?>><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
              <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
            </div>
          </div>

          <!-- Curriculum Select -->
          <div class="relative flex-1 lg:flex-none">
            <select name="curriculum" class="w-full lg:w-auto appearance-none bg-slate-50 dark:bg-white/5 border-none rounded-xl text-xs font-semibold px-4 py-2.5 pr-8 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer outline-none">
              <option value="">Curriculum</option>
              <?php foreach (['General','Cambridge','Edexcel','IB','MSCE','ZIMSEC','Other'] as $c): ?>
                <option value="<?= h($c) ?>" <?= $f_curr===$c?'selected':''; ?>><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
              <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
            </div>
          </div>

          <!-- Subject Select -->
          <div class="relative flex-1 lg:flex-none">
            <select name="subject_id" class="w-full lg:w-auto appearance-none bg-slate-50 dark:bg-white/5 border-none rounded-xl text-xs font-semibold px-4 py-2.5 pr-8 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer outline-none md:max-w-[150px]">
              <option value="">Subject</option>
              <?php foreach ($subjects as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $f_subject===(int)$s['id']?'selected':''; ?>><?= h($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
              <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
            </div>
          </div>

          <!-- Grade Input -->
          <input type="number" min="0" max="13" name="grade" class="w-20 bg-slate-100 dark:bg-white/5 border-none rounded-xl text-xs font-bold px-2 py-2.5 text-slate-600 dark:text-slate-400 text-center outline-none placeholder-slate-400 appearance-none" placeholder="Grade" value="<?= $f_grade!==''?(int)$f_grade:''; ?>">
          
          <!-- Submit Button -->
          <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white p-3 rounded-xl transition-all shadow-md shadow-indigo-600/10 active:scale-95 ml-1">
            <svg class="w-5 h-5 font-bold" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
          </button>
        </div>
      </form>
    </section>

    <?php if (empty($rows)): ?>
      <div class="panel rounded-xl p-10 text-center text-slate-400">
        No materials found. Try adjusting filters.
      </div>
    <?php else: ?>
      <section class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-4">
        <?php foreach ($rows as $r):
          $isFile = !empty($r['file_url']);
          $url    = $isFile ? (storage_gateway_url($r['file_url']) ?? ($prefix . $r['file_url'])) : ($r['external_url']);
          $type   = $r['material_type'] ?: 'Other';
          $pillCls = type_pill_cls($type);

          // what can we inline in the modal
          $canInline = false;
          if ($isFile) {
            $canInline = (bool)preg_match('/\.(mp4|webm|ogg|pdf|jpe?g|png|gif|webp)$/i', (string)$r['file_url']);
          } else {
            $canInline = (bool)preg_match('#(youtube\.com|youtu\.be|vimeo\.com)#i', (string)$url);
          }

          // subject color hue (stable-ish by ID)
          $subjHue = isset($r['subject_id']) ? ((int)$r['subject_id'] % 24) * 15 : 210; // 0..345 step 15
          $subjBg  = "hsl($subjHue, 55%, 18%)";
          $subjTx  = "hsl($subjHue, 80%, 82%)";
          $subjBd  = "hsl($subjHue, 55%, 28%)";

          // first-page/thumb hints for the card
          $isPdf   = $isFile && preg_match('/\.pdf(\?|$)/i', (string)$url);
          $isImg   = $isFile && preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/i', (string)$url);
          $ytId    = (!$isFile && preg_match('/(?:v=|\.be\/)([^&?\/]+)/', (string)$url, $m)) ? $m[1] : '';
        ?>
        <article class="card-base rounded-xl border border-slate-700 overflow-hidden group transition-transform duration-200 hover:-translate-y-0.5">
          <!-- Visual header with preview -->
          <div class="relative aspect-[210/297] w-full md:w-[80%] lg:w-[60%] mx-auto bg-gradient-to-br from-slate-900 to-slate-800">
            <?php if ($isPdf): ?>
              <canvas class="pdf-canvas skeleton w-full h-full block" data-url="<?= h($url) ?>" title="PDF preview (page 1)"></canvas>
              <?php // Shown only if the first page cannot be drawn. Without it a failed
                    // preview was an empty dark rectangle that explained nothing. ?>
              <div class="preview-fallback hidden absolute inset-0 flex items-center justify-center pb-12 text-slate-400">
                <div class="flex flex-col items-center gap-2 opacity-80">
                  <div class="p-3 rounded-full bg-slate-800/80 ring-1 ring-slate-700"><?= type_icon($type) ?></div>
                  <span class="text-xs">Preview unavailable</span>
                </div>
              </div>
            <?php elseif ($isImg): ?>
              <img src="<?= h($url) ?>" alt="" class="w-full h-full object-cover"
                   onerror="this.style.display='none'; this.parentElement.querySelector('.preview-fallback')?.classList.remove('hidden');"/>
              <div class="preview-fallback hidden absolute inset-0 flex items-center justify-center pb-12 text-slate-400">
                <div class="flex flex-col items-center gap-2 opacity-80">
                  <div class="p-3 rounded-full bg-slate-800/80 ring-1 ring-slate-700"><?= type_icon($type) ?></div>
                  <span class="text-xs">Preview unavailable</span>
                </div>
              </div>
            <?php elseif ($ytId): ?>
              <img src="https://i.ytimg.com/vi/<?= h($ytId) ?>/hqdefault.jpg" alt="YouTube thumbnail" class="w-full h-full object-cover"/>
              <div class="absolute inset-0 grid place-items-center"><div class="bg-black/50 rounded-full p-3"><?= type_icon('Video') ?></div></div>
            <?php else: ?>
              <div class="absolute inset-0 grid place-items-center text-slate-400">
                <div class="flex flex-col items-center gap-2 opacity-80">
                  <div class="p-3 rounded-full bg-slate-800/80 ring-1 ring-slate-700"><?= type_icon($type) ?></div>
                  <span class="text-xs">No preview available</span>
                </div>
              </div>
            <?php endif; ?>

            <!-- Type chip -->
            <span class="absolute top-2 right-2 chip <?= h($pillCls) ?> ring-1 ring-inset flex items-center gap-1">
              <?= type_icon($type) ?>
              <span><?= h($type) ?></span>
            </span>

            <!-- Subject / grade / curriculum chips -->
            <div class="absolute bottom-2 left-2 flex flex-wrap gap-2">
              <?php if (!empty($r['subject_name'])): ?>
                <span class="chip ring-1" style="background:<?= h($subjBg) ?>;color:<?= h($subjTx) ?>;border-color:<?= h($subjBd) ?>;"><?= h($r['subject_name']) ?></span>
              <?php endif; ?>
              <?php if ($r['grade_level'] !== null && $r['grade_level'] !== ''): ?>
                <span class="chip bg-slate-800/80 ring-1 ring-slate-700/60 text-slate-200">G<?= (int)$r['grade_level'] ?></span>
              <?php endif; ?>
              <?php if (!empty($r['curriculum'])): ?>
                <span class="chip bg-slate-800/80 ring-1 ring-slate-700/60 text-slate-200"><?= h($r['curriculum']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Body. material-body scopes the light-mode remap: the preview panel above
               stays dark in both themes and its chips must keep their light text. -->
          <div class="p-2 material-body">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-white font-semibold leading-snug line-clamp-2"><?= h($r['title']) ?></p>
                <p class="text-[11px] text-slate-400 mt-1">Added by <?= h($r['admin_name'] ?: 'Admin') ?> · <?= h(date('M j, Y · g:i a', strtotime($r['created_at']))) ?></p>
              </div>
            </div>

            <?php if (!empty($r['description'])): ?>
              <p class="text-sm text-slate-300 mt-2 line-clamp-3"><?= nl2br(h($r['description'])) ?></p>
            <?php endif; ?>

            <?php if (!empty($r['tags'])): ?>
              <p class="text-xs text-slate-400 mt-2">Tags: <?= h($r['tags']) ?></p>
            <?php endif; ?>

            <div class="flex items-center justify-between mt-3">
              <div class="flex items-center gap-3">
                <?php if ($url): ?>
                  <button class="flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-indigo-900/50 hover:bg-indigo-800/60 text-indigo-200 hover:text-white transition-colors text-xs font-medium"
                          onclick='openPreview(<?= json_encode($r['title'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode($type, JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode($url, JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= $canInline ? 'true':'false' ?>, <?= json_encode($r['description'] ?? '', JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode($r['curriculum'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= (int)$r['grade_level'] ?>, <?= json_encode($r['subject_name'] ?? '', JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                    <svg class="h-3.5 w-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-eye"></use></svg>
                    <span>Preview</span>
                  </button>
                  
                  <?php if ($isFile): ?>
                    <a href="<?= h($url) ?>" download class="flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-emerald-900/40 hover:bg-emerald-800/50 text-emerald-200 hover:text-white transition-colors text-xs font-medium">
                      <svg class="h-3.5 w-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-download"></use></svg>
                      <span>Download</span>
                    </a>
                  <?php else: ?>
                    <a href="<?= h($url) ?>" target="_blank" class="flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-blue-900/40 hover:bg-blue-800/50 text-blue-200 hover:text-white transition-colors text-xs font-medium">
                      <svg class="h-3.5 w-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-external-link"></use></svg>
                      <span>Open</span>
                    </a>
                  <?php endif; ?>
                  
                  <button class="flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-slate-800/50 hover:bg-slate-700/60 text-slate-300 hover:text-white transition-colors text-xs font-medium" 
                          onclick="copyLink('<?= h($url) ?>')">
                    <svg class="h-3.5 w-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-copy"></use></svg>
                    <span>Copy</span>
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>

  <!-- Preview Modal -->
  <div id="matModal" class="hidden fixed inset-0 z-50 items-center justify-center">
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="relative panel rounded-xl w-full max-w-3xl p-6 border border-slate-700 mx-4">
      <div class="flex items-center justify-between mb-2">
        <h3 id="mTitle" class="text-xl font-bold text-white"></h3>
        <button id="mClose" class="text-slate-400 hover:text-white">✕</button>
      </div>
      <p id="mMeta" class="text-xs text-slate-400 mb-3"></p>
      <div id="mBody" class="space-y-3">
        <!-- dynamic -->
      </div>
      <div class="mt-4 text-right flex items-center gap-2 justify-end">
        <a id="mOpen" href="#" target="_blank" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold px-4 py-2 rounded-lg">Open in new tab</a>
        <a id="mDownload" href="#" download class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold px-4 py-2 rounded-lg hidden">Download</a>
      </div>
    </div>
  </div>

  <!-- pdf.js for in-card first-page previews (lazy) -->
  <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
  <script>
    // Configure pdf.js worker
    if (window['pdfjsLib']) {
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
    }

    const modal  = document.getElementById('matModal');
    const mTitle = document.getElementById('mTitle');
    const mMeta  = document.getElementById('mMeta');
    const mBody  = document.getElementById('mBody');
    const mOpen  = document.getElementById('mOpen');
    const mDl    = document.getElementById('mDownload');
    document.getElementById('mClose').addEventListener('click', ()=> { modal.classList.add('hidden'); modal.classList.remove('flex'); });
    window.addEventListener('click', e => { if (e.target === modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); } });

    function openPreview(title, type, url, canInline, desc, curriculum, grade, subjectName){
      mTitle.textContent = title;
      const bits = [type, curriculum || ''];
      if (grade !== null && grade !== undefined && grade !== '') bits.push('Grade ' + (grade || 0));
      if (subjectName) bits.push(subjectName);
      mMeta.textContent = bits.filter(Boolean).join(' · ');

      mBody.innerHTML = '';

      if (canInline) {
        if (/\.pdf(\?|$)/i.test(url)) {
          const f = document.createElement('iframe');
          f.src = url; f.className = 'w-full h-[70vh] rounded-lg bg-black/20';
          mBody.appendChild(f);
        } else if (/\.(mp4|webm|ogg)(\?|$)/i.test(url)) {
          const v = document.createElement('video');
          v.src = url; v.controls = true; v.className = 'w-full rounded-lg bg-black/30';
          mBody.appendChild(v);
        } else if (/youtube\.com|youtu\.be/i.test(url)) {
          const id = (url.match(/(?:v=|\.be\/)([^&?\/]+)/) || [null,null])[1];
          if (id){
            const iframe = document.createElement('iframe');
            iframe.className = 'w-full h-[60vh] rounded-lg';
            iframe.src = 'https://www.youtube.com/embed/' + encodeURIComponent(id);
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
            iframe.allowFullscreen = true;
            mBody.appendChild(iframe);
          }
        } else if (/vimeo\.com/i.test(url)) {
          const match = url.match(/vimeo\.com\/(\d+)/);
          if (match){
            const iframe = document.createElement('iframe');
            iframe.className = 'w-full h-[60vh] rounded-lg';
            iframe.src = 'https://player.vimeo.com/video/' + match[1];
            iframe.allow = 'autoplay; fullscreen; picture-in-picture';
            iframe.allowFullscreen = true;
            mBody.appendChild(iframe);
          }
        } else if (/\.(jpe?g|png|gif|webp)(\?|$)/i.test(url)) {
          const img = document.createElement('img');
          img.src = url; img.alt = 'Preview image'; img.className = 'w-full rounded-lg object-contain bg-black/10';
          mBody.appendChild(img);
        }
      }
      if (desc) {
        const p = document.createElement('p');
        p.className = 'text-sm text-slate-300';
        p.textContent = desc;
        mBody.appendChild(p);
      }

      mOpen.href = url || '#';

      // show download only for stored (non-external-link) materials
      if (url && (url.indexOf('/uploads/materials/') !== -1 || url.indexOf('file_url.php?p=materials') !== -1)) {
        mDl.href = url; mDl.classList.remove('hidden');
      } else {
        mDl.href = '#'; mDl.classList.add('hidden');
      }

      modal.classList.remove('hidden'); modal.classList.add('flex');
    }

    async function copyLink(url){
      try { await navigator.clipboard.writeText(url); alert('Link copied to clipboard'); }
      catch (e){ prompt('Copy link:', url); }
    }

    // Lazy-render first page of PDFs into their canvases using IntersectionObserver
    async function renderPdfToCanvas(canvas, url){
      try {
        if (!window['pdfjsLib']) return; // fallback silently
        const loadingTask = pdfjsLib.getDocument({ url });
        const pdf = await loadingTask.promise;
        const page = await pdf.getPage(1);
        const viewport = page.getViewport({ scale: 1 });
        // Fit canvas to container box
        const box = canvas.getBoundingClientRect();
        const scale = Math.min((box.width / viewport.width) || 1, (box.height / viewport.height) || 1) * 1.2; // a touch sharper
        const v2 = page.getViewport({ scale });
        const context = canvas.getContext('2d');
        canvas.width = v2.width; canvas.height = v2.height;
        await page.render({ canvasContext: context, viewport: v2, background: 'rgba(0,0,0,0)' }).promise;
        canvas.classList.remove('skeleton');
      } catch (err) {
        // Most often this is CORS: the file lives in the R2 bucket and api/file_url.php
        // redirects to a presigned URL on r2.cloudflarestorage.com, which sends no
        // Access-Control-Allow-Origin, so the browser blocks the cross-origin read.
        // Show the type icon rather than leaving an empty rectangle.
        console.warn('PDF preview failed:', err);
        canvas.classList.remove('skeleton');
        canvas.style.display = 'none';
        canvas.parentElement?.querySelector('.preview-fallback')?.classList.remove('hidden');
      }
    }

    const observer = ('IntersectionObserver' in window) ? new IntersectionObserver((entries)=>{
      entries.forEach(e => {
        if (e.isIntersecting) {
          const c = e.target; observer.unobserve(c);
          const url = c.getAttribute('data-url');
          if (url) renderPdfToCanvas(c, url);
        }
      });
    }, { rootMargin: '200px 0px' }) : null;

    document.querySelectorAll('canvas.pdf-canvas').forEach(c => {
      if (observer) observer.observe(c); else renderPdfToCanvas(c, c.getAttribute('data-url'));
    });

    // Backstop: if the observer never fires (it does not in a zero-height viewport),
    // the canvases would sit as skeletons forever with no request ever made.
    setTimeout(() => {
      document.querySelectorAll('canvas.pdf-canvas.skeleton').forEach(c => {
        if (observer) observer.unobserve(c);
        renderPdfToCanvas(c, c.getAttribute('data-url'));
      });
    }, 2500);
  </script>
</body>
</html>
