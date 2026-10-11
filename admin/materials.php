<?php
// admin/materials.php — Teaching Resources (upload, link, edit, delete & library).
//
// The bug that mattered here: every download link was built as a raw
// "/uploads/materials/<file>" path. All six materials have been offloaded to the R2
// bucket and no longer exist on local disk, so every one of those links 404'd. This
// file already defined materials_public_url() — the authenticated gateway the rest
// of the codebase uses — and never called it.

require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
require_once __DIR__ . '/../integrations/file_storage.php';

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['user_role'] ?? '';
// Was never set, so the sidebar could not highlight this page.
$current_page = 'materials';

if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// admin/db_connect.php built a third hand-rolled PDO on top of the pooled one and
// dropped DB_PORT from the DSN. Other pages still include it; this one no longer does.
try {
  $pdo = getDbConnection();
} catch (Throwable $e) {
  error_log('admin materials DB error: ' . $e->getMessage());
  http_response_code(503);
  die('Teaching resources are temporarily unavailable. Please try again shortly.');
}

function is_admin(PDO $pdo, int $uid, string $role): bool {
  if ($role === 'Administrator') return true;
  $st = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator')");
  $st->execute([$uid]);
  return (bool)$st->fetchColumn();
}
if (!is_admin($pdo, $user_id, $user_role)) { header('Location: ../login.php'); exit(); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

const MAX_UPLOAD_BYTES = 200 * 1024 * 1024; // 200MB
const MAX_GRADE        = 20;                // grade_level is TINYINT UNSIGNED
const PER_PAGE         = 24;

$TYPES = ['Book','PastPaper','PPT','Video','Worksheet','Audio','Other'];

/** Extensions, and the MIME types we will accept for each. */
$ALLOW_BY_TYPE = [
  'Book'      => ['pdf','epub'],
  'PastPaper' => ['pdf','doc','docx'],
  'PPT'       => ['ppt','pptx','pdf'],
  'Video'     => ['mp4','webm','ogg','mov','m4v'],
  'Worksheet' => ['pdf','doc','docx','xls','xlsx'],
  'Audio'     => ['mp3','m4a','wav','ogg'],
  'Other'     => ['pdf','doc','docx','xls','xlsx','ppt','pptx','zip','png','jpg','jpeg','mp4','webm'],
];
// The old code trusted the client-supplied filename extension alone.
$MIME_BY_EXT = [
  'pdf'=>['application/pdf'], 'epub'=>['application/epub+zip','application/zip'],
  'doc'=>['application/msword','application/octet-stream'],
  'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
  'xls'=>['application/vnd.ms-excel','application/octet-stream'],
  'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],
  'ppt'=>['application/vnd.ms-powerpoint','application/octet-stream'],
  'pptx'=>['application/vnd.openxmlformats-officedocument.presentationml.presentation','application/zip'],
  'zip'=>['application/zip','application/x-zip-compressed'],
  'png'=>['image/png'], 'jpg'=>['image/jpeg'], 'jpeg'=>['image/jpeg'],
  'mp4'=>['video/mp4'], 'webm'=>['video/webm'], 'ogg'=>['video/ogg','audio/ogg','application/ogg'],
  'mov'=>['video/quicktime'], 'm4v'=>['video/x-m4v','video/mp4'],
  'mp3'=>['audio/mpeg'], 'm4a'=>['audio/mp4','audio/x-m4a'], 'wav'=>['audio/wav','audio/x-wav'],
];

function materials_dir_abs(): string {
  $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'materials';
  if (!is_dir($root)) @mkdir($root, 0775, true);
  return $root;
}

/**
 * The URL a material should actually be opened through.
 *
 * Files go via api/file_url.php, which 302s to a 1-hour presigned bucket URL (or
 * streams the local copy when the offload has not happened). The old render built
 * "$prefix . $r['file_url']" instead, which points at a local path that no longer
 * exists for anything already moved to the bucket.
 */
function material_open_url(array $row): ?string {
  if (!empty($row['file_url'])) {
    return '../api/file_url.php?p=' . rawurlencode('materials/' . basename($row['file_url']));
  }
  return !empty($row['external_url']) ? $row['external_url'] : null;
}

function safe_store_name(string $orig): string {
  $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
  return bin2hex(random_bytes(6)) . '_' . $base;
}

function safe_unlink_if_owned(?string $file_url): void {
  if (!$file_url) return;
  if (!preg_match('#^/uploads/materials/#i', $file_url)) return;
  $abs = materials_dir_abs() . DIRECTORY_SEPARATOR . basename($file_url);
  if (is_file($abs)) @unlink($abs);
  storage_delete($file_url); // also remove the bucket copy
}

/**
 * Validate an upload and move it into place. Returns the stored "/uploads/..." path.
 * Throws before touching anything if the file is not acceptable.
 */
function accept_upload(?array $f, string $type): string {
  global $ALLOW_BY_TYPE, $MIME_BY_EXT;
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    throw new RuntimeException('Please choose a file, or switch to “External link”.');
  }
  if ($f['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
      ? 'That file is larger than the server allows.' : 'Upload failed. Try again.');
  }
  if (($f['size'] ?? 0) > MAX_UPLOAD_BYTES) throw new RuntimeException('File too large (max 200MB).');

  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  $allowedExt = $ALLOW_BY_TYPE[$type] ?? $ALLOW_BY_TYPE['Other'];
  if (!in_array($ext, $allowedExt, true)) {
    throw new RuntimeException('A ' . $type . ' must be one of: ' . implode(', ', $allowedExt) . '.');
  }
  // Sniff the actual bytes, not just the name the browser sent.
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $f['tmp_name']);
  finfo_close($finfo);
  $okMimes = $MIME_BY_EXT[$ext] ?? [];
  if ($okMimes && !in_array($mime, $okMimes, true)) {
    throw new RuntimeException('That file does not look like a .' . $ext . ' (detected ' . $mime . ').');
  }

  $storeName = safe_store_name($f['name']);
  $abs = materials_dir_abs() . DIRECTORY_SEPARATOR . $storeName;
  if (!move_uploaded_file($f['tmp_name'], $abs)) throw new RuntimeException('Failed to save the file.');
  $stored = '/uploads/materials/' . $storeName;
  storage_offload($abs, $stored); // to the bucket; stays local if that fails
  return $stored;
}

$errors = []; $success = null;

$subjects = [];
try { $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name ASC")->fetchAll(); }
catch (Throwable $e) { /* ignore */ }

/** Shared field reading for create and update. */
function read_material_fields(array $src, string $p = ''): array {
  global $TYPES;
  $type = $src[$p.'material_type'] ?? 'Other';
  if (!in_array($type, $TYPES, true)) $type = 'Other';
  $grade = (int)($src[$p.'grade_level'] ?? 0);
  // grade_level is TINYINT UNSIGNED; anything over 255 is an out-of-range error under
  // production's STRICT_TRANS_TABLES, and the old code only clamped the low end.
  if ($grade < 0) $grade = 0;
  if ($grade > MAX_GRADE) throw new RuntimeException('Grade must be between 0 and ' . MAX_GRADE . '.');
  $title = trim($src[$p.'title'] ?? '');
  if ($title === '') throw new RuntimeException('Title is required.');
  $curriculum = trim($src[$p.'curriculum'] ?? '') ?: 'General';
  return [
    'title'       => $title,
    'description' => trim($src[$p.'description'] ?? '') ?: null,
    'type'        => $type,
    'curriculum'  => $curriculum,
    'grade'       => $grade,
    'subject_id'  => ((int)($src[$p.'subject_id'] ?? 0)) ?: null,
    'tags'        => trim($src[$p.'tags'] ?? '') ?: null,
    'published'   => isset($src[$p.'is_published']) ? 1 : 0,
  ];
}

// ---------- CREATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  try {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) throw new RuntimeException('Invalid session token. Please refresh.');
    $F = read_material_fields($_POST);
    if ($F['subject_id']) {
      $chk = $pdo->prepare("SELECT id FROM subjects WHERE id=?");
      $chk->execute([$F['subject_id']]);
      if (!$chk->fetchColumn()) throw new RuntimeException('That subject no longer exists.');
    }

    $file_url = null; $external_url = null;
    if (($_POST['source'] ?? 'file') === 'link') {
      $external_url = trim($_POST['external_url'] ?? '');
      if (!filter_var($external_url, FILTER_VALIDATE_URL)) throw new RuntimeException('Please provide a valid URL.');
    } else {
      $file_url = accept_upload($_FILES['file'] ?? null, $F['type']);
    }

    $pdo->prepare("INSERT INTO learning_materials
        (title, description, material_type, curriculum, grade_level, subject_id,
         file_url, external_url, tags, is_published, created_by_admin_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$F['title'], $F['description'], $F['type'], $F['curriculum'], $F['grade'],
                   $F['subject_id'], $file_url, $external_url, $F['tags'], $F['published'], $user_id]);
    $success = 'Material added.';
  } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

// ---------- UPDATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  try {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) throw new RuntimeException('Invalid session token. Please refresh.');
    $id = (int)($_POST['material_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Invalid material.');

    $row = $pdo->prepare("SELECT * FROM learning_materials WHERE id=?");
    $row->execute([$id]);
    $cur = $row->fetch();
    if (!$cur) throw new RuntimeException('Material not found.');

    $F = read_material_fields($_POST, 'ed_');
    if ($F['subject_id']) {
      $chk = $pdo->prepare("SELECT id FROM subjects WHERE id=?");
      $chk->execute([$F['subject_id']]);
      if (!$chk->fetchColumn()) throw new RuntimeException('That subject no longer exists.');
    }

    $mode        = $_POST['ed_source_mode'] ?? 'keep'; // keep | file | link
    $newFileUrl  = $cur['file_url'] ?? null;
    $newExtUrl   = $cur['external_url'] ?? null;
    $obsoleteUrl = null; // binned only after the row is safely updated

    if ($mode === 'file') {
      $newFileUrl  = accept_upload($_FILES['ed_file'] ?? null, $F['type']);
      $obsoleteUrl = $cur['file_url'] ?? null;
      $newExtUrl   = null;
    } elseif ($mode === 'link') {
      $link = trim($_POST['ed_external_url'] ?? '');
      if (!filter_var($link, FILTER_VALIDATE_URL)) throw new RuntimeException('Provide a valid URL.');
      $obsoleteUrl = $cur['file_url'] ?? null;
      $newFileUrl  = null;
      $newExtUrl   = $link;
    }

    $pdo->prepare("UPDATE learning_materials
        SET title=?, description=?, material_type=?, curriculum=?, grade_level=?, subject_id=?,
            file_url=?, external_url=?, tags=?, is_published=?
        WHERE id=?")
        ->execute([$F['title'], $F['description'], $F['type'], $F['curriculum'], $F['grade'],
                   $F['subject_id'], $newFileUrl, $newExtUrl, $F['tags'], $F['published'], $id]);

    // The old code deleted the previous file *before* running this UPDATE, so a
    // failed update left the row pointing at a file that no longer existed.
    if ($obsoleteUrl && $obsoleteUrl !== $newFileUrl) safe_unlink_if_owned($obsoleteUrl);
    $success = 'Material updated.';
  } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

// ---------- DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  try {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) throw new RuntimeException('Invalid session token. Please refresh.');
    $id = (int)($_POST['material_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Invalid material.');
    $st = $pdo->prepare("SELECT title, file_url FROM learning_materials WHERE id=?");
    $st->execute([$id]);
    $cur = $st->fetch();
    if (!$cur) throw new RuntimeException('Material not found.');

    // Row first, file second — the reverse order orphaned the row when the delete failed.
    $pdo->prepare("DELETE FROM learning_materials WHERE id=?")->execute([$id]);
    safe_unlink_if_owned($cur['file_url'] ?? null);
    $success = 'Deleted “' . $cur['title'] . '”.';
  } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

// ---------- Filters & list ----------
$q         = trim($_GET['q'] ?? '');
$f_type    = in_array($_GET['type'] ?? '', $TYPES, true) ? $_GET['type'] : '';
$f_curr    = trim($_GET['curriculum'] ?? '');
$f_grade   = ($_GET['grade'] ?? '') !== '' ? (int)$_GET['grade'] : '';
$f_subject = ($_GET['subject_id'] ?? '') !== '' ? (int)$_GET['subject_id'] : '';
$f_source  = in_array($_GET['source'] ?? '', ['file','link'], true) ? $_GET['source'] : '';
$f_pub     = in_array($_GET['pub'] ?? '', ['1','0'], true) ? $_GET['pub'] : '';
$page      = max(1, (int)($_GET['p'] ?? 1));

$where = []; $args = [];
if ($q !== '') {
  $where[] = "(lm.title LIKE ? OR lm.description LIKE ? OR lm.tags LIKE ?)";
  array_push($args, "%$q%", "%$q%", "%$q%");
}
if ($f_type !== '')    { $where[] = "lm.material_type = ?"; $args[] = $f_type; }
if ($f_curr !== '')    { $where[] = "lm.curriculum = ?";    $args[] = $f_curr; }
if ($f_grade !== '')   { $where[] = "lm.grade_level = ?";   $args[] = $f_grade; }
if ($f_subject !== '') { $where[] = "lm.subject_id = ?";    $args[] = $f_subject; }
if ($f_source === 'file') $where[] = "lm.file_url IS NOT NULL";
if ($f_source === 'link') $where[] = "lm.external_url IS NOT NULL";
if ($f_pub !== '')     { $where[] = "lm.is_published = ?";  $args[] = (int)$f_pub; }
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM learning_materials lm $whereSql");
$cnt->execute($args);
$total       = (int)$cnt->fetchColumn();
$total_pages = max(1, (int)ceil($total / PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * PER_PAGE;
$limit       = PER_PAGE;

// The old query had a bare LIMIT 200 and no count, so anything past 200 silently
// vanished with nothing on screen to say so.
$st = $pdo->prepare("
  SELECT lm.*, s.name AS subject_name, CONCAT(u.first_name,' ',u.last_name) AS admin_name
  FROM learning_materials lm
  LEFT JOIN subjects s ON s.id = lm.subject_id
  LEFT JOIN users u ON u.id = lm.created_by_admin_id
  $whereSql
  ORDER BY lm.created_at DESC
  LIMIT $limit OFFSET $offset");
$st->execute($args);
$rows = $st->fetchAll();

// The curriculum filter used to be a hardcoded list — General, Cambridge, Edexcel,
// IB, MSCE, ZIMSEC, Other — which does not include GCSE, a curriculum that actually
// exists here. Build it from the data instead.
$curr_list = $pdo->query("
  SELECT DISTINCT curriculum FROM (
    SELECT curriculum FROM learning_materials
    UNION SELECT curriculum FROM subjects
  ) x WHERE curriculum <> '' ORDER BY curriculum")->fetchAll(PDO::FETCH_COLUMN);

$stat_total = (int)$pdo->query("SELECT COUNT(*) FROM learning_materials")->fetchColumn();
$stat_unpub = (int)$pdo->query("SELECT COUNT(*) FROM learning_materials WHERE is_published = 0")->fetchColumn();
$stat_nosub = (int)$pdo->query("SELECT COUNT(*) FROM learning_materials WHERE subject_id IS NULL")->fetchColumn();

function qs(array $over = []): string {
  $base = [
    'q' => $GLOBALS['q'] ?: null, 'type' => $GLOBALS['f_type'] ?: null,
    'curriculum' => $GLOBALS['f_curr'] ?: null,
    'grade' => $GLOBALS['f_grade'] !== '' ? $GLOBALS['f_grade'] : null,
    'subject_id' => $GLOBALS['f_subject'] !== '' ? $GLOBALS['f_subject'] : null,
    'source' => $GLOBALS['f_source'] ?: null, 'pub' => $GLOBALS['f_pub'] !== '' ? $GLOBALS['f_pub'] : null,
  ];
  foreach ($over as $k => $v) $base[$k] = $v;
  return '?' . http_build_query(array_filter($base, fn($v) => $v !== null && $v !== ''));
}

$TYPE_BADGE = [
  'Book'      => 'bg-indigo-500/15 text-indigo-300',
  'PastPaper' => 'bg-amber-500/15 text-amber-300',
  'PPT'       => 'bg-orange-500/15 text-orange-300',
  'Video'     => 'bg-rose-500/15 text-rose-300',
  'Worksheet' => 'bg-emerald-500/15 text-emerald-300',
  'Audio'     => 'bg-violet-500/15 text-violet-300',
  'Other'     => 'bg-gray-700 text-gray-300',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin — Teaching Resources</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* Matches the rest of the admin area — this page used to run its own slate
       palette, its own .panel class and an <h1>, and read like a different app. */
    body{font-family:'Inter',sans-serif;background:#111827;color:#d1d5db}
    ::-webkit-scrollbar{width:8px;height:8px} ::-webkit-scrollbar-track{background:#1f2937} ::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.55rem .8rem;width:100%;font-size:.875rem}
    .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.25)}
    .card{background:#0f1623;border:1px solid rgba(255,255,255,.06);border-radius:.75rem}
    .badge{font-size:11px;padding:.2rem .5rem;border-radius:9999px;font-weight:600}
  </style>
</head>
<body class="flex min-h-screen">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-5 lg:p-7 min-w-0">
    <header class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div>
        <h2 class="text-2xl font-bold text-white">Teaching Resources</h2>
        <p class="text-gray-400 text-sm mt-0.5">
          <?php echo number_format($stat_total); ?> resources
          <?php if ($stat_unpub): ?> &middot; <a class="text-amber-400 hover:text-amber-300" href="<?php echo h(qs(['pub'=>'0','p'=>null])); ?>"><?php echo $stat_unpub; ?> unpublished</a><?php endif; ?>
          <?php if ($stat_nosub): ?> &middot; <span class="text-gray-500"><?php echo $stat_nosub; ?> with no subject</span><?php endif; ?>
        </p>
      </div>
      <button id="addBtn" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg text-sm">Add resource</button>
    </header>

    <?php if ($success || $errors): ?>
      <div class="mb-5 space-y-2">
        <?php if ($success): ?><div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($success); ?></div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($e); ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="get" class="card p-3 mb-5">
      <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2">
        <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Title, description or tag…" class="form-input col-span-2">
        <select name="type" class="form-input">
          <option value="">All types</option>
          <?php foreach ($TYPES as $t): ?><option value="<?php echo h($t); ?>" <?php echo $f_type===$t?'selected':''; ?>><?php echo h($t); ?></option><?php endforeach; ?>
        </select>
        <select name="curriculum" class="form-input">
          <option value="">Any curriculum</option>
          <?php foreach ($curr_list as $c): ?><option value="<?php echo h($c); ?>" <?php echo $f_curr===$c?'selected':''; ?>><?php echo h($c); ?></option><?php endforeach; ?>
        </select>
        <select name="subject_id" class="form-input">
          <option value="">Any subject</option>
          <?php foreach ($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $f_subject===(int)$s['id']?'selected':''; ?>><?php echo h($s['name']); ?></option><?php endforeach; ?>
        </select>
        <select name="source" class="form-input">
          <option value="">File or link</option>
          <option value="file" <?php echo $f_source==='file'?'selected':''; ?>>Uploaded files</option>
          <option value="link" <?php echo $f_source==='link'?'selected':''; ?>>External links</option>
        </select>
        <select name="pub" class="form-input">
          <option value="">Any status</option>
          <option value="1" <?php echo $f_pub==='1'?'selected':''; ?>>Published</option>
          <option value="0" <?php echo $f_pub==='0'?'selected':''; ?>>Unpublished</option>
        </select>
        <div class="col-span-2 md:col-span-4 xl:col-span-7 flex flex-wrap items-center justify-between gap-2 pt-1">
          <input type="number" name="grade" min="0" max="<?php echo MAX_GRADE; ?>" value="<?php echo $f_grade !== '' ? (int)$f_grade : ''; ?>" placeholder="Grade" class="form-input w-28">
          <div class="flex gap-2">
            <a href="materials.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm">Reset</a>
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">Filter</button>
          </div>
        </div>
      </div>
    </form>

    <?php if (empty($rows)): ?>
      <div class="card p-12 text-center">
        <p class="text-gray-400 text-sm"><?php echo $total === 0 && $stat_total === 0 ? 'No resources yet.' : 'No resources match those filters.'; ?></p>
      </div>
    <?php else: ?>
      <p class="text-xs text-gray-500 mb-2">
        Showing <?php echo number_format($offset + 1); ?>&ndash;<?php echo number_format(min($offset + PER_PAGE, $total)); ?> of <?php echo number_format($total); ?>
      </p>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        <?php foreach ($rows as $r):
          $isFile = !empty($r['file_url']);
          $url    = material_open_url($r);
          $badge  = $TYPE_BADGE[$r['material_type']] ?? $TYPE_BADGE['Other'];
        ?>
          <div class="card p-4 flex flex-col">
            <div class="flex items-start justify-between gap-2 mb-2">
              <span class="badge <?php echo $badge; ?>"><?php echo h($r['material_type']); ?></span>
              <?php if (!(int)$r['is_published']): ?>
                <span class="badge bg-amber-500/15 text-amber-300">Unpublished</span>
              <?php endif; ?>
            </div>
            <p class="font-semibold text-white text-sm leading-snug"><?php echo h($r['title']); ?></p>
            <?php if (!empty($r['description'])): ?>
              <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?php echo h($r['description']); ?></p>
            <?php endif; ?>
            <p class="text-[11px] text-gray-500 mt-2">
              <?php echo h($r['subject_name'] ?: 'No subject'); ?>
              &middot; <?php echo h($r['curriculum']); ?>
              <?php if ((int)$r['grade_level']): ?> &middot; Grade <?php echo (int)$r['grade_level']; ?><?php endif; ?>
            </p>
            <?php if (!empty($r['tags'])): ?>
              <p class="text-[11px] text-gray-600 mt-1 truncate"><?php echo h($r['tags']); ?></p>
            <?php endif; ?>
            <p class="text-[10px] text-gray-600 mt-1">
              <?php echo $isFile ? 'Uploaded file' : 'External link'; ?>
              <?php if ($r['admin_name']): ?> &middot; by <?php echo h($r['admin_name']); ?><?php endif; ?>
            </p>

            <div class="flex flex-wrap items-center gap-1.5 mt-3 pt-3 border-t border-gray-800">
              <?php if ($url): ?>
                <a href="<?php echo h($url); ?>" target="_blank" rel="noopener noreferrer"
                   class="text-xs font-semibold bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20 py-1.5 px-3 rounded-lg">Open</a>
              <?php else: ?>
                <span class="text-xs text-gray-600 py-1.5 px-3">No file or link</span>
              <?php endif; ?>
              <button type="button" class="edit-btn text-xs font-semibold bg-gray-700/60 hover:bg-gray-600 text-gray-200 py-1.5 px-3 rounded-lg"
                data-id="<?php echo (int)$r['id']; ?>"
                data-title="<?php echo h($r['title']); ?>"
                data-description="<?php echo h($r['description'] ?? ''); ?>"
                data-type="<?php echo h($r['material_type']); ?>"
                data-curriculum="<?php echo h($r['curriculum']); ?>"
                data-grade="<?php echo (int)$r['grade_level']; ?>"
                data-subject="<?php echo (int)($r['subject_id'] ?? 0); ?>"
                data-tags="<?php echo h($r['tags'] ?? ''); ?>"
                data-published="<?php echo (int)$r['is_published']; ?>"
                data-isfile="<?php echo $isFile ? 1 : 0; ?>"
                data-external="<?php echo h($r['external_url'] ?? ''); ?>">Edit</button>
              <form method="post" class="js-confirm inline" data-confirm="<?php echo h('Delete “' . $r['title'] . '”?' . ($isFile ? "\n\nThe uploaded file is removed too." : '')); ?>">
                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="material_id" value="<?php echo (int)$r['id']; ?>">
                <button class="text-xs font-semibold bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 py-1.5 px-3 rounded-lg">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between mt-4 text-sm">
          <div><?php if ($page > 1): ?><a class="px-3 py-1.5 bg-gray-700 rounded-md hover:bg-gray-600" href="<?php echo h(qs(['p'=>$page-1])); ?>">Prev</a><?php endif; ?></div>
          <div class="text-gray-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
          <div><?php if ($page < $total_pages): ?><a class="px-3 py-1.5 bg-gray-700 rounded-md hover:bg-gray-600" href="<?php echo h(qs(['p'=>$page+1])); ?>">Next</a><?php endif; ?></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <!-- Add / Edit modal -->
  <div id="matModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/70" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-5">
        <h3 id="matTitle" class="text-lg font-semibold text-white">Add resource</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
      </div>
      <form method="post" enctype="multipart/form-data" class="space-y-4">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" id="m_action" value="create">
        <input type="hidden" name="material_id" id="m_id" value="">
        <div><label class="block text-sm text-gray-300 mb-1.5">Title *</label><input name="title" id="m_title" class="form-input" required></div>
        <div><label class="block text-sm text-gray-300 mb-1.5">Description</label><textarea name="description" id="m_description" rows="2" class="form-input"></textarea></div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Type</label>
            <select name="material_type" id="m_type" class="form-input">
              <?php foreach ($TYPES as $t): ?><option value="<?php echo h($t); ?>"><?php echo h($t); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Curriculum</label>
            <input name="curriculum" id="m_curriculum" class="form-input" list="currList" placeholder="General">
            <datalist id="currList"><?php foreach ($curr_list as $c): ?><option value="<?php echo h($c); ?>"><?php endforeach; ?></datalist></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Grade</label>
            <input type="number" name="grade_level" id="m_grade" class="form-input" min="0" max="<?php echo MAX_GRADE; ?>" value="0"></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Subject</label>
            <select name="subject_id" id="m_subject" class="form-input">
              <option value="">No subject</option>
              <?php foreach ($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['name']); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Tags</label><input name="tags" id="m_tags" class="form-input" placeholder="comma, separated"></div>
        </div>

        <div class="border border-gray-700 rounded-lg p-3 space-y-3">
          <div class="flex flex-wrap gap-4 text-sm text-gray-300">
            <label class="inline-flex items-center gap-2"><input type="radio" name="source" value="file" id="src_file" checked> Upload a file</label>
            <label class="inline-flex items-center gap-2"><input type="radio" name="source" value="link" id="src_link"> External link</label>
            <label class="inline-flex items-center gap-2 hidden" id="src_keep_wrap"><input type="radio" name="source" value="keep" id="src_keep"> Keep what is there</label>
          </div>
          <div id="fileRow">
            <input type="file" name="file" id="m_file" class="form-input !py-1.5">
            <p class="text-xs text-gray-500 mt-1">Max 200MB. Allowed formats depend on the type. Files are served through the authenticated gateway, not a public path.</p>
          </div>
          <div id="linkRow" class="hidden">
            <input type="url" name="external_url" id="m_external" class="form-input" placeholder="https://…">
          </div>
        </div>

        <label class="inline-flex items-center gap-2 text-sm text-gray-300">
          <input type="checkbox" name="is_published" id="m_published" class="accent-indigo-500" checked> Published
        </label>

        <div class="flex justify-end gap-2 pt-3 border-t border-gray-700">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-5 py-2.5 rounded-lg">Cancel</button>
          <button id="m_submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-5 py-2.5 rounded-lg font-semibold">Add resource</button>
        </div>
      </form>
    </div>
  </div>

<script>
(function () {
  const modal = document.getElementById('matModal');
  const open  = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
  const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
  document.querySelectorAll('[data-close-modal]').forEach(el => el.addEventListener('click', close));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
  const fileRow = document.getElementById('fileRow');
  const linkRow = document.getElementById('linkRow');
  const keepWrap = document.getElementById('src_keep_wrap');

  const syncSource = () => {
    const v = (document.querySelector('input[name=source]:checked') || {}).value;
    fileRow.classList.toggle('hidden', v !== 'file');
    linkRow.classList.toggle('hidden', v !== 'link');
    // Only require a file when uploading is actually the chosen mode.
    document.getElementById('m_file').required = (v === 'file' && document.getElementById('m_action').value === 'create');
    document.getElementById('m_external').required = (v === 'link');
  };
  document.querySelectorAll('input[name=source]').forEach(r => r.addEventListener('change', syncSource));

  document.getElementById('addBtn').addEventListener('click', () => {
    document.getElementById('matTitle').textContent = 'Add resource';
    document.getElementById('m_action').value = 'create';
    document.getElementById('m_submit').textContent = 'Add resource';
    ['m_id','m_title','m_description','m_curriculum','m_tags','m_external'].forEach(i => set(i, ''));
    set('m_grade', '0'); set('m_type', 'Book'); set('m_subject', '');
    document.getElementById('m_published').checked = true;
    keepWrap.classList.add('hidden');
    document.getElementById('src_file').checked = true;
    syncSource();
    open();
  });

  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const d = btn.dataset;
      document.getElementById('matTitle').textContent = 'Edit resource';
      document.getElementById('m_action').value = 'update';
      document.getElementById('m_submit').textContent = 'Save changes';
      set('m_id', d.id); set('m_title', d.title); set('m_description', d.description);
      set('m_type', d.type); set('m_curriculum', d.curriculum); set('m_grade', d.grade);
      set('m_subject', d.subject === '0' ? '' : d.subject); set('m_tags', d.tags);
      set('m_external', d.external);
      document.getElementById('m_published').checked = (d.published === '1');
      keepWrap.classList.remove('hidden');
      document.getElementById('src_keep').checked = true;
      syncSource();
      open();
    });
  });

  // The update handler reads ed_-prefixed fields; rename on submit so one modal can
  // serve both actions without duplicating the whole form.
  modal.querySelector('form').addEventListener('submit', () => {
    const isUpdate = document.getElementById('m_action').value === 'update';
    const map = {
      m_title:'title', m_description:'description', m_type:'material_type', m_curriculum:'curriculum',
      m_grade:'grade_level', m_subject:'subject_id', m_tags:'tags', m_published:'is_published',
      m_file:'file', m_external:'external_url'
    };
    Object.entries(map).forEach(([id, base]) => {
      const el = document.getElementById(id);
      if (el) el.name = isUpdate ? ('ed_' + base) : base;
    });
    const src = document.querySelector('input[name=source]:checked, input[name=ed_source_mode]:checked');
    document.querySelectorAll('input[name=source], input[name=ed_source_mode]').forEach(r => {
      r.name = isUpdate ? 'ed_source_mode' : 'source';
    });
    if (src) src.checked = true;
  });

  document.querySelectorAll('form.js-confirm').forEach(f => {
    f.addEventListener('submit', e => {
      if (!window.confirm(f.dataset.confirm || 'Are you sure?')) e.preventDefault();
    });
  });
})();
</script>
</body>
</html>
