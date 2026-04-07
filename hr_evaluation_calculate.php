<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hr_evaluation_helpers.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Acesso reservado a administradores e equipa RH.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = [];
if (is_string($rawInput) && trim($rawInput) !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (!$payload) {
    $payload = $_POST;
}

$targetUserId = (int) ($payload['user_id'] ?? 0);
$awardYear = (int) ($payload['award_year'] ?? date('Y'));
$awardPeriod = (string) ($payload['award_period'] ?? 'jan_abr');
$awardProfile = trim((string) ($payload['award_profile'] ?? ''));
$performanceScore = (int) ($payload['performance_score'] ?? 0);
$behaviorScore = (int) ($payload['behavior_score'] ?? 0);
$punctualityCount = (int) ($payload['punctuality_count'] ?? 0);
$absenceCount = (int) ($payload['absence_count'] ?? 0);
$finalAbsenceCount = (int) ($payload['final_absence_count'] ?? 0);
$evaluationId = isset($payload['evaluation_id']) ? (int) $payload['evaluation_id'] : null;

$validPeriods = array_keys(taskforce_evaluation_periods());
$errors = [];
if ($targetUserId <= 0) {
    $errors[] = 'Colaborador inválido.';
}
if ($awardYear < 2024 || $awardYear > 2100) {
    $errors[] = 'Ano inválido.';
}
if (!in_array($awardPeriod, $validPeriods, true)) {
    $errors[] = 'Período inválido.';
}
if ($performanceScore < 0 || $performanceScore > 3) {
    $errors[] = 'Score de performance inválido.';
}
if ($behaviorScore < 0 || $behaviorScore > 3) {
    $errors[] = 'Score de comportamento inválido.';
}
if ($punctualityCount < 0 || $absenceCount < 0 || $finalAbsenceCount < 0) {
    $errors[] = 'Contagens não podem ser negativas.';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$employee = taskforce_fetch_evaluation_employee($pdo, $targetUserId);
if (!$employee) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Colaborador não encontrado.']);
    exit;
}

$profileKey = $awardProfile !== '' ? $awardProfile : (string) ($employee['award_profile'] ?? 'operador');
if (!array_key_exists($profileKey, taskforce_evaluation_profiles())) {
    $profileKey = 'operador';
}

$rule = taskforce_resolve_evaluation_rule(
    $pdo,
    $awardYear,
    $profileKey,
    isset($employee['department_id']) ? (int) $employee['department_id'] : null
);

$periodValues = taskforce_calculate_period_values($rule['config'], [
    'performance_score' => $performanceScore,
    'behavior_score' => $behaviorScore,
    'punctuality_count' => $punctualityCount,
    'absence_count' => $absenceCount,
]);

$yearSummary = taskforce_calculate_year_summary(
    $pdo,
    $targetUserId,
    $awardYear,
    $rule['config'],
    $finalAbsenceCount,
    $evaluationId,
    $periodValues
);

echo json_encode([
    'ok' => true,
    'rule' => [
        'id' => (int) ($rule['id'] ?? 0),
        'name' => (string) ($rule['name'] ?? ''),
        'profile_key' => (string) ($rule['profile_key'] ?? $profileKey),
    ],
    'values' => array_merge($periodValues, $yearSummary),
], JSON_UNESCAPED_UNICODE);
