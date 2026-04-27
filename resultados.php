<?php
require_once __DIR__ . '/helpers.php';
require_login();

if (!function_exists('format_user_picker_label')) {
    function format_user_picker_label(array $user): string
    {
        $userId = (int) ($user['id'] ?? 0);
        $userNumber = trim((string) ($user['user_number'] ?? ''));
        $userName = trim((string) ($user['name'] ?? ''));
        $labelNumber = $userNumber !== '' ? $userNumber : (string) $userId;

        return trim($labelNumber . ' - ' . $userName, ' -');
    }
}

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$currentUser = current_user($pdo);
$profile = (string) ($currentUser['access_profile'] ?? 'Utilizador');
$canViewAllResults = $isAdmin || $profile === 'RH';
$canValidateResults = $canViewAllResults;

$startDate = trim((string) ($_GET['start_date'] ?? date('Y-m-d')));
$endDate = trim((string) ($_GET['end_date'] ?? date('Y-m-d')));
$teamId = (int) ($_GET['team_id'] ?? 0);
$selectedUsers = array_map('intval', (array) ($_GET['user_ids'] ?? []));

if ($startDate === '' || !DateTimeImmutable::createFromFormat('Y-m-d', $startDate)) {
    $startDate = date('Y-m-d');
}
if ($endDate === '' || !DateTimeImmutable::createFromFormat('Y-m-d', $endDate) || $endDate < $startDate) {
    $endDate = $startDate;
}

$flashSuccess = null;
$flashError = null;

function ensure_hour_bank_row(PDO $pdo, int $targetUserId): float
{
    $balanceStmt = $pdo->prepare('SELECT balance_hours FROM shopfloor_hour_banks WHERE user_id = ? LIMIT 1');
    $balanceStmt->execute([$targetUserId]);
    $balance = $balanceStmt->fetchColumn();

    if ($balance === false) {
        $pdo->prepare('INSERT INTO shopfloor_hour_banks(user_id, balance_hours, notes) VALUES (?, 0, NULL)')->execute([$targetUserId]);
        return 0.0;
    }

    return (float) $balance;
}

function apply_hour_bank_delta(PDO $pdo, int $targetUserId, int $deltaSeconds, int $validatedBy, string $actionDate): void
{
    if ($deltaSeconds === 0) {
        return;
    }

    $deltaMinutes = (int) round($deltaSeconds / 60);
    if ($deltaMinutes === 0) {
        return;
    }

    $currentBalance = ensure_hour_bank_row($pdo, $targetUserId);
    $deltaHours = $deltaMinutes / 60;
    $newBalance = $currentBalance + $deltaHours;

    $updateStmt = $pdo->prepare('UPDATE shopfloor_hour_banks SET balance_hours = ?, notes = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
    $updateStmt->execute([$newBalance, 'Validação automática de picagens', $validatedBy, $targetUserId]);

    $actionType = $deltaMinutes < 0 ? 'debito' : 'credito';
    $reason = 'Validação de picagens - ' . $actionDate;
    $logStmt = $pdo->prepare('INSERT INTO hr_hour_bank_logs(user_id, delta_minutes, reason, created_by, action_type, action_date) VALUES (?, ?, ?, ?, ?, ?)');
    $logStmt->execute([$targetUserId, $deltaMinutes, $reason, $validatedBy, $actionType, $actionDate]);
}

function calculate_effective_seconds(array $entries): int
{
    usort(
        $entries,
        static function (array $a, array $b): int {
            return strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? ''));
        }
    );

    $seconds = 0;
    $openIn = null;
    foreach ($entries as $entry) {
        $entryType = (string) ($entry['entry_type'] ?? '');
        $timestamp = strtotime((string) ($entry['occurred_at'] ?? ''));
        if (!$timestamp) {
            continue;
        }

        if ($entryType === 'entrada') {
            $openIn = $timestamp;
        } elseif ($entryType === 'saida' && $openIn !== null && $timestamp > $openIn) {
            $seconds += ($timestamp - $openIn);
            $openIn = null;
        }
    }

    return $seconds;
}

function format_signed_hhmm(int $seconds): string
{
    $abs = abs($seconds);
    $hours = intdiv($abs, 3600);
    $minutes = intdiv($abs % 3600, 60);
    $prefix = $seconds < 0 ? '-' : '';
    return sprintf('%s%02d:%02d', $prefix, $hours, $minutes);
}

function format_date_pt(string $date): string
{
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $date;
    }

    $weekdayMap = [
        1 => 'Seg',
        2 => 'Ter',
        3 => 'Qua',
        4 => 'Qui',
        5 => 'Sex',
        6 => 'Sáb',
        7 => 'Dom',
    ];
    $weekday = $weekdayMap[(int) date('N', $timestamp)] ?? date('D', $timestamp);

    return date('d/m/y', $timestamp) . ' ' . $weekday;
}


function list_weekdays_between(string $startDate, string $endDate): array
{
    if ($startDate === '' || $endDate === '' || $endDate < $startDate) {
        return [];
    }

    $days = [];
    $current = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    while ($current <= $end) {
        if ((int) $current->format('N') <= 5) {
            $days[] = $current->format('Y-m-d');
        }
        $current = $current->modify('+1 day');
    }

    return $days;
}

function parse_absence_sage_code(string $reason): string
{
    if (preg_match('/·\s*([A-Z0-9\-]+)\s*-/', $reason, $matches)) {
        return trim((string) ($matches[1] ?? ''));
    }

    return '';
}

function should_exclude_absence_from_bank_credit(string $absenceCode): bool
{
    $normalized = preg_replace('/\D+/', '', $absenceCode) ?? '';
    return $normalized === '100';
}

function format_sage_employee_number(string $value, int $fallbackUserId): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        $digits = (string) $fallbackUserId;
    }

    if (strlen($digits) < 3) {
        return str_pad($digits, 3, '0', STR_PAD_LEFT);
    }

    return $digits;
}

function format_sage_quantity(float $quantity): string
{
    return str_pad(number_format($quantity, 2, '.', ''), 22, '0', STR_PAD_LEFT);
}

function build_sage_payroll_export(array $records): string
{
    if ($records === []) {
        return '';
    }

    usort(
        $records,
        static function (array $a, array $b): int {
            $left = ($a['employee_number'] ?? '') . '|' . ($a['work_date'] ?? '') . '|' . ($a['sage_code'] ?? '');
            $right = ($b['employee_number'] ?? '') . '|' . ($b['work_date'] ?? '') . '|' . ($b['sage_code'] ?? '');
            return strcmp($left, $right);
        }
    );

    $lines = [];
    foreach ($records as $record) {
        $employeeNumber = (string) ($record['employee_number'] ?? '000');
        $sageCode = trim((string) ($record['sage_code'] ?? ''));
        $workDate = trim((string) ($record['work_date'] ?? ''));
        $quantity = (float) ($record['quantity'] ?? 0);
        if ($sageCode === '' || $workDate === '') {
            continue;
        }

        $dateLabel = date('d.m.Y', strtotime($workDate));
        $lines[] = sprintf('%s%s      %s%s', $employeeNumber, $dateLabel, $sageCode, format_sage_quantity($quantity));
    }

    return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
}

function parse_signed_hhmm_to_minutes(string $value): ?int
{
    if (!preg_match('/^([+-])?(\d{1,3}):(\d{2})$/', $value, $matches)) {
        return null;
    }

    $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
    $hours = (int) $matches[2];
    $minutes = (int) $matches[3];
    if ($minutes > 59) {
        return null;
    }

    return $sign * (($hours * 60) + $minutes);
}

function parse_hhmm_to_minutes(string $value): ?int
{
    if (!preg_match('/^(\d{1,3}):(\d{2})$/', $value, $matches)) {
        return null;
    }

    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];
    if ($minutes > 59) {
        return null;
    }

    return ($hours * 60) + $minutes;
}

function format_hhmm_from_minutes(int $minutes): string
{
    if ($minutes < 0) {
        $minutes = 0;
    }

    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function resolve_absence_minutes(array $absence, int $targetMinutes): int
{
    $durationType = (string) ($absence['duration_type'] ?? 'Completa');
    if ($durationType === 'Horas') {
        $hoursValue = trim((string) ($absence['duration_hours'] ?? ''));
        $parsedMinutes = parse_hhmm_to_minutes($hoursValue);
        return $parsedMinutes ?? 0;
    }

    if ($durationType === 'Parcial') {
        return (int) floor($targetMinutes / 2);
    }

    return $targetMinutes;
}

function get_override_bh_seconds(PDO $pdo, int $targetUserId, string $workDate): ?int
{
    $overrideStmt = $pdo->prepare('SELECT bh_minutes FROM shopfloor_bh_overrides WHERE user_id = ? AND work_date = ? LIMIT 1');
    $overrideStmt->execute([$targetUserId, $workDate]);
    $bhMinutes = $overrideStmt->fetchColumn();
    if ($bhMinutes === false) {
        return null;
    }

    return ((int) $bhMinutes) * 60;
}

function get_absence_allocated_seconds(PDO $pdo, int $targetUserId, string $workDate): int
{
    $allocationStmt = $pdo->prepare('SELECT allocated_minutes FROM shopfloor_absence_time_allocations WHERE user_id = ? AND work_date = ? LIMIT 1');
    $allocationStmt->execute([$targetUserId, $workDate]);
    $allocatedMinutes = $allocationStmt->fetchColumn();
    if ($allocatedMinutes === false) {
        return 0;
    }

    return ((int) $allocatedMinutes) * 60;
}

function persist_bh_override(PDO $pdo, int $targetUserId, string $workDate, int $overrideMinutes, string $overrideReason, int $actorUserId): void
{
    $previousStmt = $pdo->prepare('SELECT id, bh_minutes FROM shopfloor_bh_overrides WHERE user_id = ? AND work_date = ? LIMIT 1');
    $previousStmt->execute([$targetUserId, $workDate]);
    $previousRow = $previousStmt->fetch(PDO::FETCH_ASSOC);
    $previousBh = $previousRow['bh_minutes'] ?? false;

    if ($previousRow) {
        $updateStmt = $pdo->prepare('UPDATE shopfloor_bh_overrides SET bh_minutes = ?, reason = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $updateStmt->execute([$overrideMinutes, $overrideReason !== '' ? $overrideReason : null, $actorUserId, (int) $previousRow['id']]);
    } else {
        $insertStmt = $pdo->prepare('INSERT INTO shopfloor_bh_overrides(user_id, work_date, bh_minutes, reason, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        $insertStmt->execute([$targetUserId, $workDate, $overrideMinutes, $overrideReason !== '' ? $overrideReason : null, $actorUserId]);
    }

    $logOverrideStmt = $pdo->prepare('INSERT INTO shopfloor_bh_override_logs(user_id, work_date, previous_bh_minutes, new_bh_minutes, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $logOverrideStmt->execute([$targetUserId, $workDate, $previousBh === false ? null : (int) $previousBh, $overrideMinutes, $overrideReason !== '' ? $overrideReason : null, $actorUserId]);

    log_app_event($pdo, $actorUserId, 'shopfloor.bh_override.save', 'Tempo BH editado manualmente.', [
        'target_user_id' => $targetUserId,
        'work_date' => $workDate,
        'bh_minutes' => $overrideMinutes,
        'reason' => $overrideReason,
    ]);
}

function clear_bh_override(PDO $pdo, int $targetUserId, string $workDate, int $actorUserId): void
{
    $existingStmt = $pdo->prepare('SELECT id, bh_minutes FROM shopfloor_bh_overrides WHERE user_id = ? AND work_date = ? LIMIT 1');
    $existingStmt->execute([$targetUserId, $workDate]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return;
    }

    $pdo->prepare('DELETE FROM shopfloor_bh_overrides WHERE id = ?')->execute([(int) $existing['id']]);
    $logOverrideStmt = $pdo->prepare('INSERT INTO shopfloor_bh_override_logs(user_id, work_date, previous_bh_minutes, new_bh_minutes, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $logOverrideStmt->execute([$targetUserId, $workDate, (int) $existing['bh_minutes'], 0, 'Override removido (valor automático)', $actorUserId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canValidateResults) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $validateDate = trim((string) ($_POST['validate_date'] ?? ''));
    $validateUserId = (int) ($_POST['validate_user_id'] ?? 0);

    if ($action === 'update_entry_time') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $slotIndex = (int) ($_POST['slot_index'] ?? 0);
        $entryDate = trim((string) ($_POST['entry_date'] ?? ''));
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $newTime = trim((string) ($_POST['entry_time'] ?? ''));

        header('Content-Type: application/json; charset=UTF-8');

        if ($entryId > 0) {
            $entryStmt = $pdo->prepare('SELECT id, occurred_at FROM shopfloor_time_entries WHERE id = ? LIMIT 1');
            $entryStmt->execute([$entryId]);
            $entryRow = $entryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$entryRow) {
                echo json_encode(['ok' => false, 'message' => 'Registo não encontrado.']);
                exit;
            }

            $resolvedDate = date('Y-m-d', strtotime((string) $entryRow['occurred_at']));
            if ($newTime === '') {
                $pdo->prepare('DELETE FROM shopfloor_time_entries WHERE id = ?')->execute([$entryId]);

                log_app_event($pdo, $userId, 'shopfloor.time_entry.delete', 'Picagem removida nos resultados.', [
                    'entry_id' => $entryId,
                    'entry_date' => $resolvedDate,
                ]);

                echo json_encode(['ok' => true, 'entry_id' => null, 'entry_time' => null, 'deleted' => true]);
                exit;
            }

            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $newTime)) {
                echo json_encode(['ok' => false, 'message' => 'Hora inválida. Use HH:MM.']);
                exit;
            }

            $newOccurredAt = $resolvedDate . ' ' . $newTime . ':00';
            $pdo->prepare('UPDATE shopfloor_time_entries SET occurred_at = ? WHERE id = ?')->execute([$newOccurredAt, $entryId]);

            log_app_event($pdo, $userId, 'shopfloor.time_entry.edit', 'Hora de picagem editada nos resultados.', [
                'entry_id' => $entryId,
                'new_time' => $newTime,
                'entry_date' => $resolvedDate,
            ]);

            echo json_encode(['ok' => true, 'entry_id' => $entryId, 'entry_time' => $newTime]);
            exit;
        }

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $newTime)) {
            echo json_encode(['ok' => false, 'message' => 'Hora inválida. Use HH:MM.']);
            exit;
        }

        if ($slotIndex <= 0 || $targetUserId <= 0 || !DateTimeImmutable::createFromFormat('Y-m-d', $entryDate)) {
            echo json_encode(['ok' => false, 'message' => 'Dados inválidos para criar picagem.']);
            exit;
        }

        $entryType = $slotIndex % 2 === 1 ? 'entrada' : 'saida';
        $newOccurredAt = $entryDate . ' ' . $newTime . ':00';
        $insertStmt = $pdo->prepare('INSERT INTO shopfloor_time_entries(user_id, entry_type, occurred_at) VALUES (?, ?, ?)');
        $insertStmt->execute([$targetUserId, $entryType, $newOccurredAt]);
        $newEntryId = (int) $pdo->lastInsertId();

        log_app_event($pdo, $userId, 'shopfloor.time_entry.create', 'Nova picagem criada nos resultados.', [
            'entry_id' => $newEntryId,
            'entry_type' => $entryType,
            'new_time' => $newTime,
            'entry_date' => $entryDate,
        ]);

        echo json_encode(['ok' => true, 'entry_id' => $newEntryId, 'entry_time' => $newTime, 'entry_type' => $entryType]);
        exit;
    }

    if ($action === 'save_absence_allocation') {
        header('Content-Type: application/json; charset=UTF-8');

        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $workDate = trim((string) ($_POST['work_date'] ?? ''));
        $absenceRequestId = (int) ($_POST['absence_request_id'] ?? 0);
        $absenceCode = trim((string) ($_POST['absence_code'] ?? ''));
        $absenceReason = trim((string) ($_POST['absence_reason'] ?? ''));
        $allocatedValue = trim((string) ($_POST['allocated_duration'] ?? ''));
        $allocatedMinutes = parse_hhmm_to_minutes($allocatedValue);

        if ($targetUserId <= 0 || !DateTimeImmutable::createFromFormat('Y-m-d', $workDate) || $absenceRequestId <= 0 || $absenceCode === '' || $allocatedMinutes === null) {
            echo json_encode(['ok' => false, 'message' => 'Dados inválidos para guardar relação de ausência.']);
            exit;
        }

        $checkStmt = $pdo->prepare('SELECT id, reason FROM shopfloor_absence_requests WHERE id = ? AND user_id = ? AND status <> "Rejeitado" AND start_date <= ? AND end_date >= ? LIMIT 1');
        $checkStmt->execute([$absenceRequestId, $targetUserId, $workDate, $workDate]);
        $requestRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$requestRow) {
            echo json_encode(['ok' => false, 'message' => 'Ausência não encontrada ou indisponível para este dia.']);
            exit;
        }
        if ($absenceReason === '') {
            $absenceReason = trim((string) ($requestRow['reason'] ?? ''));
        }
        if ($absenceReason === '') {
            echo json_encode(['ok' => false, 'message' => 'Motivo de ausência inválido para este dia.']);
            exit;
        }

        $existingStmt = $pdo->prepare('SELECT id FROM shopfloor_absence_time_allocations WHERE user_id = ? AND work_date = ? LIMIT 1');
        $existingStmt->execute([$targetUserId, $workDate]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId) {
            $updateStmt = $pdo->prepare('UPDATE shopfloor_absence_time_allocations SET absence_request_id = ?, absence_code = ?, absence_reason = ?, allocated_minutes = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $updateStmt->execute([$absenceRequestId, $absenceCode, $absenceReason, $allocatedMinutes, $userId, (int) $existingId]);
        } else {
            $insertStmt = $pdo->prepare('INSERT INTO shopfloor_absence_time_allocations(user_id, work_date, absence_request_id, absence_code, absence_reason, allocated_minutes, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            $insertStmt->execute([$targetUserId, $workDate, $absenceRequestId, $absenceCode, $absenceReason, $allocatedMinutes, $userId]);
        }

        log_app_event($pdo, $userId, 'shopfloor.absence_time_allocation.save', 'Tempo de ausência relacionado ao dia de picagens.', [
            'target_user_id' => $targetUserId,
            'work_date' => $workDate,
            'absence_request_id' => $absenceRequestId,
            'absence_code' => $absenceCode,
            'absence_reason' => $absenceReason,
            'allocated_minutes' => $allocatedMinutes,
        ]);

        echo json_encode(['ok' => true, 'allocated_minutes' => $allocatedMinutes]);
        exit;
    }

    if ($action === 'reopen_row' && $validateUserId > 0 && DateTimeImmutable::createFromFormat('Y-m-d', $validateDate)) {
        $entriesStmt = $pdo->prepare('SELECT id, entry_type, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = ? ORDER BY occurred_at ASC');
        $entriesStmt->execute([$validateUserId, $validateDate]);
        $entriesToReopen = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$entriesToReopen) {
            $flashError = 'Não existem picagens para reabrir neste dia.';
        } else {
            $pdo->beginTransaction();
            try {
                $targetSeconds = (8 * 3600) + (15 * 60);
                $effectiveSeconds = calculate_effective_seconds($entriesToReopen);
                $absenceAllocatedSeconds = get_absence_allocated_seconds($pdo, $validateUserId, $validateDate);
                $computedBhSeconds = ($effectiveSeconds - $targetSeconds) + $absenceAllocatedSeconds;
                $bhSeconds = get_override_bh_seconds($pdo, $validateUserId, $validateDate) ?? $computedBhSeconds;

                apply_hour_bank_delta($pdo, $validateUserId, -$bhSeconds, $userId, $validateDate);
                $reopenStmt = $pdo->prepare('UPDATE shopfloor_time_entries SET validated_at = NULL, validated_by = NULL WHERE user_id = ? AND date(occurred_at) = ?');
                $reopenStmt->execute([$validateUserId, $validateDate]);

                $pdo->commit();
                $flashSuccess = 'Dia reaberto para edição.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Não foi possível reabrir o dia selecionado.';
            }
        }
    } elseif (!DateTimeImmutable::createFromFormat('Y-m-d', $validateDate)) {
        $flashError = 'Data inválida para validação.';
    } elseif ($action === 'validate_row' && $validateUserId > 0) {
        $pendingEntriesStmt = $pdo->prepare('SELECT id FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = ? AND validated_at IS NULL ORDER BY occurred_at ASC');
        $pendingEntriesStmt->execute([$validateUserId, $validateDate]);
        $pendingEntries = $pendingEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$pendingEntries) {
            $flashError = 'Não existem picagens pendentes para validar.';
        } else {
            $overrideBhValue = trim((string) ($_POST['override_bh_value'] ?? ''));
            $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));

            $pdo->beginTransaction();
            try {
                $allEntriesStmt = $pdo->prepare('SELECT id, entry_type, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = ? ORDER BY occurred_at ASC');
                $allEntriesStmt->execute([$validateUserId, $validateDate]);
                $allEntries = $allEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

                $targetSeconds = (8 * 3600) + (15 * 60);
                $effectiveSeconds = calculate_effective_seconds($allEntries);
                $absenceAllocatedSeconds = get_absence_allocated_seconds($pdo, $validateUserId, $validateDate);
                $computedBhSeconds = ($effectiveSeconds - $targetSeconds) + $absenceAllocatedSeconds;
                $computedBhMinutes = (int) round($computedBhSeconds / 60);

                if ($overrideBhValue !== '') {
                    $overrideMinutes = parse_signed_hhmm_to_minutes($overrideBhValue);
                    if ($overrideMinutes === null) {
                        throw new RuntimeException('Tempo BH inválido. Use formato ±HH:MM.');
                    }

                    if ($overrideMinutes !== $computedBhMinutes || $overrideReason !== '') {
                        persist_bh_override($pdo, $validateUserId, $validateDate, $overrideMinutes, $overrideReason, $userId);
                    } else {
                        clear_bh_override($pdo, $validateUserId, $validateDate, $userId);
                    }
                }

                $stmt = $pdo->prepare('UPDATE shopfloor_time_entries SET validated_by = ?, validated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND date(occurred_at) = ? AND validated_at IS NULL');
                $stmt->execute([$userId, $validateUserId, $validateDate]);

                $bhSeconds = get_override_bh_seconds($pdo, $validateUserId, $validateDate) ?? $computedBhSeconds;
                apply_hour_bank_delta($pdo, $validateUserId, $bhSeconds, $userId, $validateDate);
                $pdo->commit();
                $flashSuccess = 'Picagens validadas com sucesso.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível validar as picagens.';
            }
        }
    } elseif ($action === 'validate_day') {
        $whereParts = ['date(occurred_at) = ?'];
        $params = [$validateDate];

        if ($teamId > 0) {
            $whereParts[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = user_id)';
            $params[] = $teamId;
        }
        if ($selectedUsers) {
            $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
            $whereParts[] = 'user_id IN (' . $placeholders . ')';
            foreach ($selectedUsers as $selUserId) {
                $params[] = $selUserId;
            }
        }

        $fetchSql = 'SELECT user_id, entry_type, occurred_at
            FROM shopfloor_time_entries
            WHERE validated_at IS NULL AND ' . implode(' AND ', $whereParts) . '
            ORDER BY user_id ASC, occurred_at ASC';
        $fetchStmt = $pdo->prepare($fetchSql);
        $fetchStmt->execute($params);
        $pendingEntries = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$pendingEntries) {
            $flashError = 'Não existem picagens pendentes para validar no dia selecionado.';
        } else {
            $usersWithPendingEntries = [];
            foreach ($pendingEntries as $entry) {
                $entryUserId = (int) ($entry['user_id'] ?? 0);
                if ($entryUserId <= 0) {
                    continue;
                }
                $usersWithPendingEntries[$entryUserId] = true;
            }

            $pdo->beginTransaction();
            try {
                $sqlValidate = 'UPDATE shopfloor_time_entries
                    SET validated_by = ?, validated_at = CURRENT_TIMESTAMP
                    WHERE validated_at IS NULL AND ' . implode(' AND ', $whereParts);
                $validateParams = $params;
                array_unshift($validateParams, $userId);
                $pdo->prepare($sqlValidate)->execute($validateParams);

                $targetSeconds = (8 * 3600) + (15 * 60);
                foreach (array_keys($usersWithPendingEntries) as $entryUserId) {
                    $allEntriesStmt = $pdo->prepare('SELECT entry_type, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = ? ORDER BY occurred_at ASC');
                    $allEntriesStmt->execute([(int) $entryUserId, $validateDate]);
                    $allEntries = $allEntriesStmt->fetchAll(PDO::FETCH_ASSOC);
                    $effectiveSeconds = calculate_effective_seconds($allEntries);
                    $absenceAllocatedSeconds = get_absence_allocated_seconds($pdo, (int) $entryUserId, $validateDate);
                    $computedBhSeconds = ($effectiveSeconds - $targetSeconds) + $absenceAllocatedSeconds;
                    $bhSeconds = get_override_bh_seconds($pdo, (int) $entryUserId, $validateDate) ?? $computedBhSeconds;
                    apply_hour_bank_delta($pdo, (int) $entryUserId, $bhSeconds, $userId, $validateDate);
                }

                $pdo->commit();
                $flashSuccess = 'Picagens do dia validadas.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Não foi possível validar as picagens do dia.';
            }
        }
    }
}

$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
if ($canViewAllResults) {
    $users = $pdo->query('SELECT id, name, user_number FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtUsers = $pdo->prepare('SELECT id, name, user_number FROM users WHERE id = ? LIMIT 1');
    $stmtUsers->execute([$userId]);
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
}

$userTeamMap = [];
$userTeamStmt = $pdo->query('SELECT tm.user_id, tm.team_id FROM team_members tm');
foreach ($userTeamStmt->fetchAll(PDO::FETCH_ASSOC) as $membership) {
    $memberUserId = (int) ($membership['user_id'] ?? 0);
    $memberTeamId = (int) ($membership['team_id'] ?? 0);
    if ($memberUserId <= 0 || $memberTeamId <= 0) {
        continue;
    }

    if (!isset($userTeamMap[$memberUserId])) {
        $userTeamMap[$memberUserId] = [];
    }

    $userTeamMap[$memberUserId][] = $memberTeamId;
}

foreach ($users as &$listedUser) {
    $listedUserId = (int) ($listedUser['id'] ?? 0);
    $listedUser['team_ids'] = $userTeamMap[$listedUserId] ?? [];
}
unset($listedUser);

$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
$where = ['te.occurred_at BETWEEN ? AND ?'];
if (!$canViewAllResults) {
    $where[] = 'te.user_id = ?';
    $params[] = $userId;
}
if ($teamId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = user_id)';
    $params[] = $teamId;
}
if ($selectedUsers) {
    $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
    $where[] = 'te.user_id IN (' . $placeholders . ')';
    foreach ($selectedUsers as $selUserId) {
        $params[] = $selUserId;
    }
}

$sql = 'SELECT te.id, te.user_id, te.entry_type, te.occurred_at, te.validated_at, u.name AS user_name, u.user_number
        FROM shopfloor_time_entries te
        INNER JOIN users u ON u.id = te.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY te.user_id ASC, te.occurred_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$daily = [];
foreach ($entries as $entry) {
    $uid = (int) $entry['user_id'];
    $date = date('Y-m-d', strtotime((string) $entry['occurred_at']));
    $key = $uid . '|' . $date;

    if (!isset($daily[$key])) {
        $daily[$key] = [
            'user_id' => $uid,
            'user_name' => (string) $entry['user_name'],
            'user_number' => (string) ($entry['user_number'] ?? ''),
            'date' => $date,
            'entries' => [],
            'validated_at' => null,
            'seconds' => 0,
            'entries_count' => 0,
            'has_pending_entries' => false,
            'validated_entries_count' => 0,
        ];
    }

    $daily[$key]['entries'][] = [
        'id' => (int) $entry['id'],
        'type' => (string) $entry['entry_type'],
        'time' => date('H:i', strtotime((string) $entry['occurred_at'])),
        'timestamp' => strtotime((string) $entry['occurred_at']),
    ];

    if (!empty($entry['validated_at'])) {
        $daily[$key]['validated_at'] = (string) $entry['validated_at'];
        $daily[$key]['validated_entries_count']++;
    } else {
        $daily[$key]['has_pending_entries'] = true;
    }

    $daily[$key]['entries_count'] = count($daily[$key]['entries']);
}

$overrideParams = [$startDate, $endDate];
$overrideWhere = ['work_date BETWEEN ? AND ?'];
if (!$canViewAllResults) {
    $overrideWhere[] = 'user_id = ?';
    $overrideParams[] = $userId;
}
if ($teamId > 0) {
    $overrideWhere[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = user_id)';
    $overrideParams[] = $teamId;
}
if ($selectedUsers) {
    $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
    $overrideWhere[] = 'user_id IN (' . $placeholders . ')';
    foreach ($selectedUsers as $selUserId) {
        $overrideParams[] = $selUserId;
    }
}

$overrideSql = 'SELECT user_id, work_date, bh_minutes, reason, updated_at, updated_by FROM shopfloor_bh_overrides WHERE ' . implode(' AND ', $overrideWhere);
$overrideStmt = $pdo->prepare($overrideSql);
$overrideStmt->execute($overrideParams);
$overrideMap = [];
foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $overrideRow) {
    $overrideKey = ((int) $overrideRow['user_id']) . '|' . (string) $overrideRow['work_date'];
    $overrideMap[$overrideKey] = $overrideRow;
}

foreach ($daily as &$row) {
    $openIn = null;
    foreach ($row['entries'] as $point) {
        if ($point['type'] === 'entrada') {
            $openIn = (int) $point['timestamp'];
        } elseif ($point['type'] === 'saida' && $openIn !== null && $point['timestamp'] > $openIn) {
            $row['seconds'] += ((int) $point['timestamp'] - $openIn);
            $openIn = null;
        }
    }

    $row['status'] = (!$row['has_pending_entries'] && $row['validated_entries_count'] === $row['entries_count'] && $row['entries_count'] > 0) ? 'Validado' : 'Em curso';
    $row['type_label'] = count($row['entries']) >= 4 ? 'Normal' : 'Parcial';
    $row['effective'] = sprintf('%02d:%02d', intdiv($row['seconds'], 3600), intdiv($row['seconds'] % 3600, 60));
    $row['target'] = '08:15';
    $computedBhSeconds = $row['seconds'] - ((8 * 3600) + (15 * 60));
    $rowKey = $row['user_id'] . '|' . $row['date'];
    $override = $overrideMap[$rowKey] ?? null;
    $row['bh_seconds'] = $override ? (((int) $override['bh_minutes']) * 60) : $computedBhSeconds;
    $row['bh'] = format_signed_hhmm($row['bh_seconds']);
    $row['bh_is_override'] = $override !== null;
    $row['bh_reason'] = $override['reason'] ?? '';

}
unset($row);

usort(
    $daily,
    static function (array $a, array $b): int {
        if ($a['date'] === $b['date']) {
            return strcmp($a['user_name'], $b['user_name']);
        }
        return strcmp($b['date'], $a['date']);
    }
);

$absenceParams = [$endDate, $startDate];
$absenceWhere = ['a.start_date <= ?', 'a.end_date >= ?', 'a.status <> "Rejeitado"'];
if (!$canViewAllResults) {
    $absenceWhere[] = 'a.user_id = ?';
    $absenceParams[] = $userId;
}
if ($teamId > 0) {
    $absenceWhere[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = a.user_id)';
    $absenceParams[] = $teamId;
}
if ($selectedUsers) {
    $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
    $absenceWhere[] = 'a.user_id IN (' . $placeholders . ')';
    foreach ($selectedUsers as $selUserId) {
        $absenceParams[] = $selUserId;
    }
}

$absenceSql = 'SELECT a.id, a.user_id, a.start_date, a.end_date, a.reason, a.duration_type, a.duration_hours, a.status, u.user_number, u.name AS user_name
    FROM shopfloor_absence_requests a
    INNER JOIN users u ON u.id = a.user_id
    WHERE ' . implode(' AND ', $absenceWhere) . '
    ORDER BY a.user_id ASC, a.start_date ASC';
$absenceStmt = $pdo->prepare($absenceSql);
$absenceStmt->execute($absenceParams);
$approvedAbsences = $absenceStmt->fetchAll(PDO::FETCH_ASSOC);
$absenceReasonColorMap = [];
$absenceReasonColorStmt = $pdo->query('SELECT sage_code, color FROM shopfloor_absence_reasons WHERE is_active = 1');
foreach ($absenceReasonColorStmt->fetchAll(PDO::FETCH_ASSOC) as $colorRow) {
    $sage = normalize_sage_code((string) ($colorRow['sage_code'] ?? ''));
    if ($sage === '') {
        continue;
    }
    $absenceReasonColorMap[$sage] = (string) ($colorRow['color'] ?? '#6c757d');
}

$approvedAbsencesByDay = [];
foreach ($approvedAbsences as $absence) {
    $absenceUserId = (int) ($absence['user_id'] ?? 0);
    $absenceUserName = (string) ($absence['user_name'] ?? '');
    $absenceUserNumber = (string) ($absence['user_number'] ?? '');

    foreach (list_weekdays_between((string) $absence['start_date'], (string) $absence['end_date']) as $absenceDate) {
        $dailyKey = $absenceUserId . '|' . $absenceDate;
        if (!isset($daily[$dailyKey])) {
            $daily[$dailyKey] = [
                'user_id' => $absenceUserId,
                'user_name' => $absenceUserName,
                'user_number' => $absenceUserNumber,
                'date' => $absenceDate,
                'entries' => [],
                'validated_at' => null,
                'seconds' => 0,
                'entries_count' => 0,
                'has_pending_entries' => false,
                'validated_entries_count' => 0,
                'status' => 'Em curso',
                'type_label' => 'Ausência',
                'effective' => '00:00',
                'target' => '08:15',
                'bh_seconds' => 0,
                'bh' => format_signed_hhmm(0),
                'bh_is_override' => false,
                'bh_reason' => '',
            ];
        }
    }

    $absenceCode = parse_absence_sage_code((string) ($absence['reason'] ?? ''));
    if ($absenceCode === '') {
        continue;
    }

    $absenceUserId = (int) ($absence['user_id'] ?? 0);
    $resolvedMinutes = resolve_absence_minutes($absence, (8 * 60) + 15);
    foreach (list_weekdays_between((string) $absence['start_date'], (string) $absence['end_date']) as $absenceDate) {
        $mapKey = $absenceUserId . '|' . $absenceDate;
        if (!isset($approvedAbsencesByDay[$mapKey])) {
            $approvedAbsencesByDay[$mapKey] = [];
        }

        $approvedAbsencesByDay[$mapKey][] = [
            'absence_request_id' => (int) ($absence['id'] ?? 0),
            'absence_code' => $absenceCode,
            'default_minutes' => $resolvedMinutes,
            'reason' => (string) ($absence['reason'] ?? ''),
            'color' => (string) ($absenceReasonColorMap[$absenceCode] ?? '#6c757d'),
            'status' => (string) ($absence['status'] ?? ''),
        ];
    }
}

$allocationParams = [$startDate, $endDate];
$allocationWhere = ['work_date BETWEEN ? AND ?'];
if (!$canViewAllResults) {
    $allocationWhere[] = 'user_id = ?';
    $allocationParams[] = $userId;
}
if ($teamId > 0) {
    $allocationWhere[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = user_id)';
    $allocationParams[] = $teamId;
}
if ($selectedUsers) {
    $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
    $allocationWhere[] = 'user_id IN (' . $placeholders . ')';
    foreach ($selectedUsers as $selUserId) {
        $allocationParams[] = $selUserId;
    }
}

$allocationSql = 'SELECT user_id, work_date, absence_request_id, absence_code, absence_reason, allocated_minutes FROM shopfloor_absence_time_allocations WHERE ' . implode(' AND ', $allocationWhere);
$allocationStmt = $pdo->prepare($allocationSql);
$allocationStmt->execute($allocationParams);
$allocationMap = [];
foreach ($allocationStmt->fetchAll(PDO::FETCH_ASSOC) as $allocationRow) {
    $allocationKey = ((int) $allocationRow['user_id']) . '|' . (string) $allocationRow['work_date'];
    $allocationMap[$allocationKey] = [
        'absence_request_id' => (int) ($allocationRow['absence_request_id'] ?? 0),
        'absence_code' => (string) ($allocationRow['absence_code'] ?? ''),
        'absence_reason' => (string) ($allocationRow['absence_reason'] ?? ''),
        'allocated_minutes' => (int) ($allocationRow['allocated_minutes'] ?? 0),
    ];
}

$absenceReasonCatalog = [];
$absenceReasonCatalogStmt = $pdo->query('SELECT reason_code, sage_code, label, color FROM shopfloor_absence_reasons WHERE is_active = 1 ORDER BY reason_code ASC, label ASC');
foreach ($absenceReasonCatalogStmt->fetchAll(PDO::FETCH_ASSOC) as $catalogRow) {
    $reasonCode = trim((string) ($catalogRow['reason_code'] ?? ''));
    $sageCode = normalize_sage_code((string) ($catalogRow['sage_code'] ?? ''));
    $label = trim((string) ($catalogRow['label'] ?? ''));
    if ($sageCode === '' && $reasonCode === '' && $label === '') {
        continue;
    }

    $fullReason = trim(implode(' - ', array_filter([$reasonCode, $sageCode, $label], static fn ($value): bool => $value !== '')));
    $absenceReasonCatalog[] = [
        'absence_code' => $sageCode !== '' ? $sageCode : $reasonCode,
        'reason' => $fullReason,
        'color' => (string) ($catalogRow['color'] ?? '#6c757d'),
    ];
}

foreach ($daily as &$row) {
    $rowKey = ((int) $row['user_id']) . '|' . (string) $row['date'];
    $dayAbsences = $approvedAbsencesByDay[$rowKey] ?? [];
    $allocation = $allocationMap[$rowKey] ?? null;
    if ($allocation === null && $dayAbsences !== []) {
        $firstAbsence = $dayAbsences[0];
        $allocation = [
            'absence_request_id' => (int) ($firstAbsence['absence_request_id'] ?? 0),
            'absence_code' => (string) ($firstAbsence['absence_code'] ?? ''),
            'absence_reason' => (string) ($firstAbsence['reason'] ?? ''),
            'allocated_minutes' => (int) ($firstAbsence['default_minutes'] ?? 0),
        ];
    }

    $row['absence_options'] = $dayAbsences;
    $row['absence_allocation'] = $allocation;
    $allocationCode = $allocation ? (string) ($allocation['absence_code'] ?? '') : '';
    $row['absence_allocated_seconds'] = ($allocation && !should_exclude_absence_from_bank_credit($allocationCode)) ? ((int) ($allocation['allocated_minutes'] ?? 0) * 60) : 0;
    $row['computed_bh_seconds'] = ((int) $row['seconds'] - ((8 * 3600) + (15 * 60))) + (int) $row['absence_allocated_seconds'];
    $override = $overrideMap[$rowKey] ?? null;
    $row['bh_seconds'] = $override ? (((int) $override['bh_minutes']) * 60) : (int) $row['computed_bh_seconds'];
    $row['bh'] = format_signed_hhmm((int) $row['bh_seconds']);
    $row['bh_is_override'] = $override !== null;
    $row['bh_reason'] = $override['reason'] ?? '';
}
unset($row);

$exportRecords = [];
$exportRecordKeys = [];
foreach ($daily as $row) {
    if ((string) ($row['status'] ?? '') !== 'Validado' || (int) ($row['seconds'] ?? 0) <= 0) {
        continue;
    }

    $employeeNumber = format_sage_employee_number((string) ($row['user_number'] ?? ''), (int) ($row['user_id'] ?? 0));
    $recordKey = implode('|', [$row['user_id'], $row['date'], '202']);
    $exportRecordKeys[$recordKey] = true;
    $exportRecords[] = [
        'employee_number' => $employeeNumber,
        'user_id' => (int) ($row['user_id'] ?? 0),
        'work_date' => (string) $row['date'],
        'sage_code' => '202',
        'quantity' => 1.0,
    ];
}

foreach ($approvedAbsences as $absence) {
    $sageCode = parse_absence_sage_code((string) ($absence['reason'] ?? ''));
    if ($sageCode === '') {
        continue;
    }

    $quantity = 1.0;
    $durationType = (string) ($absence['duration_type'] ?? 'Completa');
    if ($durationType === 'Parcial') {
        $quantity = 0.5;
    } elseif ($durationType === 'Horas') {
        $hoursValue = trim((string) ($absence['duration_hours'] ?? ''));
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $hoursValue, $matches)) {
            $quantity = ((int) $matches[1]) + (((int) $matches[2]) / 60);
        }
    }

    foreach (list_weekdays_between((string) $absence['start_date'], (string) $absence['end_date']) as $absenceDate) {
        $recordKey = implode('|', [(int) ($absence['user_id'] ?? 0), $absenceDate, $sageCode]);
        if (isset($exportRecordKeys[$recordKey])) {
            continue;
        }

        $exportRecordKeys[$recordKey] = true;
        $exportRecords[] = [
            'employee_number' => format_sage_employee_number((string) ($absence['user_number'] ?? ''), (int) ($absence['user_id'] ?? 0)),
            'user_id' => (int) ($absence['user_id'] ?? 0),
            'work_date' => $absenceDate,
            'sage_code' => $sageCode,
            'quantity' => $quantity,
        ];
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'export_sage_payroll' && $canViewAllResults) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payroll_sage_' . $startDate . '_' . $endDate . '.txt"');

    $payload = build_sage_payroll_export($exportRecords);
    if ($payload === '') {
        $payload = 'Sem dados validados/aprovados para exportação no intervalo selecionado.' . PHP_EOL;
    }

    echo $payload;
    exit;
}

$maxEntryCount = 6;
foreach ($daily as $dailyRow) {
    $maxEntryCount = max($maxEntryCount, count($dailyRow['entries']));
}

$pageTitle = 'Resultados';
require __DIR__ . '/partials/header.php';
?>
<style>
    .results-table {
        font-size: 0.88rem;
    }

    .results-table th,
    .results-table td {
        padding: 0.26rem 0.2rem;
        white-space: nowrap;
        vertical-align: middle;
    }

    .results-table .results-entry-col {
        width: 3.7rem;
        min-width: 3.7rem;
        max-width: 3.7rem;
        text-align: center;
    }

    .results-table .results-entry-input {
        min-width: 4.9rem;
        width: 4.9rem;
        max-width: 4.9rem;
        padding: 0.18rem 0.35rem;
        font-size: 0.8rem;
        text-align: center;
    }

    .results-table .results-entry-input:disabled {
        background-color: #f8f9fa;
        opacity: 1;
    }

    .results-table .results-entry-display {
        font-size: 0.74rem;
        min-height: 1.2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        border: 0;
        border-radius: 0;
        background: transparent;
    }

    .results-table .results-bh-input {
        width: 5.4rem;
        max-width: 5.4rem;
        padding: 0.2rem 0.35rem;
        font-size: 0.82rem;
    }

    .results-table .results-bh-reason {
        width: 7.8rem;
        max-width: 7.8rem;
        padding: 0.2rem 0.35rem;
        font-size: 0.82rem;
    }

    .results-table .btn-sm {
        padding: 0.2rem 0.45rem;
        font-size: 0.78rem;
    }

    .results-table .badge {
        font-size: 0.77rem;
    }

    .results-validation-modal-entry-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.55rem;
    }

    .results-validation-modal-entry-grid .form-control {
        font-size: 0.86rem;
        padding: 0.22rem 0.45rem;
    }

    .results-bh-readonly {
        font-size: 0.82rem;
        font-weight: 600;
        color: #495057;
        min-height: 2rem;
        display: flex;
        align-items: center;
    }
</style>
<h1 class="h3 mb-3">Resultados de picagens</h1>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
<form method="get" class="card card-body shadow-sm mb-3" id="resultsFilterForm" data-user-picker-modal-target="#resultsCollaboratorsModal" data-user-picker-team-filter=".js-results-team-filter" data-user-picker-input-name="user_ids[]" data-user-picker-all-label="Todos" data-user-picker-selected-suffix="selecionados">
    <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Início</label><input class="form-control" type="date" name="start_date" value="<?= h($startDate) ?>"></div>
        <div class="col-md-2"><label class="form-label">Fim</label><input class="form-control" type="date" name="end_date" value="<?= h($endDate) ?>"></div>
        <?php if ($canViewAllResults): ?>
            <div class="col-md-3"><label class="form-label">Equipa</label><select class="form-select js-results-team-filter" name="team_id"><option value="0">Todas</option><?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= (int) $team['id'] === $teamId ? 'selected' : '' ?>><?= h((string) $team['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4 user-picker-root">
                <label class="form-label">Colaboradores</label>
                <div class="user-picker-meta">
                    <div class="form-text mt-0 js-user-picker-summary user-picker-summary">Todos</div>
                    <div class="user-picker-chips js-user-picker-chips"></div>
                </div>
                <button
                    type="button"
                    class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center"
                    data-bs-toggle="modal"
                    data-bs-target="#resultsCollaboratorsModal"
                >
                    <span class="text-start">Selecionar colaboradores</span>
                    <span class="badge text-bg-dark js-user-picker-count">0</span>
                </button>
                <div class="d-none js-user-picker-hidden-inputs">
                    <?php foreach ($selectedUsers as $selectedUserId): ?>
                        <input type="hidden" name="user_ids[]" value="<?= (int) $selectedUserId ?>">
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-1 d-grid"><button class="btn btn-dark">Filtrar</button></div>
            <div class="col-md-12 d-flex justify-content-end">
                <a class="btn btn-outline-secondary btn-sm" href="?start_date=<?= h($startDate) ?>&end_date=<?= h($endDate) ?>&team_id=<?= (int) $teamId ?><?php foreach ($selectedUsers as $exportUserId): ?>&user_ids[]=<?= (int) $exportUserId ?><?php endforeach; ?>&action=export_sage_payroll">Exportar payroll Sage</a>
            </div>
        <?php else: ?>
            <div class="col-md-2 d-grid"><button class="btn btn-dark">Filtrar</button></div>
        <?php endif; ?>
    </div>
</form>

<?php if ($canViewAllResults): ?>
    <div class="modal fade" id="resultsCollaboratorsModal" tabindex="-1" aria-labelledby="resultsCollaboratorsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="resultsCollaboratorsModalLabel">Escolher colaboradores</h2>
                        <p class="text-muted small mb-0">Pesquise, selecione vários colaboradores e aplique ao filtro.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-6">
                            <input type="search" class="form-control js-user-picker-search" placeholder="Pesquisar colaborador">
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                <button type="button" class="btn btn-outline-secondary btn-sm js-user-picker-select-all">Selecionar todos</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm js-user-picker-clear-all">Limpar</button>
                                <button type="button" class="btn btn-outline-primary btn-sm js-user-picker-select-team">Só da equipa escolhida</button>
                            </div>
                        </div>
                    </div>
                    <div class="user-picker-modal-list border rounded p-2">
                        <?php foreach ($users as $u): ?>
                            <?php $userLabel = format_user_picker_label($u); ?>
                            <?php $userLabelSearch = function_exists('mb_strtolower') ? mb_strtolower($userLabel) : strtolower($userLabel); ?>
                            <label class="user-picker-option border px-2 py-2 rounded js-user-picker-option" data-user-option data-user-id="<?= (int) $u['id'] ?>" data-user-label="<?= h($userLabelSearch) ?>" data-user-display-label="<?= h($userLabel) ?>" data-team-ids="<?= h(implode(',', array_map('intval', (array) ($u['team_ids'] ?? [])))) ?>">
                                <input class="form-check-input user-picker-checkbox js-user-picker-checkbox" type="checkbox" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $selectedUsers, true) ? 'checked' : '' ?>>
                                <span class="user-picker-meta-label flex-grow-1">
                                    <span class="d-block fw-semibold js-user-picker-label"><?= h($userLabel) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dark js-user-picker-apply">Aplicar</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$canViewAllResults): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white border-0 pb-0">
            <h2 class="h5 mb-1">As minhas picagens</h2>
            <p class="text-muted small mb-0">Consulta detalhada de todas as tuas picagens no intervalo selecionado.</p>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Hora</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$entries): ?>
                            <tr><td colspan="4" class="text-muted">Sem picagens no intervalo.</td></tr>
                        <?php else: ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td><?= h(format_date_pt(date('Y-m-d', strtotime((string) $entry['occurred_at'])))) ?></td>
                                    <td><?= h(date('H:i', strtotime((string) $entry['occurred_at']))) ?></td>
                                    <td><?= h((string) ucfirst((string) $entry['entry_type'])) ?></td>
                                    <td>
                                        <span class="badge <?= !empty($entry['validated_at']) ? 'text-bg-success' : 'text-bg-warning' ?>">
                                            <?= !empty($entry['validated_at']) ? 'Validado' : 'Em curso' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canViewAllResults && $canValidateResults && $startDate === $endDate): ?>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="validate_day">
        <input type="hidden" name="validate_date" value="<?= h($startDate) ?>">
        <button class="btn btn-success btn-sm"><i class="bi bi-check2-square me-1"></i>Validar rapidamente todas as picagens do dia</button>
    </form>
<?php endif; ?>

<?php if ($canViewAllResults): ?>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle results-table">
                <thead>
                    <tr>
                        <th>Estado</th><th>Tipo</th><th>Data</th><th>Número</th><th>Nome</th>
                        <?php for ($i = 1; $i <= $maxEntryCount; $i++): ?>
                            <?php $entryPrefix = $i % 2 === 1 ? 'E' : 'S'; ?>
                            <?php $entrySequence = (int) ceil($i / 2); ?>
                            <th class="results-entry-col"><?= $entryPrefix . $entrySequence ?></th>
                        <?php endfor; ?>
                        <th>Objectivo</th><th>Efectivo</th><th>Tempo BH</th>
                        <?php if ($canValidateResults): ?><th class="text-end">Validação</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$daily): ?>
                    <tr><td colspan="<?= 8 + $maxEntryCount + ($canValidateResults ? 1 : 0) ?>" class="text-muted">Sem registos no intervalo.</td></tr>
                <?php endif; ?>
                <?php foreach ($daily as $row): ?>
                    <?php $isPendingRow = $canValidateResults && $row['status'] !== 'Validado'; ?>
                    <?php $rowFormId = 'validate-row-' . (int) $row['user_id'] . '-' . str_replace('-', '', (string) $row['date']); ?>
                    <?php $existingEntryCount = (int) ($row['entries_count'] ?? count($row['entries'])); ?>
                    <tr class="js-results-row" data-user-id="<?= (int) $row['user_id'] ?>" data-work-date="<?= h($row['date']) ?>" data-row-status="<?= h((string) $row['status']) ?>" data-absence-allocated-seconds="<?= (int) ($row['absence_allocated_seconds'] ?? 0) ?>" data-absence-options='<?= h((string) json_encode(array_values((array) ($row['absence_options'] ?? [])), JSON_UNESCAPED_UNICODE)) ?>' data-current-request-id="<?= (int) (($row['absence_allocation']['absence_request_id'] ?? 0)) ?>" data-current-code="<?= h((string) ($row['absence_allocation']['absence_code'] ?? '')) ?>" data-current-reason="<?= h((string) ($row['absence_allocation']['absence_reason'] ?? '')) ?>" data-current-minutes="<?= (int) (($row['absence_allocation']['allocated_minutes'] ?? 0)) ?>">
                        <td><span class="badge <?= $row['status'] === 'Validado' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= h($row['status']) ?></span></td>
                        <td><?= h($row['type_label']) ?></td>
                        <td><?= h(format_date_pt($row['date'])) ?></td>
                        <td><?= h($row['user_number'] !== '' ? $row['user_number'] : (string) $row['user_id']) ?></td>
                        <td><?= h($row['user_name']) ?></td>
                        <?php for ($entryIdx = 0; $entryIdx < $maxEntryCount; $entryIdx++): ?>
                            <?php $entryPoint = $row['entries'][$entryIdx] ?? null; ?>
                            <td class="results-entry-col">
                                <span class="results-entry-display js-results-entry-display"><?= h((string) ($entryPoint['time'] ?? '--:--')) ?></span>
                                <?php if ($canValidateResults): ?>
                                    <input
                                        type="hidden"
                                        inputmode="numeric"
                                        pattern="^([01]\d|2[0-3]):([0-5]\d)$"
                                        class="results-entry-input<?= $isPendingRow ? ' js-entry-time' : '' ?>"
                                        value="<?= h((string) ($entryPoint['time'] ?? '')) ?>"
                                        data-entry-id="<?= (int) ($entryPoint['id'] ?? 0) ?>"
                                        data-slot-index="<?= (int) ($entryIdx + 1) ?>"
                                        data-entry-date="<?= h($row['date']) ?>"
                                        data-target-user-id="<?= (int) $row['user_id'] ?>"
                                        <?= $entryPoint || $isPendingRow ? '' : 'disabled' ?>
                                    >
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                        <td class="js-results-target" data-target-seconds="<?= (8 * 3600) + (15 * 60) ?>"><?= h($row['target']) ?></td>
                        <td class="js-results-effective" data-effective-seconds="<?= (int) $row['seconds'] ?>"><?= h($row['effective']) ?></td>
                        <td>
                            <?php $bhClass = $row['bh_seconds'] < 0 ? 'text-danger' : ($row['bh_seconds'] > 0 ? 'text-success' : 'text-muted'); ?>
                            <?php if ($canValidateResults): ?>
                                <div class="d-flex mt-1 align-items-center gap-1">
                                    <span class="badge text-bg-light border">BH <?= h($row['bh']) ?></span>
                                    <input type="hidden" class="form-control form-control-sm results-bh-input js-results-bh-input <?= $bhClass ?>" name="override_bh_value" value="<?= h($row['bh']) ?>" placeholder="±HH:MM" data-default-value="<?= h($row['bh']) ?>" data-auto-bh="<?= h($row['bh']) ?>" data-is-override="<?= $row['bh_is_override'] ? '1' : '0' ?>" <?= $isPendingRow ? 'form="' . h($rowFormId) . '"' : '' ?>>
                                    <input type="hidden" class="form-control form-control-sm results-bh-reason" name="override_reason" value="<?= h((string) $row['bh_reason']) ?>" placeholder="Motivo" <?= $isPendingRow ? 'form="' . h($rowFormId) . '"' : '' ?>>
                                </div>
                                <?php if (!empty($row['absence_allocation'])): ?>
                                    <div class="small text-muted mt-1 js-row-absence-summary">Ausência <?= h((string) ($row['absence_allocation']['absence_code'] ?? '')) ?> · <?= h(format_hhmm_from_minutes((int) ($row['absence_allocation']['allocated_minutes'] ?? 0))) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <input type="text" class="form-control form-control-sm results-bh-input <?= $bhClass ?>" value="<?= h($row['bh']) ?>" readonly>
                            <?php endif; ?>
                        </td>
                        <?php if ($canValidateResults): ?>
                            <td class="text-end">
                                <?php if ($row['status'] === 'Validado'): ?>
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <span class="text-success small" title="Validado" aria-label="Validado"><i class="bi bi-check-circle-fill"></i></span>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm js-open-validation-modal"
                                            title="Editar ausência"
                                            data-user-name="<?= h((string) $row['user_name']) ?>"
                                            data-user-number="<?= h($row['user_number'] !== '' ? $row['user_number'] : (string) $row['user_id']) ?>"
                                            data-work-date="<?= h(format_date_pt((string) $row['date'])) ?>"
                                        ><i class="bi bi-person-bounding-box"></i></button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="reopen_row">
                                            <input type="hidden" name="validate_date" value="<?= h($row['date']) ?>">
                                            <input type="hidden" name="validate_user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button class="btn btn-outline-primary btn-sm" type="submit" title="Voltar a editar" aria-label="Voltar a editar"><i class="bi bi-pencil"></i></button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="d-inline-flex gap-1">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm js-open-validation-modal"
                                            data-row-form-id="<?= h($rowFormId) ?>"
                                            data-user-name="<?= h((string) $row['user_name']) ?>"
                                            data-user-number="<?= h($row['user_number'] !== '' ? $row['user_number'] : (string) $row['user_id']) ?>"
                                            data-work-date="<?= h(format_date_pt((string) $row['date'])) ?>"
                                        ><i class="bi bi-pencil-square"></i></button>
                                        <button class="btn btn-outline-success btn-sm" type="submit" form="<?= h($rowFormId) ?>">Validar</button>
                                    </div>
                                    <form method="post" id="<?= h($rowFormId) ?>" class="d-none">
                                        <input type="hidden" name="action" value="validate_row">
                                        <input type="hidden" name="validate_date" value="<?= h($row['date']) ?>">
                                        <input type="hidden" name="validate_user_id" value="<?= (int) $row['user_id'] ?>">
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canValidateResults): ?>
<div class="modal fade" id="rowValidationModal" tabindex="-1" aria-labelledby="rowValidationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="rowValidationModalLabel">Validar tempo do dia</h2>
                    <p class="text-muted small mb-0 js-row-validation-context"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small d-none js-row-validation-absence-info"></div>
                <div class="results-validation-modal-entry-grid mb-3 js-row-validation-entries"></div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Tempo BH (±HH:MM)</label>
                        <div class="form-control-plaintext results-bh-readonly js-row-validation-bh">+00:00</div>
                    </div>
                </div>
                <div class="row g-2 mt-1 js-row-validation-absence-fields d-none">
                    <div class="col-md-8">
                        <label class="form-label">Motivo da ausência</label>
                        <select class="form-select js-row-validation-absence-code"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tempo associado (HH:MM)</label>
                        <input type="text" class="form-control js-row-validation-absence-duration" placeholder="02:00">
                    </div>
                </div>
                <p class="small text-muted mt-2 mb-0 js-row-validation-absence-help d-none">O tempo associado é somado ao efectivo para reduzir o Tempo BH negativo.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-dark js-row-validation-save">Guardar alterações</button>
                <button type="button" class="btn btn-success js-row-validation-save-validate">Guardar e validar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
(() => {
    const absenceReasonCatalog = <?= json_encode($absenceReasonCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const validationModalElement = document.getElementById('rowValidationModal');
    if (validationModalElement && window.bootstrap) {
        const validationModal = new bootstrap.Modal(validationModalElement);
        const contextEl = validationModalElement.querySelector('.js-row-validation-context');
        const entriesRoot = validationModalElement.querySelector('.js-row-validation-entries');
        const bhInput = validationModalElement.querySelector('.js-row-validation-bh');
        const absenceInfo = validationModalElement.querySelector('.js-row-validation-absence-info');
        const saveButton = validationModalElement.querySelector('.js-row-validation-save');
        const saveValidateButton = validationModalElement.querySelector('.js-row-validation-save-validate');
        const absenceFieldsRow = validationModalElement.querySelector('.js-row-validation-absence-fields');
        const absenceCodeSelect = validationModalElement.querySelector('.js-row-validation-absence-code');
        const absenceDurationInput = validationModalElement.querySelector('.js-row-validation-absence-duration');
        const absenceHelp = validationModalElement.querySelector('.js-row-validation-absence-help');
        const state = { row: null };

        const renderEntries = (row) => {
            entriesRoot.innerHTML = '';
            row.querySelectorAll('.js-entry-time').forEach((input) => {
                const slot = Number(input.dataset.slotIndex || '0');
                const prefix = slot % 2 === 1 ? 'Entrada' : 'Saída';
                const order = Math.ceil(slot / 2);
                const fieldWrap = document.createElement('div');
                fieldWrap.innerHTML = `<label class="form-label">${prefix} ${order}</label>`;
                const field = document.createElement('input');
                field.type = 'text';
                field.className = 'form-control';
                field.placeholder = '--:--';
                field.value = (input.value || '').trim();
                field.dataset.sourceSlot = String(slot);
                field.addEventListener('input', () => {
                    const sourceInput = row.querySelector(`.js-entry-time[data-slot-index="${slot}"]`);
                    if (!sourceInput) return;
                    sourceInput.value = field.value.trim();
                    const display = sourceInput.closest('td')?.querySelector('.js-results-entry-display');
                    if (display) {
                        display.textContent = field.value.trim() || '--:--';
                    }
                    if (typeof window.resultsRecalculateRow === 'function') {
                        window.resultsRecalculateRow(row);
                        const rowBhValue = (row.querySelector('.js-results-bh-input')?.dataset.autoBh || '').trim();
                        if (bhInput) {
                            bhInput.textContent = rowBhValue;
                        }
                    }
                });
                fieldWrap.appendChild(field);
                entriesRoot.appendChild(fieldWrap);
            });
        };

        const renderAbsenceFields = (row) => {
            const optionsRaw = row.dataset.absenceOptions || '[]';
            let dayOptions = [];
            try {
                dayOptions = JSON.parse(optionsRaw);
            } catch (error) {
                dayOptions = [];
            }
            absenceCodeSelect.innerHTML = '';
            if (!Array.isArray(dayOptions) || dayOptions.length === 0 || !Array.isArray(absenceReasonCatalog) || absenceReasonCatalog.length === 0) {
                absenceFieldsRow?.classList.add('d-none');
                absenceHelp?.classList.add('d-none');
                return;
            }

            absenceReasonCatalog.forEach((option) => {
                const opt = document.createElement('option');
                opt.value = String(option.absence_code || '');
                opt.textContent = `${option.absence_code || ''} - ${option.reason || 'Motivo'}`.trim();
                opt.dataset.absenceCode = option.absence_code || '';
                opt.dataset.absenceReason = option.reason || '';
                opt.dataset.absenceColor = option.color || '#6c757d';
                absenceCodeSelect.appendChild(opt);
            });

            const defaultDayOption = dayOptions[0] || null;
            const activeRequestId = String(row.dataset.currentRequestId || defaultDayOption?.absence_request_id || '0');
            row.dataset.currentRequestId = activeRequestId;
            const currentCode = (row.dataset.currentCode || defaultDayOption?.absence_code || '').trim();
            let selectedOption = Array.from(absenceCodeSelect.options).find((opt) => (opt.dataset.absenceCode || '') === currentCode);
            if (!selectedOption && (row.dataset.currentReason || '').trim() !== '') {
                selectedOption = Array.from(absenceCodeSelect.options).find((opt) => (opt.dataset.absenceReason || '').trim() === (row.dataset.currentReason || '').trim());
            }
            if (!selectedOption) {
                selectedOption = absenceCodeSelect.options[0];
            }
            if (selectedOption) {
                selectedOption.selected = true;
                const currentMinutes = parseInt(row.dataset.currentMinutes || defaultDayOption?.default_minutes || '0', 10);
                absenceDurationInput.value = `${String(Math.floor(currentMinutes / 60)).padStart(2, '0')}:${String(currentMinutes % 60).padStart(2, '0')}`;
            }
            const currentReason = (row.dataset.currentReason || '').trim();
            const fallbackReason = (selectedOption?.dataset.absenceReason || '').trim();
            const reasonValue = currentReason !== '' ? currentReason : fallbackReason;
            row.dataset.currentReason = reasonValue;
            const selectedColor = (selectedOption?.dataset.absenceColor || '#6c757d').trim();
            absenceCodeSelect.style.borderLeft = `0.35rem solid ${selectedColor}`;
            absenceCodeSelect.onchange = () => {
                const selectedOnChange = absenceCodeSelect.options[absenceCodeSelect.selectedIndex];
                if (!selectedOnChange) {
                    return;
                }
                row.dataset.currentReason = (selectedOnChange.dataset.absenceReason || '').trim();
                const changedColor = (selectedOnChange.dataset.absenceColor || '#6c757d').trim();
                absenceCodeSelect.style.borderLeft = `0.35rem solid ${changedColor}`;
            };

            absenceFieldsRow?.classList.remove('d-none');
            absenceHelp?.classList.remove('d-none');
        };

        const applyAbsenceAllocation = async (row) => {
            if (!absenceCodeSelect || !absenceDurationInput || !absenceFieldsRow || absenceFieldsRow.classList.contains('d-none')) {
                return true;
            }
            const selected = absenceCodeSelect.options[absenceCodeSelect.selectedIndex];
            if (!selected) return true;
            const payload = new URLSearchParams();
            payload.set('action', 'save_absence_allocation');
            payload.set('target_user_id', row.dataset.userId || '0');
            payload.set('work_date', row.dataset.workDate || '');
            payload.set('absence_request_id', row.dataset.currentRequestId || '0');
            payload.set('absence_code', selected.dataset.absenceCode || '');
            payload.set('absence_reason', (selected.dataset.absenceReason || row.dataset.currentReason || '').trim());
            payload.set('allocated_duration', (absenceDurationInput.value || '').trim());

            const response = await fetch('resultados.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                body: payload.toString(),
            });
            const result = await response.json();
            if (!result.ok) {
                alert(result.message || 'Não foi possível guardar o tempo da ausência.');
                return false;
            }

            const allocatedMinutes = Number(result.allocated_minutes || 0);
            row.dataset.currentRequestId = row.dataset.currentRequestId || '0';
            row.dataset.currentCode = selected.dataset.absenceCode || '';
            row.dataset.currentReason = (selected.dataset.absenceReason || '').trim();
            row.dataset.currentMinutes = String(allocatedMinutes);
            row.dataset.absenceAllocatedSeconds = String(allocatedMinutes * 60);
            const summary = row.querySelector('.js-row-absence-summary');
            const hh = String(Math.floor(allocatedMinutes / 60)).padStart(2, '0');
            const mm = String(allocatedMinutes % 60).padStart(2, '0');
            const summaryText = `Ausência ${(selected.dataset.absenceCode || '').trim()} · ${hh}:${mm}`;
            if (summary) {
                summary.textContent = summaryText;
            } else {
                const container = row.querySelector('.js-results-bh-input')?.closest('td');
                if (container) {
                    const div = document.createElement('div');
                    div.className = 'small text-muted mt-1 js-row-absence-summary';
                    div.textContent = summaryText;
                    container.appendChild(div);
                }
            }
            return true;
        };

        const runSave = async (shouldValidateAfterSave = false) => {
            if (!state.row) return;
            const row = state.row;
            const entryInputs = Array.from(row.querySelectorAll('.js-entry-time'));
            for (const input of entryInputs) {
                const value = (input.value || '').trim();
                const normalizedValue = value === '--:--' ? '' : value;
                const entryId = Number(input.dataset.entryId || '0');
                if (normalizedValue === '' && entryId <= 0) {
                    continue;
                }
                const body = new URLSearchParams();
                body.set('action', 'update_entry_time');
                body.set('entry_id', String(entryId > 0 ? entryId : 0));
                body.set('slot_index', input.dataset.slotIndex || '0');
                body.set('entry_date', input.dataset.entryDate || '');
                body.set('target_user_id', input.dataset.targetUserId || '0');
                body.set('entry_time', normalizedValue);
                body.set('validate_date', input.dataset.entryDate || '');
                const response = await fetch('resultados.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString(),
                });
                const result = await response.json();
                if (!result.ok) {
                    alert(result.message || 'Não foi possível guardar uma das picagens.');
                    return;
                }
                if (result.deleted) {
                    delete input.dataset.entryId;
                } else if (result.entry_id) {
                    input.dataset.entryId = String(result.entry_id);
                }
            }

            const absenceSaved = await applyAbsenceAllocation(row);
            if (!absenceSaved) return;

            const rowBhInput = row.querySelector('.js-results-bh-input');
            if (rowBhInput && typeof window.resultsRecalculateRow === 'function') {
                window.resultsRecalculateRow(row);
                rowBhInput.value = (rowBhInput.dataset.autoBh || '').trim();
                rowBhInput.dataset.isOverride = '0';
                const rowReasonInput = row.querySelector('.results-bh-reason');
                if (rowReasonInput) {
                    rowReasonInput.value = '';
                }
            }
            validationModal.hide();

            if (shouldValidateAfterSave) {
                const rowFormId = row.querySelector('.js-open-validation-modal')?.dataset.rowFormId || '';
                const form = rowFormId ? document.getElementById(rowFormId) : null;
                if (form) {
                    form.submit();
                }
            }
        };

        document.querySelectorAll('.js-open-validation-modal').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('.js-results-row');
                if (!row) return;
                state.row = row;
                contextEl.textContent = `${button.dataset.userName || ''} (${button.dataset.userNumber || ''}) · ${button.dataset.workDate || ''}`;
                const modalBhValue = (row.querySelector('.js-results-bh-input')?.dataset.autoBh || row.querySelector('.js-results-bh-input')?.value || '').trim();
                bhInput.textContent = modalBhValue;
                const isValidatedRow = (row.dataset.rowStatus || '') === 'Validado';
                if (saveValidateButton) {
                    saveValidateButton.classList.toggle('d-none', isValidatedRow);
                }
                const absenceSeconds = Number(row.dataset.absenceAllocatedSeconds || '0');
                if (absenceInfo) {
                    if (absenceSeconds > 0) {
                        const hours = Math.floor(absenceSeconds / 3600);
                        const minutes = Math.floor((absenceSeconds % 3600) / 60);
                        absenceInfo.textContent = `Ausência comunicada para o dia: +${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')} no cálculo do Tempo BH.`;
                        absenceInfo.classList.remove('d-none');
                    } else {
                        absenceInfo.classList.add('d-none');
                        absenceInfo.textContent = '';
                    }
                }
                renderEntries(row);
                renderAbsenceFields(row);
                validationModal.show();
            });
        });

        saveButton?.addEventListener('click', () => runSave(false));
        saveValidateButton?.addEventListener('click', () => runSave(true));
    }
})();
</script>
