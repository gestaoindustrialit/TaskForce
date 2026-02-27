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

        return ($hours * 60) + $minutes + (int) ceil($seconds / 60);
    }

    if (ctype_digit($value)) {
        return max(0, (int) $value);
    }

    return null;
}

function format_minutes(?int $minutes): string
{
    if ($minutes === null) {
        return '00:00:00';
    }

    $totalSeconds = max(0, $minutes) * 60;
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
