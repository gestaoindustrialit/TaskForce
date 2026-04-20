<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$alertId = (int) ($_GET['alert_id'] ?? 0);
$token = trim((string) ($_GET['token'] ?? ''));
$preview = $_SESSION['hr_alert_preview'] ?? null;

if (!is_array($preview)) {
    http_response_code(404);
    exit('Pré-visualização não encontrada.');
}

$previewAlertId = (int) ($preview['alert_id'] ?? 0);
$previewToken = trim((string) ($preview['token'] ?? ''));
if ($alertId <= 0 || $previewAlertId !== $alertId || $previewToken === '' || !hash_equals($previewToken, $token)) {
    http_response_code(404);
    exit('Pré-visualização inválida.');
}

$relativePath = ltrim((string) ($preview['pdf_relative_path'] ?? ''), '/');
if ($relativePath === '' || str_contains($relativePath, '..') || !str_starts_with($relativePath, 'storage/generated-pdfs/')) {
    http_response_code(404);
    exit('Ficheiro de pré-visualização inválido.');
}

$absolutePath = __DIR__ . '/' . $relativePath;
if (!is_file($absolutePath) || !is_readable($absolutePath)) {
    http_response_code(404);
    exit('Ficheiro PDF não encontrado no servidor.');
}

$downloadName = trim((string) ($preview['pdf_filename'] ?? 'preview-mapa-mensal.pdf'));
if ($downloadName === '') {
    $downloadName = 'preview-mapa-mensal.pdf';
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . (string) filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
exit;
