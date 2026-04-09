<?php
declare(strict_types=1);

final class UploadService
{
    public static function secureUpload(array $file, array $allowedMimeTypes, int $maxBytes = 5242880): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) ($file['size'] ?? 0) > $maxBytes) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if (!in_array($mime, $allowedMimeTypes, true)) {
            return null;
        }

        $ext = pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION);
        $safeName = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . strtolower($ext) : '');
        $uploadDir = app_config('paths.uploads');
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0750, true);
        }

        $targetPath = rtrim((string) $uploadDir, '/') . '/' . $safeName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            return null;
        }

        return 'storage/uploads/' . $safeName;
    }
}
