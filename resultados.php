<?php
require_once __DIR__ . '/helpers.php';
require_login();

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

    return date('d-m-Y', $timestamp) . ' (' . $weekday . ')';
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

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $newTime)) {
            echo json_encode(['ok' => false, 'message' => 'Hora inválida. Use HH:MM.']);
            exit;
        }

        if ($entryId > 0) {
            $entryStmt = $pdo->prepare('SELECT id, occurred_at FROM shopfloor_time_entries WHERE id = ? LIMIT 1');
            $entryStmt->execute([$entryId]);
            $entryRow = $entryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$entryRow) {
                echo json_encode(['ok' => false, 'message' => 'Registo não encontrado.']);
                exit;
            }

            $resolvedDate = date('Y-m-d', strtotime((string) $entryRow['occurred_at']));
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

    if (!DateTimeImmutable::createFromFormat('Y-m-d', $validateDate)) {
        $flashError = 'Data inválida para validação.';
    } elseif ($action === 'validate_row' && $validateUserId > 0) {
        $targetEntriesStmt = $pdo->prepare('SELECT id, entry_type, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = ? AND validated_at IS NULL ORDER BY occurred_at ASC');
        $targetEntriesStmt->execute([$validateUserId, $validateDate]);
        $targetEntries = $targetEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$targetEntries) {
            $flashError = 'Não existem picagens pendentes para validar.';
        } else {
            $overrideBhValue = trim((string) ($_POST['override_bh_value'] ?? ''));
            $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));

            $pdo->beginTransaction();
            try {
                if ($overrideBhValue !== '') {
                    $overrideMinutes = parse_signed_hhmm_to_minutes($overrideBhValue);
                    if ($overrideMinutes === null) {
                        throw new RuntimeException('Tempo BH inválido. Use formato ±HH:MM.');
                    }

                    persist_bh_override($pdo, $validateUserId, $validateDate, $overrideMinutes, $overrideReason, $userId);
                }

                $targetEntriesStmt->execute([$validateUserId, $validateDate]);
                $targetEntries = $targetEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare('UPDATE shopfloor_time_entries SET validated_by = ?, validated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND date(occurred_at) = ? AND validated_at IS NULL');
                $stmt->execute([$userId, $validateUserId, $validateDate]);

                $targetSeconds = (8 * 3600) + (15 * 60);
                $effectiveSeconds = calculate_effective_seconds($targetEntries);
                $bhSeconds = get_override_bh_seconds($pdo, $validateUserId, $validateDate) ?? ($effectiveSeconds - $targetSeconds);
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
            $entriesByUser = [];
            foreach ($pendingEntries as $entry) {
                $entryUserId = (int) ($entry['user_id'] ?? 0);
                if ($entryUserId <= 0) {
                    continue;
                }
                if (!isset($entriesByUser[$entryUserId])) {
                    $entriesByUser[$entryUserId] = [];
                }
                $entriesByUser[$entryUserId][] = $entry;
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
                foreach ($entriesByUser as $entryUserId => $userEntries) {
                    $effectiveSeconds = calculate_effective_seconds($userEntries);
                    $bhSeconds = get_override_bh_seconds($pdo, (int) $entryUserId, $validateDate) ?? ($effectiveSeconds - $targetSeconds);
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
        padding: 0.35rem 0.3rem;
        white-space: nowrap;
        vertical-align: middle;
    }

    .results-table .results-entry-col {
        width: 5.1rem;
        min-width: 5.1rem;
        max-width: 5.1rem;
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
                    <tr class="js-results-row" data-user-id="<?= (int) $row['user_id'] ?>" data-work-date="<?= h($row['date']) ?>">
                        <td><span class="badge <?= $row['status'] === 'Validado' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= h($row['status']) ?></span></td>
                        <td><?= h($row['type_label']) ?></td>
                        <td><?= h(format_date_pt($row['date'])) ?></td>
                        <td><?= h($row['user_number'] !== '' ? $row['user_number'] : (string) $row['user_id']) ?></td>
                        <td><?= h($row['user_name']) ?></td>
                        <?php for ($entryIdx = 0; $entryIdx < $maxEntryCount; $entryIdx++): ?>
                            <?php $entryPoint = $row['entries'][$entryIdx] ?? null; ?>
                            <td class="results-entry-col">
                                <?php if ($canValidateResults): ?>
                                    <input
                                        type="text"
                                        inputmode="numeric"
                                        pattern="^([01]\d|2[0-3]):([0-5]\d)$"
                                        class="form-control form-control-sm results-entry-input<?= $isPendingRow ? ' js-entry-time' : '' ?>"
                                        value="<?= h((string) ($entryPoint['time'] ?? '')) ?>"
                                        data-entry-id="<?= (int) ($entryPoint['id'] ?? 0) ?>"
                                        data-slot-index="<?= (int) ($entryIdx + 1) ?>"
                                        data-entry-date="<?= h($row['date']) ?>"
                                        data-target-user-id="<?= (int) $row['user_id'] ?>"
                                        placeholder="--:--"
                                        <?= $entryPoint || $isPendingRow ? '' : 'disabled' ?>
                                    >
                                <?php elseif ($entryPoint): ?>
                                    <?= h((string) $entryPoint['time']) ?>
                                <?php else: ?>
                                    <input
                                        type="text"
                                        class="form-control form-control-sm results-entry-input"
                                        value=""
                                        placeholder="--:--"
                                        disabled
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
                                    <input type="text" class="form-control form-control-sm results-bh-input js-results-bh-input <?= $bhClass ?>" name="override_bh_value" value="<?= h($row['bh']) ?>" placeholder="±HH:MM" data-default-value="<?= h($row['bh']) ?>" data-auto-bh="<?= h($row['bh']) ?>" data-is-override="<?= $row['bh_is_override'] ? '1' : '0' ?>" <?= $isPendingRow ? 'form="' . h($rowFormId) . '"' : '' ?>>
                                    <input type="text" class="form-control form-control-sm results-bh-reason" name="override_reason" value="<?= h((string) $row['bh_reason']) ?>" placeholder="Motivo" <?= $isPendingRow ? 'form="' . h($rowFormId) . '"' : '' ?>>
                                </div>
                            <?php else: ?>
                                <input type="text" class="form-control form-control-sm results-bh-input <?= $bhClass ?>" value="<?= h($row['bh']) ?>" readonly>
                            <?php endif; ?>
                        </td>
                        <?php if ($canValidateResults): ?>
                            <td class="text-end">
                                <?php if ($row['status'] === 'Validado'): ?>
                                    <span class="text-success small"><i class="bi bi-check-circle-fill me-1"></i>Validado</span>
                                <?php else: ?>
                                    <button class="btn btn-outline-success btn-sm" type="submit" form="<?= h($rowFormId) ?>">Validar</button>
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

<?php require __DIR__ . '/partials/footer.php'; ?>
