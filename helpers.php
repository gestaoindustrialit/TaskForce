<?php
$taskforceConfigCandidates = [
    __DIR__ . '/config.php',
    dirname(__DIR__) . '/taskforce/config.php',
];

$taskforceConfigFile = null;
foreach ($taskforceConfigCandidates as $candidate) {
    if (is_file($candidate)) {
        $taskforceConfigFile = $candidate;
        break;
    }
}

if ($taskforceConfigFile === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Erro de configuração: config.php não encontrado.';
    exit;
}

require_once $taskforceConfigFile;

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_user(PDO $pdo): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, name, email, is_admin, access_profile, pin_only_login FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function can_access_hr_module(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT is_admin, access_profile FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    return (int) ($row['is_admin'] ?? 0) === 1 || (string) ($row['access_profile'] ?? '') === 'RH';
}

function is_admin(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() === 1;
}


function verify_pin_code(array $user, string $pin): bool
{
    if (!preg_match('/^\d{6}$/', $pin)) {
        return false;
    }

    $hash = trim((string) ($user['pin_code_hash'] ?? ''));
    if ($hash !== '') {
        // Compatibilidade com dados legados: alguns ambientes guardaram
        // o PIN em texto simples na própria coluna pin_code_hash.
        if (preg_match('/^\d{6}$/', $hash)) {
            return hash_equals($hash, $pin);
        }

        return password_verify($pin, $hash);
    }

    // Compatibilidade com instalações antigas onde o PIN podia ser guardado
    // na coluna legacy pin_code.
    $legacyPin = trim((string) ($user['pin_code'] ?? ''));
    if ($legacyPin === '') {
        return false;
    }

    if (preg_match('/^\d{6}$/', $legacyPin)) {
        return hash_equals($legacyPin, $pin);
    }

    return password_verify($pin, $legacyPin);
}

function is_pin_only_user(array $user): bool
{
    return (int) ($user['pin_only_login'] ?? 0) === 1;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }

    global $pdo;
    $user = current_user($pdo);
    if (!$user) {
        session_unset();
        session_destroy();
        redirect('login.php');
    }

    if (is_pin_only_user($user)) {
        $allowedPages = ['shopfloor.php', 'logout.php'];
        $currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($currentPage, $allowedPages, true)) {
            redirect('shopfloor.php');
        }
    }
}

function team_accessible(PDO $pdo, int $teamId, int $userId): bool
{
    if (is_admin($pdo, $userId)) {
        $teamExistsStmt = $pdo->prepare('SELECT 1 FROM teams WHERE id = ?');
        $teamExistsStmt->execute([$teamId]);
        return (bool) $teamExistsStmt->fetchColumn();
    }

    $stmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$teamId, $userId]);

    return (bool) $stmt->fetchColumn();
}

function project_for_user(PDO $pdo, int $projectId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, t.name AS team_name,
                u.name AS leader_name, u.email AS leader_email
         FROM projects p
         INNER JOIN teams t ON t.id = p.team_id
         INNER JOIN team_members tm ON tm.team_id = t.id
         LEFT JOIN users u ON u.id = p.leader_user_id
         WHERE p.id = ? AND tm.user_id = ?'
    );
    $stmt->execute([$projectId, $userId]);

    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    return $project ?: null;
}



function task_creation_field_catalog(): array
{
    return [
        'title' => ['label' => 'Título', 'type' => 'text', 'default_visible' => true, 'default_required' => true, 'is_builtin' => true],
        'description' => ['label' => 'Descrição', 'type' => 'text', 'default_visible' => true, 'default_required' => false, 'is_builtin' => true],
        'estimated_minutes' => ['label' => 'Previsto (min)', 'type' => 'number', 'default_visible' => true, 'default_required' => false, 'is_builtin' => true],
        'assignee_user_id' => ['label' => 'Atribuído', 'type' => 'select', 'default_visible' => true, 'default_required' => false, 'is_builtin' => true],
        'checklist_template_id' => ['label' => 'Checklist (modelo)', 'type' => 'select', 'default_visible' => true, 'default_required' => false, 'is_builtin' => true],
        'new_checklist_items' => ['label' => 'Checklist (novos itens)', 'type' => 'textarea', 'default_visible' => true, 'default_required' => false, 'is_builtin' => true],
    ];
}

function task_creation_custom_fields(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('SELECT field_key, label, field_type, options_json, sort_order FROM task_custom_fields WHERE team_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$teamId]);

    $customFields = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fieldKey = (string) ($row['field_key'] ?? '');
        if ($fieldKey === '') {
            continue;
        }

        $options = [];
        if (!empty($row['options_json'])) {
            $decoded = json_decode((string) $row['options_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $option) {
                    $optionText = trim((string) $option);
                    if ($optionText !== '') {
                        $options[] = $optionText;
                    }
                }
            }
        }

        $fieldType = (string) ($row['field_type'] ?? 'text');
        if (!in_array($fieldType, ['text', 'textarea', 'number', 'date', 'select'], true)) {
            $fieldType = 'text';
        }

        $customFields[$fieldKey] = [
            'label' => (string) ($row['label'] ?? $fieldKey),
            'type' => $fieldType,
            'options' => $options,
            'default_visible' => true,
            'default_required' => false,
            'is_builtin' => false,
        ];
    }

    return $customFields;
}

function task_creation_field_catalog_for_team(PDO $pdo, int $teamId): array
{
    return array_merge(task_creation_field_catalog(), task_creation_custom_fields($pdo, $teamId));
}

function task_creation_field_rules(PDO $pdo, int $teamId, ?int $projectId = null, ?array $catalog = null): array
{
    $fieldCatalog = $catalog ?? task_creation_field_catalog_for_team($pdo, $teamId);
    $rules = [];
    foreach ($fieldCatalog as $fieldKey => $meta) {
        $rules[$fieldKey] = [
            'label' => $meta['label'],
            'is_visible' => !empty($meta['default_visible']),
            'is_required' => !empty($meta['default_required']),
            'type' => (string) ($meta['type'] ?? 'text'),
            'options' => $meta['options'] ?? [],
            'is_builtin' => !empty($meta['is_builtin']),
        ];
    }

    $stmt = $pdo->prepare('SELECT project_id, field_key, is_visible, is_required FROM task_creation_field_rules WHERE team_id = ? AND (project_id IS NULL OR project_id = ?)');
    $stmt->execute([$teamId, $projectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    usort($rows, static function (array $a, array $b): int {
        $aProject = $a['project_id'] !== null ? 1 : 0;
        $bProject = $b['project_id'] !== null ? 1 : 0;
        return $aProject <=> $bProject;
    });

    foreach ($rows as $row) {
        $fieldKey = (string) ($row['field_key'] ?? '');
        if (!array_key_exists($fieldKey, $rules)) {
            continue;
        }

        $rules[$fieldKey]['is_visible'] = (int) $row['is_visible'] === 1;
        $rules[$fieldKey]['is_required'] = (int) $row['is_required'] === 1;
    }

    return $rules;
}

function task_creation_field_key_from_label(string $label): string
{
    $base = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : $label;
    if (!is_string($base) || $base === '') {
        $base = $label;
    }
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: '';
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'campo';
    }

    return 'custom_' . $base;
}


function string_contains(string $haystack, string $needle): bool
{
    if (function_exists('str_contains')) {
        return str_contains($haystack, $needle);
    }

    return $needle == '' || strpos($haystack, $needle) !== false;
}


function format_user_picker_label(array $user): string
{
    $userId = (int) ($user['id'] ?? 0);
    $userNumber = trim((string) ($user['user_number'] ?? ''));
    $userName = trim((string) ($user['name'] ?? ''));
    $labelNumber = $userNumber !== '' ? $userNumber : (string) $userId;

    return trim($labelNumber . ' - ' . $userName, ' -');
}

function h(?string $value): string
{
    $text = (string) $value;

    if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8') && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1,Windows-1252,UTF-8');
        if ($converted !== false) {
            $text = $converted;
        }
    }

    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function log_app_event(PDO $pdo, ?int $userId, string $eventType, string $description, array $context = []): void
{
    $contextJson = null;
    if (count($context) > 0) {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $contextJson = $encoded;
        }
    }

    $stmt = $pdo->prepare('INSERT INTO app_logs(user_id, event_type, description, context_json) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $eventType, $description, $contextJson]);
}


function record_ticket_status_history(PDO $pdo, int $ticketId, ?string $fromStatus, string $toStatus, ?int $changedBy): void
{
    $stmt = $pdo->prepare('INSERT INTO team_ticket_status_history(ticket_id, from_status, to_status, changed_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([$ticketId, $fromStatus, $toStatus, $changedBy]);
}

function task_badge_class(string $status): string
{
    return match ($status) {
        'in_progress' => 'warning',
        'done' => 'success',
        default => 'secondary',
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'in_progress' => 'Em Progresso',
        'done' => 'Concluída',
        default => 'Por Fazer',
    };
}

function deliver_report(string $email, string $subject, string $body): bool
{
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = @mail($email, $subject, $body, $headers);

    if (!$sent) {
        $logLine = sprintf("[%s] TO:%s | %s\n%s\n---\n", date('Y-m-d H:i:s'), $email, $subject, $body);
        file_put_contents(__DIR__ . '/reports_sent.log', $logLine, FILE_APPEND);
    }

    return $sent;
}

function taskforce_weekday_label_pt(int $weekday): string
{
    return match ($weekday) {
        1 => 'seg',
        2 => 'ter',
        3 => 'qua',
        4 => 'qui',
        5 => 'sex',
        6 => 'sáb',
        7 => 'dom',
        default => '',
    };
}

function taskforce_format_minutes_signed(int $minutes): string
{
    $prefix = $minutes < 0 ? '-' : '';
    $absMinutes = abs($minutes);
    return sprintf('%s%02d:%02d', $prefix, intdiv($absMinutes, 60), $absMinutes % 60);
}

function taskforce_generate_monthly_attendance_report(PDO $pdo, array $user, DateTimeImmutable $referenceDate): array
{
    $periodStart = $referenceDate->modify('first day of previous month')->setTime(0, 0, 0);
    $periodEnd = $referenceDate->modify('last day of previous month')->setTime(23, 59, 59);
    $periodStartDate = $periodStart->format('Y-m-d');
    $periodEndDate = $periodEnd->format('Y-m-d');
    $reportMonthLabel = ucfirst(strftime('%B de %Y', $periodStart->getTimestamp()));

    $scheduleStmt = $pdo->prepare(
        'SELECT s.name, s.start_time, s.end_time, s.break_minutes, s.weekdays_mask
         FROM hr_schedules s
         INNER JOIN users u ON u.schedule_id = s.id
         WHERE u.id = ?
         LIMIT 1'
    );
    $scheduleStmt->execute([(int) ($user['id'] ?? 0)]);
    $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $entryStmt = $pdo->prepare(
        'SELECT id, entry_type, occurred_at, validated_at
         FROM shopfloor_time_entries
         WHERE user_id = ?
           AND occurred_at BETWEEN ? AND ?
         ORDER BY occurred_at ASC'
    );
    $entryStmt->execute([(int) ($user['id'] ?? 0), $periodStartDate . ' 00:00:00', $periodEndDate . ' 23:59:59']);
    $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

    $entriesByDate = [];
    foreach ($entries as $entry) {
        $workDate = date('Y-m-d', strtotime((string) ($entry['occurred_at'] ?? '')));
        if (!isset($entriesByDate[$workDate])) {
            $entriesByDate[$workDate] = [];
        }
        $entriesByDate[$workDate][] = $entry;
    }

    $overrideStmt = $pdo->prepare(
        'SELECT work_date, bh_minutes, reason
         FROM shopfloor_bh_overrides
         WHERE user_id = ?
           AND work_date BETWEEN ? AND ?'
    );
    $overrideStmt->execute([(int) ($user['id'] ?? 0), $periodStartDate, $periodEndDate]);
    $overrideMap = [];
    foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $overrideRow) {
        $overrideMap[(string) $overrideRow['work_date']] = $overrideRow;
    }

    $vacationStmt = $pdo->prepare(
        'SELECT start_date, end_date, status, notes
         FROM hr_vacation_events
         WHERE user_id = ?
           AND end_date >= ?
           AND start_date <= ?'
    );
    $vacationStmt->execute([(int) ($user['id'] ?? 0), $periodStartDate, $periodEndDate]);
    $vacations = $vacationStmt->fetchAll(PDO::FETCH_ASSOC);

    $holidayStmt = $pdo->prepare(
        'SELECT title, event_type, start_date, end_date
         FROM hr_calendar_events
         WHERE end_date >= ?
           AND start_date <= ?'
    );
    $holidayStmt->execute([$periodStartDate, $periodEndDate]);
    $calendarEvents = $holidayStmt->fetchAll(PDO::FETCH_ASSOC);

    $vacationBalanceStmt = $pdo->prepare('SELECT assigned_days FROM hr_vacation_balances WHERE user_id = ? AND year = ? LIMIT 1');
    $vacationBalanceStmt->execute([(int) ($user['id'] ?? 0), (int) $periodStart->format('Y')]);
    $assignedVacationDays = (float) ($vacationBalanceStmt->fetchColumn() ?: 0);

    $approvedVacationDays = 0.0;
    $period = new DatePeriod($periodStart, new DateInterval('P1D'), $periodEnd->modify('+1 day'));
    $rows = [];
    $totalWorkedMinutes = 0;
    $totalBhMinutes = 0;
    $daysWithEntries = 0;
    $daysValidated = 0;

    foreach ($period as $day) {
        $date = $day->format('Y-m-d');
        $weekday = (int) $day->format('N');
        $weekdayLabel = taskforce_weekday_label_pt($weekday);
        $dayEntries = $entriesByDate[$date] ?? [];
        $times = array_map(static fn(array $entry): string => date('H:i', strtotime((string) ($entry['occurred_at'] ?? ''))), $dayEntries);

        $effectiveMinutes = 0;
        $openIn = null;
        foreach ($dayEntries as $entry) {
            $entryType = (string) ($entry['entry_type'] ?? '');
            $timestamp = strtotime((string) ($entry['occurred_at'] ?? ''));
            if ($timestamp === false) {
                continue;
            }
            if ($entryType === 'entrada') {
                $openIn = $timestamp;
            } elseif ($entryType === 'saida' && $openIn !== null && $timestamp > $openIn) {
                $effectiveMinutes += (int) round(($timestamp - $openIn) / 60);
                $openIn = null;
            }
        }

        $scheduleApplies = $schedule !== null && in_array((string) $weekday, array_filter(explode(',', (string) ($schedule['weekdays_mask'] ?? ''))), true);
        $targetMinutes = 0;
        if ($scheduleApplies) {
            [$startHour, $startMinute] = array_map('intval', explode(':', (string) $schedule['start_time']));
            [$endHour, $endMinute] = array_map('intval', explode(':', (string) $schedule['end_time']));
            $targetMinutes = (($endHour * 60) + $endMinute) - (($startHour * 60) + $startMinute) - (int) ($schedule['break_minutes'] ?? 0);
            if ($targetMinutes < 0) {
                $targetMinutes = 0;
            }
        }

        $justification = '';
        $typeLabel = count($dayEntries) >= 4 ? 'Normal' : (count($dayEntries) > 0 ? 'Parcial' : 'Folga');

        foreach ($calendarEvents as $event) {
            if ($date >= (string) $event['start_date'] && $date <= (string) $event['end_date']) {
                $justification = (string) ($event['event_type'] ?: 'Calendário') . ': ' . (string) $event['title'];
                $typeLabel = 'Feriado';
                break;
            }
        }

        foreach ($vacations as $vacation) {
            if ($date >= (string) $vacation['start_date'] && $date <= (string) $vacation['end_date']) {
                $note = trim((string) ($vacation['notes'] ?? ''));
                $justification = (string) $vacation['status'] . ($note !== '' ? ' · ' . $note : '');
                $typeLabel = 'Férias';
                if ((string) ($vacation['status'] ?? '') === 'Aprovado' && $weekday <= 5) {
                    $approvedVacationDays += 1.0;
                }
                break;
            }
        }

        if (!$scheduleApplies && !$dayEntries && $justification === '') {
            $typeLabel = 'Folga';
        }

        $isValidated = !empty($dayEntries) && count(array_filter($dayEntries, static fn(array $entry): bool => !empty($entry['validated_at']))) === count($dayEntries);
        if ($isValidated) {
            $daysValidated++;
        }
        if ($dayEntries) {
            $daysWithEntries++;
        }

        $bhMinutes = 0;
        if (isset($overrideMap[$date])) {
            $bhMinutes = (int) ($overrideMap[$date]['bh_minutes'] ?? 0);
            if ($justification === '' && !empty($overrideMap[$date]['reason'])) {
                $justification = 'Ajuste BH: ' . (string) $overrideMap[$date]['reason'];
            }
        } elseif ($scheduleApplies || $dayEntries) {
            $bhMinutes = $effectiveMinutes - $targetMinutes;
        }

        $totalWorkedMinutes += $effectiveMinutes;
        $totalBhMinutes += $bhMinutes;

        $row = [
            'date' => $day->format('d/m/Y'),
            'weekday' => $weekdayLabel,
            'type' => $typeLabel,
            'slots' => array_pad($times, 8, '--:--'),
            'bh' => taskforce_format_minutes_signed($bhMinutes),
            'justification' => $justification !== '' ? $justification : ($isValidated ? 'Validado' : ''),
        ];
        $rows[] = $row;
    }

    $usedVacationDays = $approvedVacationDays;
    $vacationBalance = max(0, $assignedVacationDays - $usedVacationDays);

    $companyName = app_setting($pdo, 'company_name', 'TaskForce');
    $companyAddress = app_setting($pdo, 'company_address', '');
    $companyPhone = app_setting($pdo, 'company_phone', '');
    $companyEmail = app_setting($pdo, 'company_email', '');

    $lines = [];
    $lines[] = $companyName;
    if ($companyAddress !== '') {
        $lines[] = $companyAddress;
    }
    if ($companyPhone !== '' || $companyEmail !== '') {
        $lines[] = trim(($companyPhone !== '' ? 'Telefone: ' . $companyPhone : '') . ($companyPhone !== '' && $companyEmail !== '' ? ' · ' : '') . ($companyEmail !== '' ? 'Email: ' . $companyEmail : ''));
    }
    $lines[] = str_repeat('=', 72);
    $lines[] = 'Mapa mensal de picagens';
    $lines[] = 'Período: ' . $periodStart->format('d/m/Y') . ' - ' . $periodEnd->format('d/m/Y');
    $lines[] = 'Colaborador: ' . (string) ($user['name'] ?? '');
    $lines[] = 'Número: ' . ((string) ($user['user_number'] ?? '') !== '' ? (string) $user['user_number'] : '—');
    $lines[] = 'Departamento: ' . ((string) ($user['department'] ?? '') !== '' ? (string) $user['department'] : '—');
    $lines[] = 'Horário: ' . ($schedule ? ((string) $schedule['name'] . ' (' . (string) $schedule['start_time'] . '-' . (string) $schedule['end_time'] . ')') : 'Não configurado');
    $lines[] = 'Mês de referência: ' . $reportMonthLabel;
    $lines[] = str_repeat('-', 72);
    $lines[] = sprintf('%-11s %-3s %-9s %-47s %-6s %s', 'Data', 'Dia', 'Tipo', 'Picagens', 'BH', 'Justificação');
    $lines[] = str_repeat('-', 72);

    foreach ($rows as $row) {
        $slotsText = implode(' ', $row['slots']);
        $lines[] = sprintf(
            '%-11s %-3s %-9s %-47s %-6s %s',
            $row['date'],
            $row['weekday'],
            substr((string) $row['type'], 0, 9),
            substr($slotsText, 0, 47),
            $row['bh'],
            $row['justification']
        );
    }

    $lines[] = str_repeat('-', 72);
    $lines[] = 'Resumo mensal';
    $lines[] = '- Dias com picagens: ' . $daysWithEntries;
    $lines[] = '- Dias totalmente validados: ' . $daysValidated;
    $lines[] = '- Horas trabalhadas: ' . taskforce_format_minutes_signed($totalWorkedMinutes);
    $lines[] = '- Saldo BH do mês: ' . taskforce_format_minutes_signed($totalBhMinutes);
    $lines[] = '- Saldo de férias estimado: ' . number_format($vacationBalance, 1, ',', '') . ' dias';
    $lines[] = '';
    $lines[] = 'Notas:';
    $lines[] = '- Este relatório é informativo e reflete os registos validados/guardados no TaskForce.';
    $lines[] = '- Caso identifique alguma divergência, contacte RH para análise/retificação.';

    return [
        'subject' => '[TaskForce RH] Mapa mensal de picagens - ' . (string) ($user['name'] ?? '') . ' - ' . $periodStart->format('m/Y'),
        'body' => implode(PHP_EOL, $lines),
        'period_start' => $periodStartDate,
        'period_end' => $periodEndDate,
        'report_month_label' => $reportMonthLabel,
    ];
}

function app_setting(PDO $pdo, string $settingKey, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$settingKey]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : $default;
}

function set_app_setting(PDO $pdo, string $settingKey, string $settingValue): void
{
    $updateStmt = $pdo->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
    $updateStmt->execute([$settingValue, $settingKey]);

    if ($updateStmt->rowCount() > 0) {
        return;
    }

    $insertStmt = $pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES (?, ?)');

    try {
        $insertStmt->execute([$settingKey, $settingValue]);
    } catch (PDOException $e) {
        $updateStmt->execute([$settingValue, $settingKey]);
    }
}

function default_ticket_statuses(): array
{
    return [
        [
            'value' => 'open',
            'label' => 'Aberto',
            'is_completed' => false,
            'sort_order' => 10,
            'color' => '#facc15',
        ],
        [
            'value' => 'done',
            'label' => 'Concluído',
            'is_completed' => true,
            'sort_order' => 20,
            'color' => '#22c55e',
        ],
    ];
}

function ticket_statuses(PDO $pdo): array
{
    $raw = app_setting($pdo, 'ticket_statuses_json', '');
    if (!is_string($raw) || trim($raw) === '') {
        return default_ticket_statuses();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return default_ticket_statuses();
    }

    $sanitized = [];
    foreach ($decoded as $status) {
        if (!is_array($status)) {
            continue;
        }

        $value = strtolower(trim((string) ($status['value'] ?? '')));
        $label = trim((string) ($status['label'] ?? ''));
        $isCompleted = !empty($status['is_completed']);
        $sortOrder = isset($status['sort_order']) ? (int) $status['sort_order'] : 0;
        $color = strtoupper(trim((string) ($status['color'] ?? '')));

        if ($value === '' || $label === '') {
            continue;
        }

        $value = preg_replace('/[^a-z0-9_\-]/', '_', $value) ?: '';
        if ($value === '' || isset($sanitized[$value])) {
            continue;
        }

        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $color = $isCompleted ? '#22C55E' : '#FACC15';
        }

        $sanitized[$value] = [
            'value' => $value,
            'label' => $label,
            'is_completed' => $isCompleted,
            'sort_order' => $sortOrder,
            'color' => $color,
        ];
    }

    if (count($sanitized) === 0) {
        return default_ticket_statuses();
    }

    uasort($sanitized, static function (array $a, array $b): int {
        $left = (int) ($a['sort_order'] ?? 0);
        $right = (int) ($b['sort_order'] ?? 0);

        if ($left === $right) {
            return strcmp((string) $a['label'], (string) $b['label']);
        }

        return $left <=> $right;
    });

    return array_values($sanitized);
}

function ticket_status_value_exists(PDO $pdo, string $value): bool
{
    foreach (ticket_statuses($pdo) as $status) {
        if ((string) $status['value'] === $value) {
            return true;
        }
    }

    return false;
}

function default_open_ticket_status(PDO $pdo): string
{
    foreach (ticket_statuses($pdo) as $status) {
        if (empty($status['is_completed'])) {
            return (string) $status['value'];
        }
    }

    $statuses = ticket_statuses($pdo);
    return (string) ($statuses[0]['value'] ?? 'open');
}

function ticket_status_is_completed(PDO $pdo, string $value): bool
{
    foreach (ticket_statuses($pdo) as $status) {
        if ((string) $status['value'] === $value) {
            return !empty($status['is_completed']);
        }
    }

    return false;
}

function ticket_status_label(PDO $pdo, string $value): string
{
    foreach (ticket_statuses($pdo) as $status) {
        if ((string) $status['value'] === $value) {
            return (string) $status['label'];
        }
    }

    return ucfirst(str_replace(['_', '-'], ' ', $value));
}

function ticket_status_badge_class(PDO $pdo, string $value): string
{
    return ticket_status_is_completed($pdo, $value) ? 'text-bg-success' : 'text-bg-warning';
}

function ticket_status_color(PDO $pdo, string $value): string
{
    foreach (ticket_statuses($pdo) as $status) {
        if ((string) $status['value'] === $value) {
            $color = strtoupper(trim((string) ($status['color'] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
                return $color;
            }

            return !empty($status['is_completed']) ? '#22C55E' : '#FACC15';
        }
    }

    return '#E5E7EB';
}

function ticket_status_badge_style(PDO $pdo, string $value): string
{
    $hex = ticket_status_color($pdo, $value);

    return 'background-color: ' . $hex . '; color: #111827; border: 1px solid rgba(0, 0, 0, 0.08);';
}

function save_brand_logo(array $file, string $prefix): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_file($file['tmp_name'])) {
        return null;
    }

    $tmpName = $file['tmp_name'];
    $mimeType = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detectedMime = finfo_file($finfo, $tmpName);
            if (is_string($detectedMime)) {
                $mimeType = $detectedMime;
            }
            finfo_close($finfo);
        }
    }

    if ($mimeType === '' && function_exists('mime_content_type')) {
        $detectedMime = mime_content_type($tmpName);
        if (is_string($detectedMime)) {
            $mimeType = $detectedMime;
        }
    }

    if ($mimeType === '') {
        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mimeFromExtension = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
        ];
        $mimeType = $mimeFromExtension[$extension] ?? '';
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mimeType])) {
        return null;
    }

    $uploadDir = __DIR__ . '/assets/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = $prefix . '_' . time() . '.' . $allowed[$mimeType];
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return null;
    }

    return 'assets/uploads/' . $filename;
}

function task_time_delta(?int $estimatedMinutes, ?int $actualMinutes): ?int
{
    if ($estimatedMinutes === null || $actualMinutes === null) {
        return null;
    }

    return $actualMinutes - $estimatedMinutes;
}

function parse_duration_to_minutes(?string $input): ?int
{
    $value = trim((string) $input);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d{1,3}):(\d{2}):(\d{2})$/', $value, $matches)) {
        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];
        if ($minutes > 59 || $seconds > 59) {
            return null;
        }

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    if (ctype_digit($value)) {
        return max(0, ((int) $value) * 60);
    }

    return null;
}

function format_minutes(?int $minutes): string
{
    if ($minutes === null) {
        return '00:00:00';
    }

    $totalSeconds = max(0, $minutes);
    $hours = intdiv($totalSeconds, 3600);
    $remaining = $totalSeconds % 3600;
    $mins = intdiv($remaining, 60);
    $seconds = $remaining % 60;

    return sprintf('%02d:%02d:%02d', $hours, $mins, $seconds);
}


function generate_ticket_code(PDO $pdo): string
{
    do {
        $code = 'TCK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $pdo->prepare('SELECT 1 FROM team_tickets WHERE ticket_code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());

    return $code;
}

function parse_ticket_description(string $description): array
{
    $normalized = str_replace("\r\n", "\n", trim($description));
    if ($normalized === '') {
        return ['summary' => '', 'details_title' => '', 'details' => []];
    }

    if (!preg_match('/\n\nDetalhes de ([^\n:]+):\n((?:- .*\n?)+)/u', $normalized, $matches, PREG_OFFSET_CAPTURE)) {
        return ['summary' => $normalized, 'details_title' => '', 'details' => []];
    }

    $markerOffset = (int) $matches[0][1];
    $summary = trim(substr($normalized, 0, $markerOffset));
    $detailsTitle = trim($matches[1][0]);
    $detailsBlock = trim($matches[2][0]);

    $details = [];
    foreach (preg_split('/\n+/', $detailsBlock) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '- ')) {
            $line = trim(substr($line, 2));
        }
        if ($line !== '') {
            $details[] = $line;
        }
    }

    return [
        'summary' => $summary,
        'details_title' => $detailsTitle,
        'details' => $details,
    ];
}


function default_recurring_task_recurrence_options(): array
{
    return [
        ['value' => 'daily', 'label' => 'Diária', 'enabled' => true],
        ['value' => 'weekly', 'label' => 'Semanal', 'enabled' => true],
        ['value' => 'biweekly', 'label' => '15 em 15 dias', 'enabled' => true],
        ['value' => 'monthly', 'label' => 'Mensal', 'enabled' => true],
        ['value' => 'bimonthly', 'label' => 'Bi-mestral', 'enabled' => true],
        ['value' => 'semiannual', 'label' => 'Semestral', 'enabled' => true],
        ['value' => 'yearly', 'label' => 'Anual', 'enabled' => true],
    ];
}

function default_pending_ticket_department_options(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC');
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $options = [];
    foreach ($teams as $team) {
        $teamId = (int) ($team['id'] ?? 0);
        if ($teamId <= 0) {
            continue;
        }

        $options[] = [
            'value' => 'team_' . $teamId,
            'label' => trim((string) ($team['name'] ?? '')),
            'enabled' => true,
        ];
    }

    return $options;
}

function pending_ticket_department_catalog(PDO $pdo): array
{
    $defaults = default_pending_ticket_department_options($pdo);
    $defaultByValue = [];
    foreach ($defaults as $option) {
        $defaultByValue[(string) $option['value']] = $option;
    }

    $raw = app_setting($pdo, 'pending_ticket_departments_json', '');
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $catalog = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $value = strtolower(trim((string) ($entry['value'] ?? '')));
        if (!isset($defaultByValue[$value])) {
            continue;
        }

        $catalog[$value] = [
            'value' => $value,
            'label' => (string) $defaultByValue[$value]['label'],
            'enabled' => !empty($entry['enabled']),
        ];
    }

    foreach ($defaults as $defaultOption) {
        $value = (string) $defaultOption['value'];
        if (!isset($catalog[$value])) {
            $catalog[$value] = $defaultOption;
        }
    }

    return array_values($catalog);
}

function recurring_task_recurrence_catalog(PDO $pdo): array
{
    $defaults = default_recurring_task_recurrence_options();
    $defaultByValue = [];
    foreach ($defaults as $option) {
        $defaultByValue[(string) $option['value']] = $option;
    }

    $raw = app_setting($pdo, 'recurring_task_recurrences_json', '');
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $catalog = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $value = strtolower(trim((string) ($entry['value'] ?? '')));
        $label = trim((string) ($entry['label'] ?? ''));
        $enabled = !array_key_exists('enabled', $entry) || !empty($entry['enabled']);

        if (!isset($defaultByValue[$value])) {
            continue;
        }

        if ($label === '') {
            $label = (string) $defaultByValue[$value]['label'];
        }

        $catalog[$value] = [
            'value' => $value,
            'label' => $label,
            'enabled' => $enabled,
        ];
    }

    foreach ($defaults as $defaultOption) {
        $value = (string) $defaultOption['value'];
        if (!isset($catalog[$value])) {
            $catalog[$value] = $defaultOption;
        }
    }

    return array_values($catalog);
}

function recurring_task_recurrence_options(PDO $pdo, bool $onlyEnabled = true): array
{
    $options = [];
    foreach (recurring_task_recurrence_catalog($pdo) as $entry) {
        if ($onlyEnabled && empty($entry['enabled'])) {
            continue;
        }
        $options[(string) $entry['value']] = (string) $entry['label'];
    }

    if ($onlyEnabled && count($options) === 0) {
        $default = default_recurring_task_recurrence_options();
        $first = $default[0] ?? ['value' => 'weekly', 'label' => 'Semanal'];
        $options[(string) $first['value']] = (string) $first['label'];
    }

    return $options;
}

function recurring_task_recurrence_label(PDO $pdo, string $recurrenceType): string
{
    $allOptions = recurring_task_recurrence_options($pdo, false);
    return $allOptions[$recurrenceType] ?? 'Semanal';
}

function recurring_task_anchor_date(array $task): DateTimeImmutable
{
    $createdAt = !empty($task['created_at']) ? new DateTimeImmutable((string) $task['created_at']) : new DateTimeImmutable('today');

    if (!empty($task['start_date'])) {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $task['start_date']);
        if ($startDate instanceof DateTimeImmutable) {
            return $startDate->setTime(0, 0);
        }
    }

    $weekday = (int) ($task['weekday'] ?? 0);
    if ($weekday >= 1 && $weekday <= 7) {
        $monday = $createdAt->modify('monday this week');
        return $monday->modify('+' . ($weekday - 1) . ' day')->setTime(0, 0);
    }

    return $createdAt->setTime(0, 0);
}

function recurring_task_occurrences(PDO $pdo, array $task, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): array
{
    $recurrenceType = (string) ($task['recurrence_type'] ?? 'weekly');
    $allOptions = recurring_task_recurrence_options($pdo, false);
    if (!isset($allOptions[$recurrenceType])) {
        $recurrenceType = 'weekly';
    }

    $anchor = recurring_task_anchor_date($task);
    $date = $anchor;
    $occurrences = [];

    $dayIntervals = [
        'daily' => 1,
        'weekly' => 7,
        'biweekly' => 14,
    ];

    if (isset($dayIntervals[$recurrenceType])) {
        $intervalDays = $dayIntervals[$recurrenceType];
        if ($date < $periodStart) {
            $diffDays = (int) $date->diff($periodStart)->format('%a');
            $steps = (int) floor($diffDays / $intervalDays);
            $date = $date->modify('+' . ($steps * $intervalDays) . ' day');
            while ($date < $periodStart) {
                $date = $date->modify('+' . $intervalDays . ' day');
            }
        }

        while ($date <= $periodEnd) {
            if ($date >= $periodStart) {
                $occurrences[] = $date;
            }
            $date = $date->modify('+' . $intervalDays . ' day');
        }

        return $occurrences;
    }

    $monthIntervals = [
        'monthly' => 1,
        'bimonthly' => 2,
        'semiannual' => 6,
        'yearly' => 12,
    ];

    $intervalMonths = $monthIntervals[$recurrenceType] ?? 1;
    $guard = 0;
    while ($date < $periodStart && $guard < 600) {
        $date = $date->modify('+' . $intervalMonths . ' month');
        $guard++;
    }

    while ($date <= $periodEnd && $guard < 1000) {
        if ($date >= $periodStart) {
            $occurrences[] = $date;
        }
        $date = $date->modify('+' . $intervalMonths . ' month');
        $guard++;
    }

    return $occurrences;
}

function app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($basePath === '' || $basePath === '/') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath;
}
