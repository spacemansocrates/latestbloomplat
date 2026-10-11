<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// /admin/admin_banners.php

// 1) Session + Auth
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }
$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

date_default_timezone_set('Africa/Blantyre');
$current_page = 'banners';

// 2) DB (same style as your admin_dashboard.php)
$db_host = DB_HOST;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass = DB_PASSWORD;
try {
  $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  die("DB error: " . $e->getMessage());
}

// 3) Authorize as Admin (role OR in admin_details)
$is_admin = false;
if ($user_role === 'Administrator') {
  $is_admin = true;
} else {
  $stmt = $pdo->prepare("SELECT admin_role FROM admin_details WHERE user_id=?");
  $stmt->execute([$user_id]);
  $row = $stmt->fetch();
  if ($row && in_array($row['admin_role'], ['Super Admin','Admin','Academic Coordinator'], true)) {
    $is_admin = true;
  }
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

// 4) Bootstrap for uploads + helpers
require_once __DIR__ . '/../app_bootstrap.php';

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function post($k,$d=null){ return $_POST[$k] ?? $d; }
function to_datetime_or_null($s){
  // Accepts HTML5 datetime-local (YYYY-MM-DDTHH:MM)
  if (!$s) return null;
  $s = str_replace('T',' ',$s);
  $ts = strtotime($s);
  return $ts ? date('Y-m-d H:i:s',$ts) : null;
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// 5) Routing (list/create/edit/toggle/delete)
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$notice = '';
$error  = '';

try {
  // Toggle active
  if ($_SERVER['REQUEST_METHOD']==='POST' && post('act')==='toggle' && hash_equals($csrf, post('csrf'))) {
    $id = (int)post('id');
    $to = (int)post('to');
    $st = $pdo->prepare("UPDATE ui_banners SET is_active=?, updated_by_admin_id=?, updated_at=NOW() WHERE id=?");
    $st->execute([$to ? 1 : 0, $user_id, $id]);
    $notice = 'Banner updated.';
  }

  // Delete
  if ($_SERVER['REQUEST_METHOD']==='POST' && post('act')==='delete' && hash_equals($csrf, post('csrf'))) {
    $id = (int)post('id');
    $st = $pdo->prepare("DELETE FROM ui_banners WHERE id=?");
    $st->execute([$id]);
    $notice = 'Banner deleted.';
  }

  // Create / Update
  if ($_SERVER['REQUEST_METHOD']==='POST' && post('act')==='save' && hash_equals($csrf, post('csrf'))) {
    $bid       = (int)post('id',0);
    $page_key  = trim((string)post('page_key','students_dashboard'));
    $audience  = post('audience','Students');
    $title     = trim((string)post('title',''));
    $message   = trim((string)post('message',''));
    $alt_text  = trim((string)post('alt_text',''));
    $cta_label = trim((string)post('cta_label',''));
    $cta_url   = trim((string)post('cta_url',''));
    $is_active = (int)!!post('is_active',0);
    $starts_at = to_datetime_or_null(post('starts_at',''));
    $ends_at   = to_datetime_or_null(post('ends_at',''));

    if ($title === '') throw new RuntimeException('Title is required');

    // Handle image upload if provided
    $relativePath = null;
    if (!empty($_FILES['image']['name'])) {
      $file = $_FILES['image'];
      if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed');

      if ($file['size'] > 6*1024*1024) throw new RuntimeException('Max 6MB');

      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime  = $finfo->file($file['tmp_name']);
      $ok    = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
      if (!isset($ok[$mime])) throw new RuntimeException('Unsupported image type');

      $ext  = $ok[$mime];
      $name = 'banner_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;

      $targetDir = UPLOADS_DIR . '/banners';
      if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

      $dest = $targetDir . '/' . $name;
      if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Failed to save file');
      $relativePath = 'banners/' . $name;
    }

    if ($bid > 0) {
      // Update
      if ($relativePath) {
        $sql = "UPDATE ui_banners
                SET page_key=?, audience=?, title=?, message=?, image_url=?, alt_text=?, cta_label=?, cta_url=?, is_active=?, starts_at=?, ends_at=?, updated_by_admin_id=?, updated_at=NOW()
                WHERE id=?";
        $args = [$page_key,$audience,$title,$message,$relativePath,$alt_text,$cta_label,$cta_url,$is_active,$starts_at,$ends_at,$user_id,$bid];
      } else {
        $sql = "UPDATE ui_banners
                SET page_key=?, audience=?, title=?, message=?, alt_text=?, cta_label=?, cta_url=?, is_active=?, starts_at=?, ends_at=?, updated_by_admin_id=?, updated_at=NOW()
                WHERE id=?";
        $args = [$page_key,$audience,$title,$message,$alt_text,$cta_label,$cta_url,$is_active,$starts_at,$ends_at,$user_id,$bid];
      }
      $st = $pdo->prepare($sql);
      $st->execute($args);
      $notice = 'Banner saved.';
      $action = 'edit';
      $id = $bid;
    } else {
      // Insert
      $sql = "INSERT INTO ui_banners
              (page_key,audience,title,message,image_url,alt_text,cta_label,cta_url,is_active,starts_at,ends_at,updated_by_admin_id,updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())";
      $st = $pdo->prepare($sql);
      $st->execute([$page_key,$audience,$title,$message,$relativePath,$alt_text,$cta_label,$cta_url,$is_active,$starts_at,$ends_at,$user_id]);
      $notice = 'Banner created.';
      $action = 'edit';
      $id = (int)$pdo->lastInsertId();
    }
  }
} catch (Throwable $e) {
  $error = $e->getMessage();
}

// Data for screen
$page_filter = $_GET['page_key'] ?? 'students_dashboard';

if ($action === 'edit') {
  $stmt = $pdo->prepare("SELECT * FROM ui_banners WHERE id=?");
  $stmt->execute([$id]);
  $editing = $stmt->fetch();
  if (!$editing && $id) { $error = 'Banner not found'; $action=''; }
}

// List banners (filtered)
$stmt = $pdo->prepare("
  SELECT *
  FROM ui_banners
  WHERE (? = '' OR page_key = ?)
  ORDER BY updated_at DESC, id DESC
  LIMIT 100
");
$stmt->execute([$page_filter, $page_filter]);
$banners = $stmt->fetchAll();

// For selects
$page_keys = [
  'students_dashboard' => 'Students Dashboard',
  'tutors_dashboard'   => 'Tutors Dashboard',
  'admin_dashboard'    => 'Admin Dashboard',
  // Add more page keys later as you add banners elsewhere
];
$audiences = ['All','Students','Tutors'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Banners | Admin</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background:#111827; color:#d1d5db; }
    ::-webkit-scrollbar{width:8px}
    ::-webkit-scrollbar-track{background:#1f2937}
    ::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .badge { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:4px 8px; border-radius:9999px }
    input, select, textarea { background:#1f2937; border:1px solid #374151; color:#fff; border-radius:0.5rem; padding:0.625rem 0.75rem; width:100%; }
    label { font-size:0.825rem; color:#9ca3af; }
  </style>
</head>
<body class="flex min-h-screen">
  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-6 lg:p-8">
    <header class="flex flex-wrap items-center justify-between gap-4 mb-6">
      <div>
        <h2 class="text-2xl font-bold text-white">UI Banners</h2>
        <p class="text-gray-400">Create and manage banners shown across pages.</p>
      </div>
      <div class="flex items-center gap-3">
        <a href="?action=edit" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg inline-flex items-center gap-2">
          <svg class="w-4 h-4" data-lucide="plus" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-plus"></use></svg> New Banner
        </a>
      </div>
    </header>

    <?php if ($notice): ?>
      <div class="mb-4 p-3 rounded-lg bg-emerald-500/10 text-emerald-300 border border-emerald-500/20"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg bg-rose-500/10 text-rose-300 border border-rose-500/20"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Filter -->
    <form method="get" class="mb-6">
      <div class="flex items-end gap-3">
        <div>
          <label class="block mb-1">Page</label>
          <select name="page_key">
            <option value="">All Pages</option>
            <?php foreach ($page_keys as $k=>$label): ?>
              <option value="<?= h($k) ?>" <?= $page_filter===$k?'selected':''?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="bg-gray-700 hover:bg-gray-600 text-white font-semibold py-2.5 px-5 rounded-lg">Apply</button>
      </div>
    </form>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
      <!-- Left: List -->
      <div class="xl:col-span-2 space-y-3">
        <?php if (empty($banners)): ?>
          <div class="bg-gray-900 p-6 rounded-lg">No banners yet.</div>
        <?php else: foreach ($banners as $b):
          $img = $b['image_url'] ? uploads_url($b['image_url']) : '';
          $activeBadge = $b['is_active'] ? 'bg-emerald-500/10 text-emerald-300' : 'bg-gray-600/20 text-gray-300';
          $scheduleStr = '';
          if (!empty($b['starts_at']) || !empty($b['ends_at'])) {
            $scheduleStr = trim(($b['starts_at'] ? (new DateTime($b['starts_at']))->format('M j, Y g:i A') : '—') . ' → ' . ($b['ends_at'] ? (new DateTime($b['ends_at']))->format('M j, Y g:i A') : '—'));
          }
        ?>
          <div class="bg-gray-900 p-4 rounded-lg border border-gray-800">
            <div class="flex items-start gap-4">
              <div class="w-28 h-20 rounded-lg bg-gray-800 overflow-hidden flex-shrink-0 border border-gray-700">
                <?php if ($img): ?>
                  <img src="<?= h($img) ?>" class="w-full h-full object-cover" alt="">
                <?php else: ?>
                  <div class="w-full h-full grid place-items-center text-gray-500 text-xs">No image</div>
                <?php endif; ?>
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-xs px-2 py-0.5 rounded bg-indigo-500/10 text-indigo-300"><?= h($b['page_key']) ?></span>
                  <span class="text-xs px-2 py-0.5 rounded bg-fuchsia-500/10 text-fuchsia-300"><?= h($b['audience']) ?></span>
                  <span class="text-xs px-2 py-0.5 rounded <?= $activeBadge ?>"><?= $b['is_active'] ? 'Active' : 'Inactive' ?></span>
                </div>
                <p class="text-white font-semibold mt-1 truncate"><?= h($b['title']) ?></p>
                <?php if ($b['message']): ?>
                  <p class="text-sm text-gray-400 line-clamp-2"><?= nl2br(h($b['message'])) ?></p>
                <?php endif; ?>
                <?php if ($scheduleStr): ?>
                  <p class="text-xs text-gray-500 mt-1"><?= h($scheduleStr) ?></p>
                <?php endif; ?>
              </div>
              <div class="text-right space-y-2">
                <a href="?action=edit&id=<?= (int)$b['id'] ?>" class="text-indigo-400 hover:text-indigo-300 text-sm font-semibold inline-flex items-center gap-1"><svg class="w-4 h-4" data-lucide="pencil" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-pencil"></use></svg>Edit</a>
                <form method="post" onsubmit="return confirm('Delete this banner?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="act" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button class="text-rose-400 hover:text-rose-300 text-sm font-semibold inline-flex items-center gap-1" type="submit"><svg class="w-4 h-4" data-lucide="trash-2" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-trash-2"></use></svg>Delete</button>
                </form>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="act" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <input type="hidden" name="to" value="<?= $b['is_active']?0:1 ?>">
                  <button class="text-sm font-semibold inline-flex items-center gap-1 <?= $b['is_active']?'text-gray-300 hover:text-gray-200':'text-emerald-400 hover:text-emerald-300' ?>" type="submit">
                    <svg class="w-4 h-4" data-lucide="<?= $b['is_active']?'pause-circle':'play-circle' ?>" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-<?= $b['is_active']?'pause-circle':'play-circle' ?>"></use></svg>
                    <?= $b['is_active']?'Deactivate':'Activate' ?>
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Right: Create/Edit form -->
      <div class="xl:col-span-1">
        <div class="bg-gray-900 p-6 rounded-lg border border-gray-800">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-white"><?= $action==='edit' ? 'Edit Banner' : 'New Banner' ?></h3>
          </div>
          <form method="post" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="act" value="save">
            <input type="hidden" name="id" value="<?= $editing['id'] ?? 0 ?>">

            <div>
              <label class="block mb-1">Page</label>
              <select name="page_key">
                <?php foreach ($page_keys as $k=>$label): ?>
                  <option value="<?= h($k) ?>" <?= ($editing['page_key'] ?? 'students_dashboard')===$k ? 'selected':''; ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block mb-1">Audience</label>
              <select name="audience">
                <?php foreach ($audiences as $a): ?>
                  <option value="<?= h($a) ?>" <?= ($editing['audience'] ?? 'Students')===$a ? 'selected':''; ?>><?= h($a) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block mb-1">Title *</label>
              <input type="text" name="title" value="<?= h($editing['title'] ?? '') ?>" required>
            </div>

            <div>
              <label class="block mb-1">Message</label>
              <textarea name="message" rows="4" placeholder="Optional short copy..."><?= h($editing['message'] ?? '') ?></textarea>
            </div>

            <div>
              <label class="block mb-1">CTA Label</label>
              <input type="text" name="cta_label" value="<?= h($editing['cta_label'] ?? '') ?>" placeholder="e.g., View Packages">
            </div>

            <div>
              <label class="block mb-1">CTA URL</label>
              <input type="text" name="cta_url" value="<?= h($editing['cta_url'] ?? '') ?>" placeholder="/students/packages.php">
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block mb-1">Starts At</label>
                <input type="datetime-local" name="starts_at" value="<?= !empty($editing['starts_at']) ? date('Y-m-d\TH:i', strtotime($editing['starts_at'])) : '' ?>">
              </div>
              <div>
                <label class="block mb-1">Ends At</label>
                <input type="datetime-local" name="ends_at" value="<?= !empty($editing['ends_at']) ? date('Y-m-d\TH:i', strtotime($editing['ends_at'])) : '' ?>">
              </div>
            </div>

            <div class="flex items-center gap-2">
              <input id="is_active" type="checkbox" name="is_active" value="1" <?= !empty($editing['is_active']) ? 'checked':''; ?> style="width:auto">
              <label for="is_active">Active</label>
            </div>

            <div>
              <label class="block mb-1">Banner Image (JPEG/PNG/WebP/GIF, ≤ 6MB)</label>
              <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif">
              <?php if (!empty($editing['image_url'])): ?>
                <p class="text-xs text-gray-400 mt-1">Current: <?= h($editing['image_url']) ?></p>
                <?php $preview = uploads_url($editing['image_url']); if ($preview): ?>
                  <img src="<?= h($preview) ?>" class="mt-2 w-full h-32 object-cover rounded-lg border border-gray-700">
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <div>
              <label class="block mb-1">Alt Text (Accessibility)</label>
              <input type="text" name="alt_text" value="<?= h($editing['alt_text'] ?? '') ?>">
            </div>

            <div class="pt-2 flex items-center gap-3">
              <button class="bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-2.5 px-5 rounded-lg" type="submit">
                Save Banner
              </button>
              <?php if ($action==='edit' && !empty($editing['id'])): ?>
                <a class="text-gray-300 hover:text-white" href="?">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    </div>
  </main>

  <script>  </script>
</body>
</html>
