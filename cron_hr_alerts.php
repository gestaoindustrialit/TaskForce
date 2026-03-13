<?php
require_once __DIR__ . '/helpers.php';

$now = new DateTimeImmutable('now');
$currentTime = $now->format('H:i');
$weekday = (string) ((int) $now->format('N'));
$today = $now->format('Y-m-d');

$stmt = $pdo->prepare('SELECT id, name, alert_type, recipient_email, send_time, weekdays_mask FROM hr_alerts WHERE is_active = 1 AND send_time = ?');
$stmt->execute([$currentTime]);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($alerts as $alert) {
    $days = array_filter(explode(',', (string) $alert['weekdays_mask']));
    if (!in_array($weekday, $days, true)) {
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
}

echo 'Alertas RH processados: ' . count($alerts) . PHP_EOL;
