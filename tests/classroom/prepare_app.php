<?php
/**
 * tests/classroom/prepare_app.php
 *
 * Copy the application into a disposable directory with a .env pointing at the
 * throwaway test database, so scripts and pages that load config/db_connect.php can
 * be exercised without touching production.
 *
 * This is the only safe way to test anything that requires config/db_connect.php on
 * this machine: that file reads the project .env, and this project's .env has
 * DB_HOST=srv2113.hstgr.io -- the live Hostinger database. Running a migration in
 * place would migrate production.
 *
 * The real .env is never copied. Customer uploads are never copied.
 *
 * Run: php tests/classroom/prepare_app.php     (prints the app directory)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pdf_fixture.php';

$root = dirname(__DIR__, 2);
$dest = sys_get_temp_dir() . '/tutsys-classroom-test/app';

$skipDirs = ['node_modules', 'uploads', 'graphify-out', '.git', '.agents', '.codex', 'tests'];

function copy_tree(string $from, string $to, array $skipDirs): int {
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            function ($current) use ($skipDirs) {
                return !($current->isDir() && in_array($current->getFilename(), $skipDirs, true));
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($from) + 1);
        $target = $to . DIRECTORY_SEPARATOR . $rel;
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0777, true);
        } else {
            // The real .env must never reach the copy: it points at production.
            if ($item->getFilename() === '.env') continue;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
            copy($item->getPathname(), $target);
            $count++;
        }
    }
    return $count;
}

if (is_dir($dest)) {
    $tempRoot = realpath(sys_get_temp_dir());
    $resolvedDest = realpath($dest);
    if (!$tempRoot || !$resolvedDest || !str_starts_with(str_replace('\\', '/', $resolvedDest),
        rtrim(str_replace('\\', '/', $tempRoot), '/') . '/tutsys-classroom-test/')) {
        throw new RuntimeException('Refusing to remove a copy outside the classroom scratch directory');
    }
    // Remove the previous copy so a stale file cannot make a test pass.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dest);
}
mkdir($dest, 0777, true);

$copied = copy_tree($root, $dest, $skipDirs);

// vendor/ is skipped by the filter above only if listed; it is small and some pages
// need it, so bring it across explicitly.
if (is_dir($root . '/vendor') && !is_dir($dest . '/vendor')) {
    $copied += copy_tree($root . '/vendor', $dest . '/vendor', []);
}

// The browser harness goes into the disposable copy only, never the real app. It is
// a test fixture that boots the production whiteboard client without a video call.
copy(__DIR__ . '/harness_board.php', $dest . '/tests-harness-board.php');
if (is_file(__DIR__ . '/harness_books.php')) {
    copy(__DIR__ . '/harness_books.php', $dest . '/tutor/tests-harness-books.php');
}

// Synthetic upload fixtures. Real files are needed so the gateway tests exercise the
// actual serving path (and so a 404 means "refused", not "the file was missing").
// No personal data: these are three-line placeholder PDFs.
foreach ([
    'uploads/materials/published.pdf'   => 'published material',
    'uploads/materials/unpublished.pdf' => 'unpublished draft',
    'uploads/homework/assignment.pdf'   => 'homework attachment',
    'uploads/submissions/private.pdf'   => "another student's submission",
] as $rel => $label) {
    $path = $dest . '/' . $rel;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, "%PDF-1.4\n% fixture: $label\n%%EOF\n");
}

foreach (['book-maths.pdf', 'book-history.pdf', 'book-private.pdf', 'worksheet.pdf'] as $name) {
    file_put_contents($dest . '/uploads/materials/' . $name, ct_pdf_fixture($name === 'book-maths.pdf' ? 4 * 1024 * 1024 : 0));
}

file_put_contents($dest . '/.env', implode("\n", [
    'DB_HOST=' . CT_DB_HOST,
    'DB_PORT=' . CT_DB_PORT,
    'DB_NAME=' . CT_DB_NAME,
    'DB_USER=' . CT_DB_USER,
    'DB_PASSWORD=' . CT_DB_PASS,
    'DB_CHARSET=utf8mb4',
    'SITE_URL=http://127.0.0.1:8099',
    'DEBUG_MODE=true',
    // No SMTP, no Twilio, no LiveKit, no S3 credentials: outbound senders stay
    // unconfigured so a test can never deliver a real message or upload.
    '',
]));

echo $dest . "\n";
fwrite(STDERR, "copied $copied files\n");
