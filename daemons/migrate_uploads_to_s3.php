<?php
// daemons/migrate_uploads_to_s3.php
// Moves materials, homework and submission files from local disk to the bucket.
// Scans all three historic upload roots (uploads/, tutor/uploads/, student/uploads/).
// Safe to run repeatedly: uploads then deletes local; already-moved files are gone.
//
// Run:  php daemons/migrate_uploads_to_s3.php

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../integrations/file_storage.php';

if (!s3_configured()) {
    exit("S3 storage is not configured in .env - nothing to do.\n");
}

$roots = [
    __DIR__ . '/../uploads',
    __DIR__ . '/../tutor/uploads',
    __DIR__ . '/../student/uploads',
];

$done = 0; $failed = 0; $bytes = 0;
foreach ($roots as $root) {
    foreach (storage_prefixes() as $type) {
        $dir = "$root/$type";
        if (!is_dir($dir)) continue;
        // recurse: some historic uploads sit in nested subfolders; keys are
        // flattened to the basename, matching how serving resolves file_urls
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $info) {
            $path = $info->getPathname();
            $f = $info->getFilename();
            if (!$info->isFile() || $f === '.htaccess') continue;
            $key = "uploads/$type/" . $f;
            $size = filesize($path);
            echo str_pad("$type/$f", 60) . ' ' . str_pad(round($size / 1048576, 1) . ' MB', 9);
            if (s3_put_object($key, $path, storage_mime($path))) {
                unlink($path);
                echo "OK\n";
                $done++; $bytes += $size;
            } else {
                echo "FAILED (kept local)\n";
                $failed++;
            }
        }
    }
}
echo "\nMigrated $done files (" . round($bytes / 1048576, 1) . " MB), failed: $failed\n";
