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
    if ($selectedUserIds === []) {
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
    if (!is_array($rows)) {
        return [];
    }

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

function parse_selected_user_ids(array $alert): array
{
    $raw = (string) ($alert['selected_user_ids'] ?? '');
    if (trim($raw) === '') {
        return [];
    }

    $ids = array_map('intval', array_map('trim', explode(',', $raw)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    $ids = array_values(array_unique($ids));

    return $ids;
}

function build_absences_daily_body(PDO $pdo, array $alert, DateTimeImmutable $now): string
{
    $today = $now->format('Y-m-d');

    $body = "Alerta RH: " . (string) ($alert['name'] ?? 'Sem nome') . PHP_EOL;
    $body .= "Data/Hora: " . $now->format('d/m/Y H:i') . PHP_EOL . PHP_EOL;

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
        $body .= "Sem ausências previstas para hoje (" . $today . ")." . PHP_EOL;
        return $body;
    }

    $body .= "Ausências previstas para hoje:" . PHP_EOL;
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
    $body = "Alerta RH: " . (string) ($alert['name'] ?? 'Sem nome') . PHP_EOL;
    $body .= "Data/Hora: " . $now->format('d/m/Y H:i') . PHP_EOL . PHP_EOL;
    $body .= "Tipo de alerta: " . (string) ($alert['alert_type'] ?? 'desconhecido') . PHP_EOL;

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

function send_attendance_monthly_map_alert(PDO $pdo, array $users, DateTimeImmutable $now, int $alertId): bool
{
    $deliveredToAtLeastOneRecipient = false;

    foreach ($users as $user) {
        $recipientEmail = trim((string) ($user['email'] ?? ''));
        $recipientName = trim((string) ($user['name'] ?? 'Sem nome'));

        if ($recipientEmail === '') {
            cron_log_line('ALERTA #' . $alertId . ' attendance_monthly_map ignorou destinatário sem email: ' . $recipientName);
            continue;
        }

        try {
            $report = taskforce_generate_monthly_attendance_report($pdo, $user, $now);
        } catch (Throwable $exception) {
            cron_log_line(
                'ALERTA #' . $alertId
                . ' erro ao gerar mapa mensal para ' . $recipientName
                . ': ' . $exception->getMessage()
            );
            continue;
        }

        $subject = (string) ($report['subject'] ?? '[TaskForce RH] Mapa mensal de picagens');
        $body = (string) ($report['body'] ?? '');

        if ($body === '') {
            cron_log_line('ALERTA #' . $alertId . ' gerou relatório vazio para ' . $recipientName);
            continue;
        }

        $sent = deliver_report($recipientEmail, $subject, $body);

        if ($sent) {
            $deliveredToAtLeastOneRecipient = true;
            cron_log_line('ALERTA #' . $alertId . ' attendance_monthly_map enviado para ' . $recipientEmail);
            continue;
        }

        cron_log_line('ALERTA #' . $alertId . ' attendance_monthly_map falhou para ' . $recipientEmail);
    }

    return $deliveredToAtLeastOneRecipient;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Ligação PDO não disponível após carregar helpers.php');
    }

    if (PHP_SAPI === 'cli') {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    }

    $now = new DateTimeImmutable('now');
    $currentTime = $now->format('H:i');

    cron_log_line('=== Início do cron_hr_alerts.php ===');

    $stmt = $pdo->prepare(
        'SELECT id, name, alert_type, recipient_email, send_time, weekdays_mask, schedule_frequency, monthly_day, selected_user_ids, last_sent_at
         FROM hr_alerts
         WHERE is_active = 1
           AND send_time <= ?'
    );
    $stmt->execute([$currentTime]);

    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($alerts)) {
        $alerts = [];
    }

    cron_log_line('Alertas encontrados para processamento: ' . count($alerts));

    $processedAlerts = 0;
    $markSentStmt = $pdo->prepare('UPDATE hr_alerts SET last_sent_at = ? WHERE id = ?');

    foreach ($alerts as $alert) {
        $alertId = (int) ($alert['id'] ?? 0);
        $alertName = trim((string) ($alert['name'] ?? 'Sem nome'));
        $alertType = trim((string) ($alert['alert_type'] ?? ''));

        cron_log_line('A processar alerta #' . $alertId . ' [' . $alertType . '] ' . $alertName);

        if (!is_hr_alert_due_today($alert, $now)) {
            cron_log_line('ALERTA #' . $alertId . ' não está agendado para hoje.');
            continue;
        }

        if (has_hr_alert_been_sent_today($alert, $now)) {
            cron_log_line('ALERTA #' . $alertId . ' já foi enviado hoje.');
            continue;
        }

        $selectedUserIds = parse_selected_user_ids($alert);

        $selectedUsersMissingDelivery = fetch_selected_users_missing_delivery_requirements($pdo, $selectedUserIds);
        if ($selectedUsersMissingDelivery !== []) {
            $missingLabels = array_map(
                static function (array $user): string {
                    return ((string) ($user['name'] ?? 'Sem nome')) . ' (#' . (int) ($user['id'] ?? 0) . ')';
                },
                $selectedUsersMissingDelivery
            );

            cron_log_line(
                'ALERTA #' . $alertId
                . ' ignorou colaboradores sem requisitos de entrega: '
                . implode(', ', $missingLabels)
            );
        }

        $users = fetch_alert_recipient_users($pdo, $selectedUserIds);

        if ($users === []) {
            cron_log_line('ALERTA #' . $alertId . ' sem destinatários elegíveis após validação.');
            continue;
        }

        $deliveredToAtLeastOneRecipient = false;

        if ($alertType === 'attendance_monthly_map') {
            $deliveredToAtLeastOneRecipient = send_attendance_monthly_map_alert($pdo, $users, $now, $alertId);
        } else {
            $subject = '[TaskForce RH] ' . $alertName;

            if ($alertType === 'absences_daily') {
                $body = build_absences_daily_body($pdo, $alert, $now);
            } else {
                $body = build_generic_alert_body($alert, $now);
            }

            $deliveredToAtLeastOneRecipient = send_standard_alert_to_users($users, $subject, $body, $alertId);
        }

        if (!$deliveredToAtLeastOneRecipient) {
            cron_log_line('ALERTA #' . $alertId . ' falhou para todos os destinatários.');
            continue;
        }

        $markSentStmt->execute([$now->format('Y-m-d H:i:s'), $alertId]);
        $processedAlerts++;

        cron_log_line('ALERTA #' . $alertId . ' marcado como enviado.');
    }

    cron_log_line('=== Fim do cron_hr_alerts.php | Processados: ' . $processedAlerts . ' ===');

    echo 'Alertas RH processados: ' . $processedAlerts . PHP_EOL;
} catch (Throwable $exception) {
    cron_log_line('ERRO FATAL no cron_hr_alerts.php: ' . $exception->getMessage());
    cron_log_line('TRACE: ' . $exception->getTraceAsString());

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Erro no cron_hr_alerts.php: ' . $exception->getMessage() . PHP_EOL);
    } else {
        http_response_code(500);
        echo 'Erro no cron_hr_alerts.php: ' . $exception->getMessage();
    }

    exit(1);
}
