<?php
require_once __DIR__ . '/profile_photo.php';

/** Adapted from the student uploader. Keep the existing photo until the database commits. */
function save_profile_avatar(PDO $pdo, int $userId, array $file): string {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new InvalidArgumentException('Image too large (maximum 5 MB).');
    if ($error === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Choose a JPG or PNG picture.');
    if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Upload failed. Please choose the picture again.');
    $tmp = $file['tmp_name'];
    if (filesize($tmp) <= 0 || filesize($tmp) > 5 * 1024 * 1024) throw new InvalidArgumentException('Image too large or empty (maximum 5 MB).');
    $info = @getimagesize($tmp);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!$info || !isset($extensions[$mime]) || ($info['mime'] ?? '') !== $mime) throw new InvalidArgumentException('Only valid JPG and PNG pictures are allowed.');
    $directory = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) throw new RuntimeException('Avatar directory unavailable');
    $filename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('Avatar file could not be saved');
    $url = 'uploads/avatars/' . $filename;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE users SET profile_photo_url = ? WHERE id = ?');
        $stmt->execute([$url, $userId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Avatar user missing');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($destination); // Only the new, unreferenced file; never the working picture.
        throw $e;
    }
    $_SESSION['profile_photo_url'] = $url;
    // Retain old pictures: legacy files may be shared or needed for rollback.
    return $url;
}
