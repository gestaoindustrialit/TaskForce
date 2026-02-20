<?php
require_once __DIR__ . '/config.php';

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_user(PDO $pdo): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, name, email, is_admin FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function is_admin(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() === 1;
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

function format_minutes(?int $minutes): string
{
    if ($minutes === null) {
        return '-';
    }

    if ($minutes < 60) {
        return $minutes . ' min';
    }

    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    return $rest > 0 ? sprintf('%dh %02dmin', $hours, $rest) : sprintf('%dh', $hours);
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
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) $task['start_date']);
        if ($startDate instanceof DateTimeImmutable) {
            return $startDate;
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
