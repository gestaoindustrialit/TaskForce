<?php
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
    $days = array_filter(explode(',', (string) ($alert['weekdays_mask'] ?? '')));
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
    } catch (Exception $exception) {
        return false;
    }

    return $lastSent->format('Y-m-d') === $now->format('Y-m-d');
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

    if ($selectedUserIds) {
        $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));
        $sql .= ' AND id IN (' . $placeholders . ')';
        $params = $selectedUserIds;
    }

    $sql .= ' ORDER BY name COLLATE NOCASE ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function log_hr_alert_cron_event(PDO $pdo, string $eventType, string $description, array $context = []): void
{
    try {
        log_app_event($pdo, null, $eventType, $description, $context);
    } catch (Throwable $exception) {
        $line = '[' . date('Y-m-d H:i:s') . '] LOG_FALHA ' . $eventType . ' | ' . $description . ' | ' . $exception->getMessage() . PHP_EOL;
        @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
    }
}

$now = new DateTimeImmutable('now');
$currentTime = $now->format('H:i');
$today = $now->format('Y-m-d');

$stmt = $pdo->prepare('SELECT id, name, alert_type, recipient_email, send_time, weekdays_mask, schedule_frequency, monthly_day, selected_user_ids, last_sent_at FROM hr_alerts WHERE is_active = 1 AND send_time <= ?');
$stmt->execute([$currentTime]);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$processedAlerts = 0;
$markSentStmt = $pdo->prepare('UPDATE hr_alerts SET last_sent_at = ? WHERE id = ?');

log_hr_alert_cron_event(
    $pdo,
    'hr.alerts.cron.started',
    'Execução do cron de alertas RH iniciada.',
    ['time' => $currentTime, 'alerts_due_by_time' => count($alerts)]
);

foreach ($alerts as $alert) {
    if (!is_hr_alert_due_today($alert, $now) || has_hr_alert_been_sent_today($alert, $now)) {
        continue;
    }

    $selectedUserIds = array_values(array_filter(array_map('intval', explode(',', (string) ($alert['selected_user_ids'] ?? '')))));
    $selectedUsersMissingDelivery = fetch_selected_users_missing_delivery_requirements($pdo, $selectedUserIds);
    if ($selectedUsersMissingDelivery) {
        $missingLabels = array_map(
            static fn(array $user): string => ((string) ($user['name'] ?? 'Sem nome')) . ' (#' . (int) ($user['id'] ?? 0) . ')',
            $selectedUsersMissingDelivery
        );
        $line = '[' . date('Y-m-d H:i:s') . '] ALERTA ' . $alert['id'] . ' ignorou colaboradores sem requisitos de entrega: ' . implode(', ', $missingLabels) . PHP_EOL;
        @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
        log_hr_alert_cron_event(
            $pdo,
            'hr.alerts.recipients.filtered',
            'Alguns colaboradores selecionados não cumprem requisitos de entrega.',
            ['alert_id' => (int) $alert['id'], 'ignored_users' => $missingLabels]
        );
    }

    $users = fetch_alert_recipient_users($pdo, $selectedUserIds);
    if (!$users) {
        $line = '[' . date('Y-m-d H:i:s') . '] ALERTA ' . $alert['id'] . ' sem destinatários elegíveis após validar e-mail/notificações.' . PHP_EOL;
        @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
        log_hr_alert_cron_event(
            $pdo,
            'hr.alerts.recipients.none',
            'Sem destinatários elegíveis para envio.',
            ['alert_id' => (int) $alert['id'], 'alert_type' => (string) $alert['alert_type']]
        );
        continue;
    }

    if ($alert['alert_type'] === 'attendance_monthly_map') {
        $deliveredToAtLeastOneRecipient = false;
        foreach ($users as $user) {
            $report = taskforce_generate_monthly_attendance_report($pdo, $user, $now);
            $sent = deliver_report((string) $user['email'], (string) $report['subject'], (string) $report['body']);
            if ($sent) {
                $deliveredToAtLeastOneRecipient = true;
            }
        }

        if (!$deliveredToAtLeastOneRecipient) {
            $line = '[' . date('Y-m-d H:i:s') . '] ALERTA ' . $alert['id'] . ' falhou para todos os destinatários no tipo attendance_monthly_map' . PHP_EOL;
            @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
            log_hr_alert_cron_event(
                $pdo,
                'hr.alerts.delivery.failed',
                'Falha de envio para todos os destinatários (attendance_monthly_map).',
                ['alert_id' => (int) $alert['id'], 'alert_type' => 'attendance_monthly_map']
            );
            continue;
        }

        $markSentStmt->execute([$now->format('Y-m-d H:i:s'), (int) $alert['id']]);
        log_hr_alert_cron_event(
            $pdo,
            'hr.alerts.delivery.success',
            'Alerta attendance_monthly_map enviado com sucesso.',
            ['alert_id' => (int) $alert['id'], 'recipients' => count($users)]
        );
        $processedAlerts++;
        continue;
    }

    $subject = '[TaskForce RH] ' . $alert['name'];
    $body = "Alerta RH: {$alert['name']}\nData/Hora: " . $now->format('d/m/Y H:i') . "\n\n";

    if ($alert['alert_type'] === 'absences_daily') {
        $absStmt = $pdo->prepare(
            'SELECT u.name, v.start_date, v.end_date, v.status
             FROM hr_vacation_events v
             INNER JOIN users u ON u.id = v.user_id
             WHERE ? BETWEEN v.start_date AND v.end_date
             ORDER BY u.name COLLATE NOCASE ASC'
        );
        $absStmt->execute([$today]);
        $rows = $absStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) === 0) {
            $body .= "Sem ausências previstas para hoje ({$today}).\n";
        } else {
            $body .= "Ausências previstas para hoje:\n";
            foreach ($rows as $row) {
                $body .= '- ' . $row['name'] . ' (' . $row['start_date'] . ' a ' . $row['end_date'] . ', ' . $row['status'] . ")\n";
            }
        }
    } else {
        $body .= "Tipo de alerta: {$alert['alert_type']}\n";
    }

    $headers = taskforce_default_mail_headers();
    $deliveredToAtLeastOneRecipient = false;
    foreach ($users as $user) {
        $recipientEmail = (string) ($user['email'] ?? '');
        if ($recipientEmail === '') {
            continue;
        }

        $sent = @mail($recipientEmail, $subject, $body, $headers);

        if (!$sent) {
            $line = '[' . date('Y-m-d H:i:s') . '] ALERTA ' . $alert['id'] . ' para ' . $recipientEmail . PHP_EOL . $subject . PHP_EOL . $body . PHP_EOL;
            @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
            continue;
        }

        $deliveredToAtLeastOneRecipient = true;
    }

    if (!$deliveredToAtLeastOneRecipient) {
        $line = '[' . date('Y-m-d H:i:s') . '] ALERTA ' . $alert['id'] . ' falhou para todos os destinatários no tipo ' . $alert['alert_type'] . PHP_EOL;
        @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
        log_hr_alert_cron_event(
            $pdo,
            'hr.alerts.delivery.failed',
            'Falha de envio para todos os destinatários.',
            ['alert_id' => (int) $alert['id'], 'alert_type' => (string) $alert['alert_type']]
        );
        continue;
    }

    $markSentStmt->execute([$now->format('Y-m-d H:i:s'), (int) $alert['id']]);
    log_hr_alert_cron_event(
        $pdo,
        'hr.alerts.delivery.success',
        'Alerta RH enviado com sucesso.',
        ['alert_id' => (int) $alert['id'], 'alert_type' => (string) $alert['alert_type'], 'recipients' => count($users)]
    );
    $processedAlerts++;
}

log_hr_alert_cron_event(
    $pdo,
    'hr.alerts.cron.finished',
    'Execução do cron de alertas RH concluída.',
    ['processed_alerts' => $processedAlerts]
);

echo 'Alertas RH processados: ' . $processedAlerts . PHP_EOL;
