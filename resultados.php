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
    $previousStmt = $pdo->prepare('SELECT bh_minutes FROM shopfloor_bh_overrides WHERE user_id = ? AND work_date = ? LIMIT 1');
    $previousStmt->execute([$targetUserId, $workDate]);
    $previousBh = $previousStmt->fetchColumn();

    $upsertStmt = $pdo->prepare('INSERT INTO shopfloor_bh_overrides(user_id, work_date, bh_minutes, reason, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(user_id, work_date) DO UPDATE SET bh_minutes = excluded.bh_minutes, reason = excluded.reason, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP');
    $upsertStmt->execute([$targetUserId, $workDate, $overrideMinutes, $overrideReason !== '' ? $overrideReason : null, $actorUserId]);

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
        $newTime = trim((string) ($_POST['entry_time'] ?? ''));

        header('Content-Type: application/json; charset=UTF-8');

        if ($entryId <= 0 || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $newTime)) {
            echo json_encode(['ok' => false, 'message' => 'Hora inválida. Use HH:MM']);
            exit;
        }

        $entryStmt = $pdo->prepare('SELECT id, occurred_at FROM shopfloor_time_entries WHERE id = ? LIMIT 1');
        $entryStmt->execute([$entryId]);
        $entryRow = $entryStmt->fetch(PDO::FETCH_ASSOC);
        if (!$entryRow) {
            echo json_encode(['ok' => false, 'message' => 'Registo não encontrado.']);
            exit;
        }

        $entryDate = date('Y-m-d', strtotime((string) $entryRow['occurred_at']));
        $newOccurredAt = $entryDate . ' ' . $newTime . ':00';

        $updateEntryStmt = $pdo->prepare('UPDATE shopfloor_time_entries SET occurred_at = ? WHERE id = ?');
        $updateEntryStmt->execute([$newOccurredAt, $entryId]);

        log_app_event($pdo, $userId, 'shopfloor.time_entry.edit', 'Hora de picagem editada nos resultados.', [
            'entry_id' => $entryId,
            'new_time' => $newTime,
            'entry_date' => $entryDate,
        ]);

        echo json_encode(['ok' => true, 'entry_time' => $newTime]);
        exit;
    } elseif ($action === 'save_bh_override') {
        $overrideDate = trim((string) ($_POST['override_date'] ?? ''));
        $overrideUserId = (int) ($_POST['override_user_id'] ?? 0);
        $overrideBhValue = trim((string) ($_POST['override_bh_value'] ?? ''));
        $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
        $overrideMinutes = parse_signed_hhmm_to_minutes($overrideBhValue);

        if (!DateTimeImmutable::createFromFormat('Y-m-d', $overrideDate) || $overrideUserId <= 0) {
            $flashError = 'Dados inválidos para editar Tempo BH.';
        } elseif ($overrideMinutes === null) {
            $flashError = 'Tempo BH inválido. Use formato ±HH:MM.';
        } else {
            $previousStmt = $pdo->prepare('SELECT bh_minutes FROM shopfloor_bh_overrides WHERE user_id = ? AND work_date = ? LIMIT 1');
            $previousStmt->execute([$overrideUserId, $overrideDate]);
            $previousBh = $previousStmt->fetchColumn();

            $upsertStmt = $pdo->prepare('INSERT INTO shopfloor_bh_overrides(user_id, work_date, bh_minutes, reason, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(user_id, work_date) DO UPDATE SET bh_minutes = excluded.bh_minutes, reason = excluded.reason, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP');
            $upsertStmt->execute([$overrideUserId, $overrideDate, $overrideMinutes, $overrideReason !== '' ? $overrideReason : null, $userId]);

            $logOverrideStmt = $pdo->prepare('INSERT INTO shopfloor_bh_override_logs(user_id, work_date, previous_bh_minutes, new_bh_minutes, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $logOverrideStmt->execute([$overrideUserId, $overrideDate, $previousBh === false ? null : (int) $previousBh, $overrideMinutes, $overrideReason !== '' ? $overrideReason : null, $userId]);

            log_app_event($pdo, $userId, 'shopfloor.bh_override.save', 'Tempo BH editado manualmente.', [
                'target_user_id' => $overrideUserId,
                'work_date' => $overrideDate,
                'bh_minutes' => $overrideMinutes,
                'reason' => $overrideReason,
            ]);

            $flashSuccess = 'Tempo BH atualizado com sucesso.';
        }
    } elseif (!DateTimeImmutable::createFromFormat('Y-m-d', $validateDate)) {
        $flashError = 'Data inválida para validação.';
    } elseif ($action === 'validate_row' && $validateUserId > 0) {
        $targetEntriesStmt = $pdo->prepare('SELECT id, entry_type, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = ? AND validated_at IS NULL ORDER BY occurred_at ASC');
        $targetEntriesStmt->execute([$validateUserId, $validateDate]);
        $targetEntries = $targetEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$targetEntries) {
            $flashError = 'Não existem picagens pendentes para validar.';
        } else {
            $postedEntryTimes = (array) ($_POST['entry_time'] ?? []);
            $postedSlotTimes = (array) ($_POST['slot_time'] ?? []);
            $overrideBhValue = trim((string) ($_POST['override_bh_value'] ?? ''));
            $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));

            $pdo->beginTransaction();
            try {
                $updateEntryStmt = $pdo->prepare('UPDATE shopfloor_time_entries SET occurred_at = ? WHERE id = ?');
                foreach ($targetEntries as $targetEntry) {
                    $entryId = (int) $targetEntry['id'];
                    $newTime = trim((string) ($postedEntryTimes[$entryId] ?? ''));
                    if ($newTime === '') {
                        continue;
                    }
                    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $newTime)) {
                        throw new RuntimeException('Hora inválida. Use HH:MM.');
                    }

                    $entryDate = date('Y-m-d', strtotime((string) $targetEntry['occurred_at']));
                    $newOccurredAt = $entryDate . ' ' . $newTime . ':00';
                    $updateEntryStmt->execute([$newOccurredAt, $entryId]);

                    log_app_event($pdo, $userId, 'shopfloor.time_entry.edit', 'Hora de picagem editada nos resultados.', [
                        'entry_id' => $entryId,
                        'new_time' => $newTime,
                        'entry_date' => $entryDate,
                    ]);
                }

                $insertEntryStmt = $pdo->prepare('INSERT INTO shopfloor_time_entries(user_id, entry_type, occurred_at) VALUES (?, ?, ?)');
                foreach ($postedSlotTimes as $slotIndex => $slotTime) {
                    $slotNumber = (int) $slotIndex;
                    $newTime = trim((string) $slotTime);
                    if ($slotNumber <= 0 || $newTime === '') {
                        continue;
                    }
                    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $newTime)) {
                        throw new RuntimeException('Hora inválida. Use HH:MM.');
                    }

                    if ($slotNumber <= count($targetEntries)) {
                        continue;
                    }

                    $entryType = $slotNumber % 2 === 1 ? 'entrada' : 'saida';
                    $newOccurredAt = $validateDate . ' ' . $newTime . ':00';
                    $insertEntryStmt->execute([$validateUserId, $entryType, $newOccurredAt]);
                    $newEntryId = (int) $pdo->lastInsertId();

                    log_app_event($pdo, $userId, 'shopfloor.time_entry.create', 'Nova picagem criada nos resultados.', [
                        'entry_id' => $newEntryId,
                        'entry_type' => $entryType,
                        'new_time' => $newTime,
                        'entry_date' => $validateDate,
                    ]);
                }

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
                $bhSeconds = get_override_bh_seconds($pdo, $validateUserId, $validateDate) ?? ($targetSeconds - $effectiveSeconds);
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
                    $bhSeconds = get_override_bh_seconds($pdo, (int) $entryUserId, $validateDate) ?? ($targetSeconds - $effectiveSeconds);
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

    $row['status'] = $row['validated_at'] ? 'Validado' : 'Em curso';
    $row['type_label'] = count($row['entries']) >= 4 ? 'Normal' : 'Parcial';
    $row['effective'] = sprintf('%02d:%02d', intdiv($row['seconds'], 3600), intdiv($row['seconds'] % 3600, 60));
    $row['target'] = '08:15';
    $computedBhSeconds = ((8 * 3600) + (15 * 60)) - $row['seconds'];
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
<form method="get" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Início</label><input class="form-control" type="date" name="start_date" value="<?= h($startDate) ?>"></div>
        <div class="col-md-2"><label class="form-label">Fim</label><input class="form-control" type="date" name="end_date" value="<?= h($endDate) ?>"></div>
        <div class="col-md-3"><label class="form-label">Equipa</label><select class="form-select" name="team_id"><option value="0">Todas</option><?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= (int) $team['id'] === $teamId ? 'selected' : '' ?>><?= h((string) $team['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Utilizadores</label><select class="form-select" name="user_ids[]" multiple size="4" <?= $canViewAllResults ? '' : 'disabled' ?>><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $selectedUsers, true) ? 'selected' : '' ?>><?= h((string) (($u['user_number'] ?: $u['id']) . ' - ' . $u['name'])) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-grid"><button class="btn btn-dark">Filtrar</button></div>
    </div>
</form>

<?php if ($canValidateResults && $startDate === $endDate): ?>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="validate_day">
        <input type="hidden" name="validate_date" value="<?= h($startDate) ?>">
        <button class="btn btn-success btn-sm"><i class="bi bi-check2-square me-1"></i>Validar rapidamente todas as picagens do dia</button>
    </form>
<?php endif; ?>

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
                    <tr>
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
                                        class="form-control form-control-sm results-entry-input"
                                        value="<?= h((string) ($entryPoint['time'] ?? '')) ?>"
                                        name="<?= $entryPoint ? 'entry_time[' . (int) $entryPoint['id'] . ']' : 'slot_time[' . ($entryIdx + 1) . ']' ?>"
                                        <?= $isPendingRow ? 'form="' . h($rowFormId) . '"' : '' ?>
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
                        <td><?= h($row['target']) ?></td>
                        <td><?= h($row['effective']) ?></td>
                        <td>
                            <?php $bhClass = $row['bh_seconds'] > 0 ? 'text-danger' : ($row['bh_seconds'] < 0 ? 'text-success' : 'text-muted'); ?>
                            <?php if ($canValidateResults): ?>
                                <div class="d-flex mt-1 align-items-center gap-1">
                                    <input type="text" class="form-control form-control-sm results-bh-input <?= $bhClass ?>" name="override_bh_value" value="<?= h($row['bh']) ?>" placeholder="±HH:MM" <?= $isPendingRow ? 'form="' . h($rowFormId) . '"' : '' ?>>
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
<script>
(function () {
    const entryInputs = document.querySelectorAll('.js-entry-time');
    if (!entryInputs.length) {
        return;
    }

    entryInputs.forEach((input) => {
        input.addEventListener('blur', async () => {
            const entryId = input.dataset.entryId || '';
            const entryTime = input.value || '';
            if (!entryId || !entryTime) {
                return;
            }

            const body = new URLSearchParams();
            body.set('action', 'update_entry_time');
            body.set('entry_id', entryId);
            body.set('entry_time', entryTime);

            input.disabled = true;
            try {
                const response = await fetch('resultados.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString()
                });

                const data = await response.json();
                if (!data.ok) {
                    throw new Error(data.message || 'Erro ao guardar');
                }

                input.classList.remove('is-invalid');
                input.classList.add('is-valid');
                setTimeout(() => input.classList.remove('is-valid'), 1000);
            } catch (error) {
                input.classList.remove('is-valid');
                input.classList.add('is-invalid');
                console.error(error);
            } finally {
                input.disabled = false;
            }
        });
    });
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
