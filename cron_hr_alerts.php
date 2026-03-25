<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function normalize_cron_schedule_frequency(string $value): string
{
    return in_array($value, ['weekly', 'monthly'], true) ? $value : 'weekly';
}

function normalize_cron_monthly_day($value): int
{
    $day = (int) $value;

    if ($day < 1) {
        return 1;
    }

    if ($day > 31) {
        return 31;
    }

    return $day;
}

function cron_log_line(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
}

function log_hr_alert_cron_event(PDO $pdo, string $eventType, string $description, array $context = []): void
{
    try {
        log_app_event($pdo, null, $eventType, $description, $context);
    } catch (Throwable $exception) {
        try {
            static $fallbackUserId = null;
            static $fallbackResolved = false;

            if (!$fallbackResolved) {
                $fallbackResolved = true;

                $userIdStmt = $pdo->query('SELECT id FROM users WHERE is_active = 1 AND is_admin = 1 ORDER BY id ASC LIMIT 1');
                $fallbackUserId = (int) ($userIdStmt ? $userIdStmt->fetchColumn() : 0);

                if ($fallbackUserId <= 0) {
                    $anyUserStmt = $pdo->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
                    $fallbackUserId = (int) ($anyUserStmt ? $anyUserStmt->fetchColumn() : 0);
                }
            }

            if ($fallbackUserId > 0) {
                log_app_event($pdo, $fallbackUserId, $eventType, $description, $context + ['log_user_fallback' => true]);
                return;
            }
        } catch (Throwable $innerException) {
            cron_log_line('LOG_FALHA_FALLBACK ' . $eventType . ' | ' . $innerException->getMessage());
        }

        cron_log_line('LOG_FALHA ' . $eventType . ' | ' . $description . ' | ' . $exception->getMessage());
    }
}

function parse_selected_user_ids(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }

    $ids = array_map('intval', array_map('trim', explode(',', $raw)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

    return array_values(array_unique($ids));
}

function is_hr_alert_due_today(array $alert, DateTimeImmutable $now): bool
{
    $scheduleFrequency = normalize_cron_schedule_frequency((string) ($alert['schedule_frequency'] ?? 'weekly'));

    if ($scheduleFrequency === 'monthly') {
        $configuredDay = normalize_cron_monthly_day($alert['monthly_day'] ?? 1);
        $lastDayOfMonth = (int) $now->format('t');
        $effectiveDay = min($configuredDay, $lastDayOfMonth);

        return (int) $now->format('j') === $effectiveDay;
    }

    $weekday = (string) ((int) $now->format('N'));
    $days = array_values(array_filter(array_map('trim', explode(',', (string) ($alert['weekdays_mask'] ?? '')))));

    return in_array($weekday, $days, true);
}

function has_hr_alert_been_sent_today(array $alert, DateTimeImmutable $now): bool
{
    $lastSentAt = trim((string) ($alert['last_sent_at'] ?? ''));

    if ($lastSentAt === '') {
        return false;
    }

    try {
        $lastSent = new DateTimeImmutable($lastSentAt);
    } catch (Throwable $exception) {
        cron_log_line('Data inválida em last_sent_at no alerta #' . (int) ($alert['id'] ?? 0) . ': ' . $lastSentAt);
        return false;
    }

    return $lastSent->format('Y-m-d') === $now->format('Y-m-d');
}

function has_hr_alert_reached_send_time(array $alert, string $currentTime): bool
{
    $configured = trim((string) ($alert['send_time'] ?? ''));
    if ($configured === '') {
        return false;
    }

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $configured, $matches)) {
        return false;
    }

    $hours = (int) ($matches[1] ?? -1);
    $minutes = (int) ($matches[2] ?? -1);
    if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
        return false;
    }

    $normalizedConfigured = sprintf('%02d:%02d', $hours, $minutes);

    return $normalizedConfigured <= $currentTime;
}

function fetch_alert_recipient_users(PDO $pdo, array $selectedUserIds): array
{
    $sql = 'SELECT id, name, email, user_number, department
            FROM users
            WHERE is_active = 1
              AND email_notifications_active = 1
              AND pin_only_login = 0
              AND TRIM(email) <> ""';

    $params = [];

    if ($selectedUserIds !== []) {
        $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));
        $sql .= ' AND id IN (' . $placeholders . ')';
        $params = $selectedUserIds;
    }

    $sql .= ' ORDER BY name COLLATE NOCASE ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($users) ? $users : [];
}

function fetch_selected_users_missing_delivery_requirements(PDO $pdo, array $selectedUserIds): array
{
    if (!$selectedUserIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, name, email, is_active, email_notifications_active, pin_only_login
         FROM users
         WHERE id IN (' . $placeholders . ')'
    );
    $stmt->execute($selectedUserIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $missing = [];
    foreach ($rows as $row) {
        $isEligible = (int) ($row['is_active'] ?? 0) === 1
            && (int) ($row['email_notifications_active'] ?? 0) === 1
            && (int) ($row['pin_only_login'] ?? 0) === 0
            && trim((string) ($row['email'] ?? '')) !== '';

        if (!$isEligible) {
            $missing[] = $row;
        }
    }

    return $missing;
}

function build_absences_daily_body(PDO $pdo, array $alert, DateTimeImmutable $now): string
{
    $today = $now->format('Y-m-d');

    $body = 'Alerta RH: ' . (string) ($alert['name'] ?? 'Sem nome') . PHP_EOL;
    $body .= 'Data/Hora: ' . $now->format('d/m/Y H:i') . PHP_EOL . PHP_EOL;

    $absStmt = $pdo->prepare(
        'SELECT u.name, v.start_date, v.end_date, v.status
         FROM hr_vacation_events v
         INNER JOIN users u ON u.id = v.user_id
         WHERE ? BETWEEN v.start_date AND v.end_date
         ORDER BY u.name COLLATE NOCASE ASC'
    );
    $absStmt->execute([$today]);

    $rows = $absStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || count($rows) === 0) {
        $body .= 'Sem ausências previstas para hoje (' . $today . ').' . PHP_EOL;
        return $body;
    }

    $body .= 'Ausências previstas para hoje:' . PHP_EOL;
    foreach ($rows as $row) {
        $body .= '- '
            . (string) ($row['name'] ?? 'Sem nome')
            . ' (' . (string) ($row['start_date'] ?? '')
            . ' a ' . (string) ($row['end_date'] ?? '')
            . ', ' . (string) ($row['status'] ?? '') . ')'
            . PHP_EOL;
    }

    return $body;
}

function build_generic_alert_body(array $alert, DateTimeImmutable $now): string
{
    $body = 'Alerta RH: ' . (string) ($alert['name'] ?? 'Sem nome') . PHP_EOL;
    $body .= 'Data/Hora: ' . $now->format('d/m/Y H:i') . PHP_EOL . PHP_EOL;
    $body .= 'Tipo de alerta: ' . (string) ($alert['alert_type'] ?? 'desconhecido') . PHP_EOL;

    return $body;
}

function send_standard_alert_to_users(array $users, string $subject, string $body, int $alertId): bool
{
    $deliveredToAtLeastOneRecipient = false;

    foreach ($users as $user) {
        $recipientEmail = trim((string) ($user['email'] ?? ''));
        $recipientName = trim((string) ($user['name'] ?? 'Sem nome'));

        if ($recipientEmail === '') {
            cron_log_line('ALERTA #' . $alertId . ' ignorou destinatário sem email: ' . $recipientName);
            continue;
        }

        $sent = deliver_report($recipientEmail, $subject, $body);
        if ($sent) {
            $deliveredToAtLeastOneRecipient = true;
            cron_log_line('ALERTA #' . $alertId . ' enviado com sucesso para ' . $recipientEmail);
            continue;
        }

        cron_log_line('ALERTA #' . $alertId . ' falhou para ' . $recipientEmail);
    }

    return $deliveredToAtLeastOneRecipient;
}

function send_attendance_monthly_map_alert(array $users, PDO $pdo, DateTimeImmutable $now, int $alertId): bool
{
    $deliveredToAtLeastOneRecipient = false;

    foreach ($users as $user) {
        $userId = (int) ($user['id'] ?? 0);
        $recipientEmail = trim((string) ($user['email'] ?? ''));

        if ($recipientEmail === '') {
            cron_log_line('ALERTA #' . $alertId . ' ignorou user #' . $userId . ' sem email para mapa mensal.');
            continue;
        }

        try {
            $report = taskforce_generate_monthly_attendance_report($pdo, $user, $now);
        } catch (Throwable $exception) {
            cron_log_line('ALERTA #' . $alertId . ' erro ao gerar mapa mensal para user #' . $userId . ': ' . $exception->getMessage());
            continue;
        }

        $subject = (string) ($report['subject'] ?? '[TaskForce RH] Mapa mensal de picagens');
        $body = (string) ($report['body'] ?? '');

        if (deliver_report($recipientEmail, $subject, $body)) {
            $deliveredToAtLeastOneRecipient = true;
            cron_log_line('ALERTA #' . $alertId . ' mapa mensal enviado com sucesso para ' . $recipientEmail);
            continue;
        }

        cron_log_line('ALERTA #' . $alertId . ' mapa mensal falhou para ' . $recipientEmail);
    }

    return $deliveredToAtLeastOneRecipient;
}

$now = new DateTimeImmutable('now');
$currentTime = $now->format('H:i');
$processedAlerts = 0;

try {
    $alertStmt = $pdo->prepare(
        'SELECT id, name, alert_type, send_time, weekdays_mask, schedule_frequency, monthly_day, selected_user_ids, last_sent_at
         FROM hr_alerts
         WHERE is_active = 1
         ORDER BY send_time ASC, id ASC'
    );
    $alertStmt->execute();
    $alerts = $alertStmt->fetchAll(PDO::FETCH_ASSOC);

    log_hr_alert_cron_event(
        $pdo,
        'hr.alerts.cron.started',
        'Execução do cron de alertas RH iniciada.',
        ['time' => $currentTime, 'criteria' => 'all active alerts', 'active_alerts' => is_array($alerts) ? count($alerts) : 0]
    );
    cron_log_line('CRON RH START time=' . $currentTime . ' active_alerts=' . (is_array($alerts) ? count($alerts) : 0));

    if (!is_array($alerts)) {
        $alerts = [];
    }

    $markSentStmt = $pdo->prepare('UPDATE hr_alerts SET last_sent_at = ? WHERE id = ?');

    foreach ($alerts as $alert) {
        $alertId = (int) ($alert['id'] ?? 0);
        if ($alertId <= 0) {
            continue;
        }

        $isDueByTime = has_hr_alert_reached_send_time($alert, $currentTime);
        $isDueToday = is_hr_alert_due_today($alert, $now);
        $alreadySentToday = has_hr_alert_been_sent_today($alert, $now);
        if (!$isDueByTime || !$isDueToday || $alreadySentToday) {
            cron_log_line(
                'ALERTA ' . $alertId
                . ' ignorado pelo gatilho: due_by_time=' . ($isDueByTime ? '1' : '0')
                . ' due_today=' . ($isDueToday ? '1' : '0')
                . ' already_sent_today=' . ($alreadySentToday ? '1' : '0')
            );
            log_hr_alert_cron_event(
                $pdo,
                'hr.alerts.trigger.skipped',
                'Alerta RH não elegível no gatilho do cron.',
                ['alert_id' => $alertId, 'due_by_time' => $isDueByTime, 'due_today' => $isDueToday, 'already_sent_today' => $alreadySentToday]
            );
            continue;
        }

        $selectedUserIds = parse_selected_user_ids((string) ($alert['selected_user_ids'] ?? ''));
        $selectedUsersMissingDelivery = fetch_selected_users_missing_delivery_requirements($pdo, $selectedUserIds);
        if ($selectedUsersMissingDelivery) {
            $missingLabels = array_map(
                static fn(array $user): string => ((string) ($user['name'] ?? 'Sem nome')) . ' (#' . (int) ($user['id'] ?? 0) . ')',
                $selectedUsersMissingDelivery
            );

            cron_log_line('ALERTA ' . $alertId . ' ignorou colaboradores sem requisitos de entrega: ' . implode(', ', $missingLabels));
            log_hr_alert_cron_event(
                $pdo,
                'hr.alerts.recipients.filtered',
                'Alguns colaboradores selecionados não cumprem requisitos de entrega.',
                ['alert_id' => $alertId, 'ignored_users' => $missingLabels]
            );
        }

        $users = fetch_alert_recipient_users($pdo, $selectedUserIds);
        if (!$users) {
            cron_log_line('ALERTA ' . $alertId . ' sem destinatários elegíveis após validar e-mail/notificações.');
            log_hr_alert_cron_event(
                $pdo,
                'hr.alerts.recipients.none',
                'Sem destinatários elegíveis para envio.',
                ['alert_id' => $alertId, 'alert_type' => (string) ($alert['alert_type'] ?? '')]
            );
            continue;
        }

        $alertType = (string) ($alert['alert_type'] ?? '');
        $deliveredToAtLeastOneRecipient = false;
        log_hr_alert_cron_event(
            $pdo,
            'hr.alerts.delivery.attempt',
            'Tentativa de envio de alerta RH iniciada.',
            ['alert_id' => $alertId, 'alert_type' => $alertType, 'recipients' => count($users)]
        );
        cron_log_line('ALERTA ' . $alertId . ' tentativa de envio iniciada. type=' . $alertType . ' recipients=' . count($users));

        if ($alertType === 'attendance_monthly_map') {
            $deliveredToAtLeastOneRecipient = send_attendance_monthly_map_alert($users, $pdo, $now, $alertId);
        } else {
            $subject = '[TaskForce RH] ' . ((string) ($alert['name'] ?? 'Alerta RH'));
            $body = $alertType === 'absences_daily'
                ? build_absences_daily_body($pdo, $alert, $now)
                : build_generic_alert_body($alert, $now);

            $deliveredToAtLeastOneRecipient = send_standard_alert_to_users($users, $subject, $body, $alertId);
        }

        if (!$deliveredToAtLeastOneRecipient) {
            cron_log_line('ALERTA ' . $alertId . ' falhou para todos os destinatários no tipo ' . $alertType);
            log_hr_alert_cron_event(
                $pdo,
                'hr.alerts.delivery.failed',
                'Falha de envio para todos os destinatários.',
                ['alert_id' => $alertId, 'alert_type' => $alertType]
            );
            continue;
        }

        $markSentStmt->execute([$now->format('Y-m-d H:i:s'), $alertId]);
        log_hr_alert_cron_event(
            $pdo,
            'hr.alerts.delivery.success',
            'Alerta RH enviado com sucesso.',
            ['alert_id' => $alertId, 'alert_type' => $alertType, 'recipients' => count($users)]
        );
        $processedAlerts++;
    }

    log_hr_alert_cron_event(
        $pdo,
        'hr.alerts.cron.finished',
        'Execução do cron de alertas RH concluída.',
        ['processed_alerts' => $processedAlerts]
    );
    cron_log_line('CRON RH FINISH processed_alerts=' . $processedAlerts);

    if (PHP_SAPI === 'cli') {
        echo 'Alertas RH processados: ' . $processedAlerts . PHP_EOL;
    }
} catch (Throwable $exception) {
    cron_log_line('Erro fatal no cron_hr_alerts.php: ' . $exception->getMessage());

    try {
        log_hr_alert_cron_event(
            $pdo,
            'hr.alerts.cron.failed',
            'Execução do cron de alertas RH falhou.',
            ['error' => $exception->getMessage()]
        );
    } catch (Throwable $innerException) {
        cron_log_line('Falha ao gravar erro no app_logs: ' . $innerException->getMessage());
    }

    throw $exception;
}
