<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// admin_import_topics.php
session_start();
require_once __DIR__.'/authz.php'; // exposes can(), require_cap(), etc.
require_cap('*'); // Full admin only (change to require_cap('curriculum.write') if you add a specific cap)

$db_host=DB_HOST; $db_name=DB_NAME; $db_user=DB_USER; $db_pass=DB_PASSWORD;

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ensure_csrf(){
  if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function check_csrf(){
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(400); die('Bad CSRF');
  }
}

try {
  $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) { die('DB error: '.h($e->getMessage())); }

$current_page = 'curriculum'; // highlight “Curriculum & Subjects” in sidebar

// -------- Helpers --------
function table_exists(PDO $pdo, string $name): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $st->execute([$name]); return (bool)$st->fetchColumn();
}
function parse_csv_to_rows(string $file): array {
  $rows = [];
  if (($fh = fopen($file, 'r')) !== false) {
    while (($r = fgetcsv($fh)) !== false) {
      // normalize to UTF-8-ish
      $rows[] = array_map(fn($x)=> trim((string)$x), $r);
    }
    fclose($fh);
  }
  return $rows;
}
// Optional XLSX support if SimpleXLSX exists (composer/standalone lib)
function parse_xlsx_to_rows(string $file): array {
  if (!class_exists('SimpleXLSX')) return [];
  $rows = [];
  if ($xlsx = SimpleXLSX::parse($file)) {
    foreach ($xlsx->rows() as $r) { $rows[] = array_map(fn($x)=> trim((string)$x), $r); }
  }
  return $rows;
}

// Try to guess Year + Curriculum from filename/content
function guess_year(string $filename, array $rows): ?string {
  $hay = strtolower($filename.' '.implode(' ', array_map(fn($r)=> implode(' ', $r), array_slice($rows,0,40))));
  // patterns: Year 8 / Y8 / Grade 8 / Form 3 (optional)
  if (preg_match('/\byear\s*(\d{1,2})\b/i', $hay, $m)) return $m[1];
  if (preg_match('/\by(\d{1,2})\b/i', $hay, $m)) return $m[1];
  if (preg_match('/\bgrade\s*(\d{1,2})\b/i', $hay, $m)) return $m[1];
  return null;
}
function guess_curriculum(string $filename, array $rows): ?string {
  $hay = strtolower($filename.' '.implode(' ', array_map(fn($r)=> implode(' ', $r), array_slice($rows,0,40))));
  $map = [
    'cambridge'=>'Cambridge',
    'cie'=>'Cambridge',
    'igcse'=>'Cambridge IGCSE',
    'gcse'=>'GCSE',
    'edexcel'=>'Edexcel',
    'aqa'=>'AQA',
    'ocr'=>'OCR',
    'ib'=>'IB',
    'wjec'=>'WJEC',
    'pearson'=>'Pearson',
    'caps'=>'CAPS',
    'zimsec'=>'ZIMSEC',
  ];
  foreach ($map as $k=>$label) if (str_contains($hay, $k)) return $label;
  return null;
}

// Load dropdowns
$subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();

// Optional curriculums table (if present)
$hasCurriculums = table_exists($pdo, 'curriculums');
$curriculums = [];
if ($hasCurriculums) {
  $curriculums = $pdo->query("SELECT id, name FROM curriculums ORDER BY name")->fetchAll();
}

// ------------- Step routing -------------
$step = $_POST['step'] ?? $_GET['step'] ?? 'upload';
$csrf = ensure_csrf();

$stage_data = [
  'file_path' => '',
  'file_name' => '',
  'rows'      => [],
  'headers'   => [],
  'guess'     => ['year'=>null, 'curriculum'=>null],
];

// keep big payload in a temp file instead of session bloat
function stash_preview(array $data): string {
  $key = 'import_'.bin2hex(random_bytes(8)).'.json';
  file_put_contents(sys_get_temp_dir().DIRECTORY_SEPARATOR.$key, json_encode($data));
  return $key;
}
function load_preview(string $key): ?array {
  $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$key;
  if (!is_file($path)) return null;
  $json = file_get_contents($path);
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : null;
}

// Handle upload -> preview
if ($step === 'preview' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();

  if (empty($_FILES['sheet']['name'])) { $err='Please select a CSV/XLSX file.'; $step='upload'; }
  else {
    $upDir = __DIR__.'/uploads/tmp';
    if (!is_dir($upDir)) @mkdir($upDir, 0775, true);
    $name  = $_FILES['sheet']['name'];
    $tmp   = $_FILES['sheet']['tmp_name'];
    $safe  = preg_replace('/[^a-zA-Z0-9._-]/','_', $name);
    $dest  = $upDir.'/'.time().'_'.$safe;
    if (!move_uploaded_file($tmp, $dest)) { $err='Failed to upload file.'; $step='upload'; }
    else {
      $ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
      $rows = [];
      if ($ext === 'csv') $rows = parse_csv_to_rows($dest);
      elseif ($ext === 'xlsx') $rows = parse_xlsx_to_rows($dest);
      else { $err='Unsupported file type. Please upload CSV or XLSX.'; $step='upload'; }

      if ($step==='preview') {
        if (empty($rows)) { $err='Could not read any rows. If XLSX, ensure SimpleXLSX is installed or save as CSV.'; $step='upload'; }
        else {
          // Trim empty lines
          $rows = array_values(array_filter($rows, fn($r)=> implode('', $r) !== ''));

          // --- Best-effort header detection (index-aware, PHP 8-safe)
$headers = [];
if (!empty($rows)) {
  $first = $rows[0];                 // first row as headers (or placeholders)
  foreach ($first as $colIndex => $cell) {
    $label = trim((string)$cell);
    $headers[$colIndex] = ($label === '') ? ('Column ' . ($colIndex + 1)) : $label;
  }
}

// How many columns to show in preview/mapping
$numCols = !empty($headers) ? count($headers) : (!empty($rows[0]) ? count($rows[0]) : 0);


          $guess = [
            'year'       => guess_year($name, $rows),
            'curriculum' => guess_curriculum($name, $rows),
          ];

          $stage_data = [
            'file_path' => $dest,
            'file_name' => $name,
            'rows'      => $rows,
            'headers'   => $headers,
            'guess'     => $guess,
          ];
          $key = stash_preview($stage_data);
        }
      }
    }
  }
}

// Handle import confirm
$doneSummary = null;
if ($step === 'import' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();

  $key = $_POST['key'] ?? '';
  $payload = load_preview($key);
  if (!$payload) { $err='Session preview expired. Please re-upload.'; $step='upload'; }
  else {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    if ($subject_id<=0) { $err='Please choose a subject.'; $step='upload'; }

    $year_in   = trim($_POST['year'] ?? '');
    $curr_sel  = trim($_POST['curriculum_name'] ?? ''); // text fallback
    $curr_id   = (int)($_POST['curriculum_id'] ?? 0);   // dropdown (if table exists)
    $topic_col = (int)($_POST['topic_col'] ?? -1);
    $sub_col   = (int)($_POST['subtopic_col'] ?? -1);
    $selected  = $_POST['include'] ?? []; // array of row indexes to include

    if (!isset($payload['rows'])) { $err='Invalid payload.'; $step='upload'; }
    elseif ($topic_col<0 && $sub_col<0) { $err='Map at least a Topic or Subtopic column.'; $step='upload'; }
    else {
      $rows = $payload['rows'];
      // Skip header row if the user checked that box
      $skipHeader = isset($_POST['skip_header']) && $_POST['skip_header']==='1';
      if ($skipHeader && count($rows)>0) array_shift($rows);

      // Build topic -> subtopics map from the selected rows only
      $map = []; // [topic => [sub1, sub2...]]
      foreach ($rows as $idx=>$r) {
        if (!in_array((string)$idx, $selected, true)) continue;
        $topic = $topic_col>=0 ? trim((string)($r[$topic_col] ?? '')) : '';
        $sub   = $sub_col>=0   ? trim((string)($r[$sub_col]   ?? '')) : '';

        if ($topic==='' && $sub==='') continue;
        if ($topic==='') $topic = '(Untitled Topic)';

        $map[$topic] = $map[$topic] ?? [];
        if ($sub!=='') $map[$topic][] = $sub;
      }

      // Deduplicate subtopics in each topic
      foreach ($map as $t=>$subs) {
        $subs = array_values(array_unique(array_filter($subs, fn($s)=>$s!=='')));
        $map[$t] = $subs;
      }

      // Optional: upsert curriculum if you have a table
      if ($hasCurriculums) {
        if ($curr_id<=0 && $curr_sel!=='') {
          // find or create
          $st = $pdo->prepare("SELECT id FROM curriculums WHERE name = ?");
          $st->execute([$curr_sel]);
          $curr_id = (int)$st->fetchColumn();
          if ($curr_id<=0) {
            $pdo->prepare("INSERT INTO curriculums (name) VALUES (?)")->execute([$curr_sel]);
            $curr_id = (int)$pdo->lastInsertId();
          }
        }
      }

      // Insert topics & subtopics (skip if exist for this subject)
      $insT = $pdo->prepare("INSERT INTO topics (subject_id, name) VALUES (?, ?)");
      $findT = $pdo->prepare("SELECT id FROM topics WHERE subject_id=? AND LOWER(name)=LOWER(?) LIMIT 1");

      $insS = $pdo->prepare("INSERT INTO subtopics (topic_id, name) VALUES (?, ?)");
      $findS = $pdo->prepare("SELECT id FROM subtopics WHERE topic_id=? AND LOWER(name)=LOWER(?) LIMIT 1");

      $addedT=0; $skippedT=0; $addedS=0; $skippedS=0;

      foreach ($map as $topicName=>$subs) {
        $findT->execute([$subject_id, $topicName]);
        $topic_id = (int)$findT->fetchColumn();
        if ($topic_id<=0) {
          $insT->execute([$subject_id, $topicName]);
          $topic_id = (int)$pdo->lastInsertId();
          $addedT++;
        } else { $skippedT++; }

        foreach ($subs as $subName) {
          $findS->execute([$topic_id, $subName]);
          $sid = (int)$findS->fetchColumn();
          if ($sid<=0) { $insS->execute([$topic_id, $subName]); $addedS++; }
          else { $skippedS++; }
        }
      }

      $doneSummary = [
        'topics_added'=>$addedT, 'topics_skipped'=>$skippedT,
        'subs_added'=>$addedS,  'subs_skipped'=>$skippedS,
      ];
      $step='done';
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Import Topics</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,sans-serif;background:#111827;color:#e5e7eb}
    .form-input{background:#1f2937;border:1px solid #374151;color:#e5e7eb;border-radius:.5rem;padding:.6rem .8rem;width:100%}
    .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px #4f46e5}
    .hint{color:#9ca3af;font-size:.85rem}
    .glass{background:linear-gradient(135deg,rgba(99,102,241,.08),rgba(236,72,153,.06));border:1px solid rgba(255,255,255,.06)}
    ::-webkit-scrollbar{width:8px}::-webkit-scrollbar-thumb{background:#374151;border-radius:4px}
  </style>
</head>
<body class="flex min-h-screen">
  <?php include __DIR__.'/admin_sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
    <header class="mb-6">
      <h2 class="text-2xl font-bold text-white">Import Topics from Spreadsheet</h2>
      <p class="text-gray-400">Upload a CSV/XLSX with topics and subtopics, confirm mappings, and import.</p>
    </header>

    <?php if (!empty($err)): ?>
      <div class="mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300"><?=h($err)?></div>
    <?php endif; ?>

    <?php if ($step==='upload'): ?>
      <section class="bg-gray-900 p-6 rounded-lg">
        <form method="post" enctype="multipart/form-data" class="space-y-5">
          <input type="hidden" name="csrf" value="<?=$csrf?>">
          <input type="hidden" name="step" value="preview">
          <div>
            <label class="block text-sm text-gray-300 mb-1">Spreadsheet (CSV or XLSX)</label>
            <input type="file" name="sheet" accept=".csv,.xlsx" class="form-input">
            <p class="hint mt-1">Tip: If XLSX parsing fails, save as CSV and upload again.</p>
          </div>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-4 py-2 rounded-lg">Upload & Preview</button>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($step==='preview'): ?>
      <?php
        $key = $key ?? ($key ?? ($key = $key ?? stash_preview($stage_data)));
        if (empty($key)) $key = stash_preview($stage_data);
        $rows    = $stage_data['rows'];
        $headers = $stage_data['headers'];
        $guess   = $stage_data['guess'];
      ?>
      <section class="space-y-6">
        <div class="bg-gray-900 p-6 rounded-lg">
          <h3 class="text-lg font-semibold text-white mb-4">Step 1 — Pick Subject, Curriculum & Year</h3>
          <form id="prepForm" method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="step" value="import">
            <input type="hidden" name="key"  value="<?=h($key)?>">

            <div>
              <label class="text-sm text-gray-300 mb-1 block">Subject</label>
              <select name="subject_id" class="form-input" required>
                <option value="">— Choose subject —</option>
                <?php foreach ($subjects as $s): ?>
                  <option value="<?=$s['id']?>"><?=h($s['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="text-sm text-gray-300 mb-1 block">Curriculum / Exam Board</label>
              <?php if ($hasCurriculums): ?>
                <select name="curriculum_id" class="form-input">
                  <option value="0">— Choose —</option>
                  <?php foreach ($curriculums as $c): ?>
                    <option value="<?=$c['id']?>" <?= ($guess['curriculum'] && strtolower($c['name'])===strtolower($guess['curriculum']))?'selected':''; ?>>
                      <?=h($c['name'])?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="hint mt-1">Or type a new one:</p>
                <input type="text" name="curriculum_name" class="form-input mt-1" placeholder="e.g., Cambridge IGCSE" value="<?=h($guess['curriculum'] ?? '')?>">
              <?php else: ?>
                <input type="text" name="curriculum_name" class="form-input" placeholder="e.g., AQA / Edexcel / Cambridge" value="<?=h($guess['curriculum'] ?? '')?>">
                <p class="hint mt-1">No <code>curriculums</code> table found — this value won’t be stored unless you add such a table later.</p>
              <?php endif; ?>
            </div>

            <div>
              <label class="text-sm text-gray-300 mb-1 block">Year / Grade</label>
              <input type="text" name="year" class="form-input" placeholder="e.g., 8" value="<?=h($guess['year'] ?? '')?>">
            </div>

            <div class="md:col-span-3 border-t border-gray-800 my-2"></div>

            <div>
              <label class="text-sm text-gray-300 mb-1 block">Topic column</label>
              <select name="topic_col" class="form-input">
                <option value="-1">— none —</option>
                <?php
                  $numCols = 0;
                  if (!empty($rows)) $numCols = max(array_map('count',$rows));
                  for ($i=0;$i<$numCols;$i++):
                    $lbl = $headers[$i] ?? ('Column '.($i+1));
                ?>
                  <option value="<?=$i?>" <?= (stripos($lbl,'topic')!==false || stripos($lbl,'unit')!==false) ? 'selected':''; ?>>
                    <?=h($lbl)?>
                  </option>
                <?php endfor; ?>
              </select>
            </div>

            <div>
              <label class="text-sm text-gray-300 mb-1 block">Subtopic column</label>
              <select name="subtopic_col" class="form-input">
                <option value="-1">— none —</option>
                <?php for ($i=0;$i<$numCols;$i++):
                  $lbl = $headers[$i] ?? ('Column '.($i+1));
                ?>
                  <option value="<?=$i?>" <?= (stripos($lbl,'subtopic')!==false || stripos($lbl,'lesson')!==false || stripos($lbl,'objective')!==false) ? 'selected':''; ?>>
                    <?=h($lbl)?>
                  </option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="flex items-center gap-2">
              <input id="skipHeader" type="checkbox" name="skip_header" value="1" class="rounded">
              <label for="skipHeader" class="text-sm text-gray-300">First row is header</label>
            </div>
          </form>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg">
          <h3 class="text-lg font-semibold text-white mb-3">Step 2 — Review & Select</h3>
          <p class="hint mb-4">Only selected rows will be imported. Duplicate topic/subtopic combos are collapsed at save.</p>

          <div class="overflow-auto max-h-[55vh] glass rounded-lg">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-800 sticky top-0">
                <tr>
                  <th class="text-left px-3 py-2">Import</th>
                  <th class="text-left px-3 py-2">Row</th>
                  <?php for ($i=0;$i<$numCols;$i++): ?>
                    <th class="text-left px-3 py-2"><?=h($headers[$i] ?? ('Column '.($i+1)))?></th>
                  <?php endfor; ?>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $idx=>$r): ?>
                <tr class="<?= $idx%2 ? 'bg-gray-900/40':'bg-gray-900/20' ?>">
                  <td class="px-3 py-2">
                    <input type="checkbox" form="prepForm" name="include[]" value="<?=$idx?>" class="rounded" <?= $idx<200 ? 'checked':''; ?>>
                  </td>
                  <td class="px-3 py-2 text-gray-400">#<?= $idx+1 ?></td>
                  <?php for ($i=0;$i<$numCols;$i++): ?>
                    <td class="px-3 py-2"><?=h($r[$i] ?? '')?></td>
                  <?php endfor; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="mt-4 flex items-center justify-end gap-3">
            <a href="admin_import_topics.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">Start Over</a>
            <button form="prepForm" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 rounded-lg font-semibold text-white">
              Import Selected
            </button>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($step==='done' && $doneSummary): ?>
      <section class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white mb-3">Import Complete</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-lg p-4">
            <div class="text-2xl font-bold text-white"><?= (int)$doneSummary['topics_added'] ?></div>
            <div class="text-sm text-gray-300">Topics added</div>
          </div>
          <div class="bg-gray-800 border border-gray-700 rounded-lg p-4">
            <div class="text-2xl font-bold text-white"><?= (int)$doneSummary['topics_skipped'] ?></div>
            <div class="text-sm text-gray-300">Topics skipped (exist)</div>
          </div>
          <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-lg p-4">
            <div class="text-2xl font-bold text-white"><?= (int)$doneSummary['subs_added'] ?></div>
            <div class="text-sm text-gray-300">Subtopics added</div>
          </div>
          <div class="bg-gray-800 border border-gray-700 rounded-lg p-4">
            <div class="text-2xl font-bold text-white"><?= (int)$doneSummary['subs_skipped'] ?></div>
            <div class="text-sm text-gray-300">Subtopics skipped (exist)</div>
          </div>
        </div>
        <div class="mt-5 flex items-center gap-3">
          <a href="admin_curriculum.php" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-white font-semibold">Go to Curriculum</a>
          <a href="admin_import_topics.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">Import Another</a>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
