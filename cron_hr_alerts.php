<?php
require_once __DIR__ . '/helpers.php';

$now = new DateTimeImmutable('now');
$currentTime = $now->format('H:i');
$weekday = (string) ((int) $now->format('N'));
$today = $now->format('Y-m-d');
$isFirstDayOfMonth = $now->format('d') === '01';

$stmt = $pdo->prepare('SELECT id, name, alert_type, recipient_email, send_time, weekdays_mask, selected_user_ids FROM hr_alerts WHERE is_active = 1 AND send_time = ?');
$stmt->execute([$currentTime]);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$processedAlerts = 0;

foreach ($alerts as $alert) {
    $days = array_filter(explode(',', (string) $alert['weekdays_mask']));
    if (!in_array($weekday, $days, true)) {
        continue;
    }

    if ($alert['alert_type'] === 'attendance_monthly_map') {
        if (!$isFirstDayOfMonth) {
            continue;
        }

        $selectedUserIds = array_values(array_filter(array_map('intval', explode(',', (string) ($alert['selected_user_ids'] ?? '')))));
        $userSql = 'SELECT id, name, email, user_number, department
             FROM users
             WHERE is_active = 1
               AND email_notifications_active = 1
               AND pin_only_login = 0
               AND TRIM(email) <> ""';
        $userParams = [];
        if ($selectedUserIds) {
            $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));
            $userSql .= ' AND id IN (' . $placeholders . ')';
            $userParams = $selectedUserIds;
        }
        $userSql .= ' ORDER BY name COLLATE NOCASE ASC';
        $usersStmt = $pdo->prepare($userSql);
        $usersStmt->execute($userParams);
        $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $user) {
            $report = taskforce_generate_monthly_attendance_report($pdo, $user, $now);
            deliver_report((string) $user['email'], (string) $report['subject'], (string) $report['body']);
        }

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

    $headers = 'From: no-reply@taskforce.local';
    $sent = @mail((string) $alert['recipient_email'], $subject, $body, $headers);

    if (!$sent) {
        $line = '[' . date('Y-m-d H:i:s') . '] ALERTA ' . $alert['id'] . ' para ' . $alert['recipient_email'] . PHP_EOL . $subject . PHP_EOL . $body . PHP_EOL;
        @file_put_contents(__DIR__ . '/reports_sent.log', $line, FILE_APPEND);
    }

    $processedAlerts++;
}

echo 'Alertas RH processados: ' . $processedAlerts . PHP_EOL;
