<?php
require_once __DIR__ . '/helpers.php';
require_login();

$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$yearStart = sprintf('%04d-01-01', $year);
$yearEnd = sprintf('%04d-12-31', $year);

$eventsStmt = $pdo->prepare('SELECT title, event_type, start_date, end_date FROM hr_calendar_events WHERE start_date <= ? AND end_date >= ? ORDER BY start_date ASC, title ASC');
$eventsStmt->execute([$yearEnd, $yearStart]);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$icsEscape = static function (string $value): string {
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\\;', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    return $value;
};

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//TaskForce//Calendario Empresa//PT',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:Calendário da Empresa',
    'X-WR-TIMEZONE:Europe/Lisbon',
];

$dtStamp = gmdate('Ymd\\THis\\Z');
$baseDomain = parse_url(app_base_url(), PHP_URL_HOST) ?: 'taskforce.local';

foreach ($events as $event) {
    $title = trim((string) ($event['title'] ?? 'Evento'));
    $type = trim((string) ($event['event_type'] ?? 'Evento'));
    $startRaw = (string) ($event['start_date'] ?? '');
    $endRaw = (string) ($event['end_date'] ?? '');

    if ($startRaw === '' || $endRaw === '') {
        continue;
    }

    try {
        $startDate = new DateTimeImmutable(max($startRaw, $yearStart));
        $endDate = new DateTimeImmutable(min($endRaw, $yearEnd));
    } catch (Throwable $exception) {
        continue;
    }

    if ($endDate < $startDate) {
        continue;
    }

    $eventTitle = $title;
    if ($type !== '' && stripos($title, $type) === false) {
        $eventTitle = $type . ' · ' . $title;
    }

    $uidHash = sha1($eventTitle . '|' . $startDate->format('Y-m-d') . '|' . $endDate->format('Y-m-d'));
    $uid = $uidHash . '@' . $baseDomain;

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $uid;
    $lines[] = 'DTSTAMP:' . $dtStamp;
    $lines[] = 'SUMMARY:' . $icsEscape($eventTitle);
    $lines[] = 'DTSTART;VALUE=DATE:' . $startDate->format('Ymd');
    $lines[] = 'DTEND;VALUE=DATE:' . $endDate->modify('+1 day')->format('Ymd');
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

$ics = implode("\r\n", $lines) . "\r\n";

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="calendario_empresa_' . $year . '.ics"');

echo $ics;
