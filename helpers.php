<?php
require_once __DIR__ . '/bootstrap/app.php';
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

function normalize_sage_code(string $value): string
{
    $normalized = strtoupper(trim($value));
    return preg_replace('/\.0+$/', '', $normalized) ?? $normalized;
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

    $currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($currentPage !== 'shopfloor.php') {
        $sessionLoginAt = trim((string) ($_SESSION['login_at'] ?? ''));
        $pendingAnnouncement = fetch_pending_shopfloor_announcement_ack($pdo, (int) $user['id'], $sessionLoginAt);
        if ($pendingAnnouncement !== null) {
            redirect('shopfloor.php?announcement_ack_required=1');
        }
    }

    run_hr_alerts_inline_if_due($pdo, (int) ($user['id'] ?? 0));
}

function run_hr_alerts_inline_if_due(PDO $pdo, int $triggerUserId = 0): void
{
    static $alreadyExecutedInRequest = false;
    if ($alreadyExecutedInRequest) {
        return;
    }
    $alreadyExecutedInRequest = true;

    if (PHP_SAPI === 'cli') {
        return;
    }

    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($currentScript === 'cron_hr_alerts.php') {
        return;
    }

    try {
        $isEnabled = trim((string) app_setting($pdo, 'hr_alerts_inline_cron_enabled', '1')) !== '0';
        if (!$isEnabled) {
            return;
        }

        $now = new DateTimeImmutable('now');
        $lastRunAt = trim((string) app_setting($pdo, 'hr_alerts_inline_cron_last_run_at', ''));
        $runsPerDay = (int) app_setting($pdo, 'hr_alerts_inline_cron_runs_per_day', '1440');
        $runsPerDay = max(1, min(1440, $runsPerDay));
        $intervalSeconds = max(60, (int) floor(86400 / $runsPerDay));
        $shouldRun = true;

        if ($lastRunAt !== '') {
            try {
                $lastRun = new DateTimeImmutable($lastRunAt);
                $shouldRun = ($now->getTimestamp() - $lastRun->getTimestamp()) >= $intervalSeconds;
            } catch (Throwable $exception) {
                $shouldRun = true;
            }
        }

        if (!$shouldRun) {
            return;
        }

        set_app_setting($pdo, 'hr_alerts_inline_cron_last_run_at', $now->format('Y-m-d H:i:s'));

        try {
            ob_start();
            require __DIR__ . '/cron_hr_alerts.php';
            ob_end_clean();
        } catch (Throwable $exception) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            try {
                log_app_event(
                    $pdo,
                    $triggerUserId > 0 ? $triggerUserId : null,
                    'hr.alerts.inline_cron.failed',
                    'Execução inline do cron de alertas RH falhou.',
                    ['error' => $exception->getMessage()]
                );
            } catch (Throwable $innerException) {
                // Ignorar falhas de logging para não bloquear fluxos da aplicação.
            }
        }
    } catch (Throwable $outerException) {
        if (function_exists('error_log')) {
            @error_log('[TaskForce] run_hr_alerts_inline_if_due fallback: ' . $outerException->getMessage());
        }
    }
}

function fetch_pending_shopfloor_announcement_ack(PDO $pdo, int $targetUserId, string $targetSessionLoginAt = ''): ?array
{
    $params = [$targetUserId, $targetUserId];

    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.body, a.created_at, COALESCE(u.name, "Sistema") AS created_by_name
         FROM shopfloor_announcements a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.is_active = 1
           AND (a.audience IN ("all", "shopfloor") OR EXISTS (SELECT 1 FROM shopfloor_announcement_targets t WHERE t.announcement_id = a.id AND t.user_id = ?))
           AND NOT EXISTS (SELECT 1 FROM shopfloor_announcement_acknowledgements ack WHERE ack.announcement_id = a.id AND ack.user_id = ?)
         ORDER BY a.created_at ASC, a.id ASC
         LIMIT 1'
    );

    $stmt->execute($params);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($announcement) ? $announcement : null;
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


function taskforce_mail_from_address(): string
{
    global $pdo;

    $value = '';
    if (isset($pdo) && $pdo instanceof PDO && function_exists('app_setting')) {
        $value = trim((string) app_setting($pdo, 'mail_from_address', ''));
    }
    if ($value === '') {
        $value = trim((string) getenv('TASKFORCE_MAIL_FROM_ADDRESS'));
    }

    return $value !== '' ? $value : 'noreply@calcadacorp.ch';
}

function taskforce_mail_from_name(): string
{
    global $pdo;

    $value = '';
    if (isset($pdo) && $pdo instanceof PDO && function_exists('app_setting')) {
        $value = trim((string) app_setting($pdo, 'mail_from_name', ''));
    }
    if ($value === '') {
        $value = trim((string) getenv('TASKFORCE_MAIL_FROM_NAME'));
    }

    return $value !== '' ? $value : 'TaskForce';
}

function taskforce_default_mail_headers(): string
{
    $fromAddress = taskforce_mail_from_address();
    $fromName = taskforce_mail_from_name();

    return 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n"
        . 'Reply-To: ' . $fromAddress . "\r\n";
}

function taskforce_smtp_config(): array
{
    global $pdo;

    $settingValue = static function (string $key) use ($pdo): string {
        if (isset($pdo) && $pdo instanceof PDO && function_exists('app_setting')) {
            return trim((string) app_setting($pdo, $key, ''));
        }

        return '';
    };

    $host = $settingValue('smtp_host');
    $portRaw = $settingValue('smtp_port');
    $secure = $settingValue('smtp_secure');
    $username = $settingValue('smtp_username');
    $password = $settingValue('smtp_password');
    $timeoutRaw = $settingValue('smtp_timeout_seconds');

    if ($host === '') {
        $host = trim((string) getenv('TASKFORCE_SMTP_HOST'));
    }
    if ($portRaw === '') {
        $portRaw = (string) getenv('TASKFORCE_SMTP_PORT');
    }
    if ($secure === '') {
        $secure = strtolower(trim((string) getenv('TASKFORCE_SMTP_SECURE')));
    }
    if ($username === '') {
        $username = trim((string) getenv('TASKFORCE_SMTP_USER'));
    }
    if ($password === '') {
        $password = (string) getenv('TASKFORCE_SMTP_PASS');
    }
    if ($timeoutRaw === '') {
        $timeoutRaw = (string) getenv('TASKFORCE_SMTP_TIMEOUT');
    }

    return [
        'host' => $host,
        'port' => (int) ($portRaw !== '' ? $portRaw : 587),
        'secure' => strtolower(trim($secure)),
        'username' => $username,
        'password' => $password,
        'timeout' => max(3, (int) ($timeoutRaw !== '' ? $timeoutRaw : 10)),
    ];
}

function taskforce_write_report_log(array $lines): void
{
    @file_put_contents(__DIR__ . '/reports_sent.log', implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
}

function taskforce_smtp_expect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 1024)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3} /', $line) === 1) {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP resposta inesperada: ' . trim($response));
    }

    return $response;
}

function taskforce_smtp_write($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function taskforce_smtp_send_mail(string $recipient, string $subject, string $body, string $headers): array
{
    $config = taskforce_smtp_config();
    if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
        return ['sent' => false, 'error' => 'SMTP não configurado (TASKFORCE_SMTP_HOST/USER/PASS em falta).'];
    }

    $transport = $config['host'];
    if ($config['secure'] === 'ssl' || ($config['secure'] === '' && $config['port'] === 465)) {
        $transport = 'ssl://' . $transport;
    }

    $socket = @stream_socket_client(
        $transport . ':' . $config['port'],
        $errno,
        $errstr,
        $config['timeout'],
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return ['sent' => false, 'error' => 'Falha ligação SMTP: ' . $errstr . ' (' . $errno . ')'];
    }

    stream_set_timeout($socket, $config['timeout']);

    try {
        taskforce_smtp_expect($socket, [220]);
        taskforce_smtp_write($socket, 'EHLO taskforce.local');
        taskforce_smtp_expect($socket, [250]);

        if ($config['secure'] === 'tls' || ($config['secure'] === '' && $config['port'] === 587)) {
            taskforce_smtp_write($socket, 'STARTTLS');
            taskforce_smtp_expect($socket, [220]);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Não foi possível iniciar STARTTLS.');
            }

            taskforce_smtp_write($socket, 'EHLO taskforce.local');
            taskforce_smtp_expect($socket, [250]);
        }

        taskforce_smtp_write($socket, 'AUTH LOGIN');
        taskforce_smtp_expect($socket, [334]);
        taskforce_smtp_write($socket, base64_encode($config['username']));
        taskforce_smtp_expect($socket, [334]);
        taskforce_smtp_write($socket, base64_encode($config['password']));
        taskforce_smtp_expect($socket, [235]);

        $fromAddress = taskforce_mail_from_address();
        taskforce_smtp_write($socket, 'MAIL FROM:<' . $fromAddress . '>');
        taskforce_smtp_expect($socket, [250]);
        taskforce_smtp_write($socket, 'RCPT TO:<' . $recipient . '>');
        taskforce_smtp_expect($socket, [250, 251]);
        taskforce_smtp_write($socket, 'DATA');
        taskforce_smtp_expect($socket, [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = 'Subject: ' . $encodedSubject . "\r\n" . $headers . "\r\n" . $body;
        $message = preg_replace("/(\r\n|\n|\r)\.([^\r\n])/", '$1..$2', $message) ?? $message;
        fwrite($socket, $message . "\r\n.\r\n");
        taskforce_smtp_expect($socket, [250]);

        taskforce_smtp_write($socket, 'QUIT');
        fclose($socket);

        return ['sent' => true, 'error' => ''];
    } catch (Throwable $exception) {
        @fclose($socket);

        return ['sent' => false, 'error' => $exception->getMessage()];
    }
}

function taskforce_build_mail_payload(string $subject, string $textBody, ?string $htmlBody = null, array $attachments = []): array
{
    $headers = taskforce_default_mail_headers();
    $normalizedHtmlBody = $htmlBody !== null ? trim($htmlBody) : null;
    if ($normalizedHtmlBody === '') {
        $normalizedHtmlBody = null;
    }

    $normalizedAttachments = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $name = trim((string) ($attachment['name'] ?? ''));
        $mime = trim((string) ($attachment['mime'] ?? 'application/octet-stream'));
        $content = (string) ($attachment['content'] ?? '');
        if ($name === '' || $content === '') {
            continue;
        }
        $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', basename($name));
        if ($safeName === null || $safeName === '') {
            $safeName = 'anexo.bin';
        }
        $normalizedAttachments[] = ['name' => $safeName, 'mime' => $mime, 'content' => $content];
    }

    if ($normalizedHtmlBody === null && !$normalizedAttachments) {
        return [
            'headers' => "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . $headers,
            'body' => $textBody,
        ];
    }

    try {
        $mixedBoundary = 'tf_mixed_' . bin2hex(random_bytes(8));
        $alternativeBoundary = 'tf_alt_' . bin2hex(random_bytes(8));
    } catch (Throwable $exception) {
        $seed = str_replace('.', '', (string) microtime(true));
        $mixedBoundary = 'tf_mixed_' . $seed;
        $alternativeBoundary = 'tf_alt_' . $seed;
    }
    $headers = "MIME-Version: 1.0\r\n"
        . 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"' . "\r\n"
        . $headers;

    $message = '--' . $mixedBoundary . "\r\n";
    $message .= 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"' . "\r\n\r\n";

    $message .= '--' . $alternativeBoundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $textBody . "\r\n\r\n";

    if ($normalizedHtmlBody !== null) {
        $message .= '--' . $alternativeBoundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $normalizedHtmlBody . "\r\n\r\n";
    }

    $message .= '--' . $alternativeBoundary . "--\r\n";

    foreach ($normalizedAttachments as $attachment) {
        $message .= '--' . $mixedBoundary . "\r\n";
        $message .= 'Content-Type: ' . $attachment['mime'] . '; name="' . $attachment['name'] . '"' . "\r\n";
        $message .= 'Content-Disposition: attachment; filename="' . $attachment['name'] . '"' . "\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($attachment['content'])) . "\r\n";
    }

    $message .= '--' . $mixedBoundary . "--\r\n";

    return ['headers' => $headers, 'body' => $message];
}

function deliver_report(string $email, string $subject, string $body, ?string $htmlBody = null, array $attachments = []): bool
{
    $payload = taskforce_build_mail_payload($subject, $body, $htmlBody, $attachments);
    $headers = $payload['headers'];
    $mailBody = $payload['body'];
    $logLines = [
        '[' . date('Y-m-d H:i:s') . '] DELIVERY ATTEMPT',
        'TO: ' . $email,
        'SUBJECT: ' . $subject,
    ];

    $phpErrorMessage = '';
    set_error_handler(static function (int $severity, string $message) use (&$phpErrorMessage): bool {
        $phpErrorMessage = $message;

        return true;
    });

    try {
        $sent = @mail($email, $subject, $mailBody, $headers);
    } finally {
        restore_error_handler();
    }

    if ($sent) {
        $logLines[] = 'TRANSPORT: mail()';
        $logLines[] = 'RESULT: SUCCESS';
        taskforce_write_report_log($logLines);

        return true;
    }

    $logLines[] = 'TRANSPORT: mail()';
    $logLines[] = 'RESULT: FAIL';
    if ($phpErrorMessage !== '') {
        $logLines[] = 'MAIL_ERROR: ' . $phpErrorMessage;
    }

    $smtpAttempt = taskforce_smtp_send_mail($email, $subject, $mailBody, $headers);
    if (!empty($smtpAttempt['sent'])) {
        $logLines[] = 'TRANSPORT_FALLBACK: smtp';
        $logLines[] = 'RESULT_FALLBACK: SUCCESS';
        taskforce_write_report_log($logLines);

        return true;
    }

    $logLines[] = 'TRANSPORT_FALLBACK: smtp';
    $logLines[] = 'RESULT_FALLBACK: FAIL';
    $logLines[] = 'SMTP_ERROR: ' . (string) ($smtpAttempt['error'] ?? 'Erro desconhecido SMTP.');
    $logLines[] = 'HEADERS:';
    $logLines[] = $headers;
    $logLines[] = 'BODY:';
    $logLines[] = $mailBody;
    $logLines[] = str_repeat('-', 80);
    taskforce_write_report_log($logLines);

    return false;
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

function taskforce_month_label_pt(DateTimeImmutable $date): string
{
    $months = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    $month = (int) $date->format('n');
    return ($months[$month] ?? '') . ' de ' . $date->format('Y');
}

function taskforce_generate_basic_pdf(array $lines): string
{
    $escapedLines = [];
    foreach ($lines as $line) {
        $safeLine = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $line);
        $escapedLines[] = '(' . $safeLine . ') Tj';
    }

    $content = "BT\n/F1 9 Tf\n36 806 Td\n11 TL\n";
    foreach ($escapedLines as $index => $line) {
        if ($index > 0) {
            $content .= "T*\n";
        }
        $content .= $line . "\n";
    }
    $content .= "ET\n";
    $contentLength = strlen($content);

    $objects = [];
    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $objects[] = "5 0 obj\n<< /Length " . $contentLength . " >>\nstream\n" . $content . "endstream\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

function taskforce_pdf_from_jpeg(string $jpegData, int $widthPx, int $heightPx): string
{
    $pageWidthPt = 595.28;
    $pageHeightPt = 841.89;

    $objects = [];
    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidthPt} {$pageHeightPt}] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Type /XObject /Subtype /Image /Width {$widthPx} /Height {$heightPx} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpegData) . " >>\nstream\n{$jpegData}\nendstream\nendobj\n";

    $content = "q\n{$pageWidthPt} 0 0 {$pageHeightPt} 0 0 cm\n/Im1 Do\nQ\n";
    $objects[] = "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

function taskforce_generate_monthly_layout_pdf(array $reportData): string
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return taskforce_generate_basic_pdf($reportData['lines'] ?? []);
    }

    $fontPath = __DIR__ . '/assets/fonts/Raleway-Regular.ttf';
    if (!is_file($fontPath)) {
        $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    }
    $canUseTtf = function_exists('imagettftext') && is_file($fontPath);
    $layoutScale = $canUseTtf ? 1.0 : 0.5;
    $scale = static fn(float $value): int => (int) round($value * $layoutScale);
    $width = $scale(1240);
    $height = $scale(1754);

    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $text = imagecolorallocate($image, 31, 41, 55);
    $muted = imagecolorallocate($image, 75, 85, 99);
    $border = imagecolorallocate($image, 209, 213, 219);
    $headerBg = imagecolorallocate($image, 243, 244, 246);
    imagefill($image, 0, 0, $white);
    $toRenderableText = static function (string $value): string {
        if (!function_exists('iconv')) {
            return $value;
        }

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted !== false ? $converted : $value;
    };
    $drawText = static function ($image, int $size, int $x, int $y, int $color, string $text) use ($canUseTtf, $fontPath, $toRenderableText): void {
        if ($canUseTtf) {
            imagettftext($image, $size, 0, $x, $y, $color, $fontPath, $text);
            return;
        }

        $font = 3;
        if ($size >= 18) {
            $font = 5;
        } elseif ($size >= 14) {
            $font = 4;
        }

        imagestring($image, $font, $x, $y - imagefontheight($font), $toRenderableText($text), $color);
    };

    $y = $scale(70);
    $drawText($image, $scale(30), $scale(60), $y, $text, 'Mapa mensal de picagens');
    $y += $scale(46);
    $drawText($image, $scale(16), $scale(60), $y, $muted, 'Período: ' . (string) ($reportData['period'] ?? ''));
    $y += $scale(30);
    $drawText($image, $scale(16), $scale(60), $y, $muted, 'Colaborador: ' . (string) ($reportData['employee'] ?? ''));
    $y += $scale(30);
    $drawText($image, $scale(16), $scale(60), $y, $muted, 'Mês de referência: ' . (string) ($reportData['month'] ?? ''));

    $logoPath = (string) ($reportData['logo_path'] ?? '');
    if ($logoPath !== '' && is_file($logoPath)) {
        $logoExt = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $logoImage = match ($logoExt) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($logoPath),
            'png' => @imagecreatefrompng($logoPath),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($logoPath) : false,
            default => false,
        };
        if ($logoImage !== false) {
            $maxTargetW = $scale(220);
            $maxTargetH = $scale(90);
            $sourceW = max(1, imagesx($logoImage));
            $sourceH = max(1, imagesy($logoImage));
            $ratio = min($maxTargetW / $sourceW, $maxTargetH / $sourceH);
            $targetW = max(1, (int) round($sourceW * $ratio));
            $targetH = max(1, (int) round($sourceH * $ratio));
            $targetX = $width - $scale(300) + (int) floor(($maxTargetW - $targetW) / 2);
            $targetY = $scale(40) + (int) floor(($maxTargetH - $targetH) / 2);
            imagecopyresampled($image, $logoImage, $targetX, $targetY, 0, 0, $targetW, $targetH, $sourceW, $sourceH);
            imagedestroy($logoImage);
        }
    }

    $y = $scale(220);
    $columns = [
        ['Data', $scale(120)],
        ['Dia', $scale(70)],
        ['Tipo', $scale(120)],
        ['Picagens', $scale(530)],
        ['BH', $scale(90)],
        ['Justificação', $scale(250)],
    ];
    $x = $scale(60);
    foreach ($columns as [$label, $w]) {
        imagefilledrectangle($image, $x, $y, $x + $w, $y + $scale(38), $headerBg);
        imagerectangle($image, $x, $y, $x + $w, $y + $scale(38), $border);
        $drawText($image, $scale(14), $x + $scale(8), $y + $scale(25), $text, $label);
        $x += $w;
    }
    $y += $scale(38);

    foreach (($reportData['rows'] ?? []) as $row) {
        if ($y > $scale(1460)) {
            break;
        }
        $cells = [
            (string) ($row['date'] ?? ''),
            (string) ($row['weekday'] ?? ''),
            (string) ($row['type'] ?? ''),
            (string) implode(' ', (array) ($row['slots'] ?? [])),
            (string) ($row['bh'] ?? ''),
            (string) ($row['justification'] ?? ''),
        ];
        $x = $scale(60);
        foreach ($columns as $index => $column) {
            [, $w] = $column;
            imagerectangle($image, $x, $y, $x + $w, $y + $scale(32), $border);
            $limit = $index === 3 ? 60 : 30;
            $txt = function_exists('mb_substr')
                ? mb_substr($cells[$index], 0, $limit)
                : substr($cells[$index], 0, $limit);
            $drawText($image, $scale(12), $x + $scale(7), $y + $scale(22), $text, $txt);
            $x += $w;
        }
        $y += $scale(32);
    }

    $y += $scale(26);
    $drawText($image, $scale(18), $scale(60), $y, $text, 'Resumo mensal');
    $y += $scale(30);
    foreach ((array) ($reportData['summary'] ?? []) as $summaryLine) {
        $drawText($image, $scale(14), $scale(60), $y, $muted, (string) $summaryLine);
        $y += $scale(24);
    }

    ob_start();
    imagejpeg($image, null, 88);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    if ($jpeg === '') {
        return taskforce_generate_basic_pdf($reportData['lines'] ?? []);
    }

    return taskforce_pdf_from_jpeg($jpeg, $width, $height);
}

function taskforce_generate_monthly_attendance_fpdf_pdf(array $reportData): ?string
{
    if (!class_exists('FPDF')) {
        return null;
    }

    $toPdfText = static function (string $value): string {
        $clean = preg_replace('/\s+/u', ' ', trim($value));
        $clean = is_string($clean) ? $clean : trim($value);
        if ($clean === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $clean);
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }
        if (function_exists('utf8_decode')) {
            return utf8_decode($clean);
        }
        return $clean;
    };
    $fitText = static function (string $value, int $limit = 36): string {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '—';
        }
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($trimmed, 0, $limit, '…', 'UTF-8');
        }
        if (strlen($trimmed) <= $limit) {
            return $trimmed;
        }
        return substr($trimmed, 0, max(0, $limit - 3)) . '...';
    };

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetDrawColor(220, 226, 234);
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetTextColor(31, 41, 55);

    $companyName = (string) ($reportData['company_name'] ?? 'TaskForce');
    $companyContacts = (array) ($reportData['company_contacts'] ?? []);
    $companyLine = trim(implode(' · ', array_filter(array_map('strval', $companyContacts))));
    $logoPath = trim((string) ($reportData['logo_path'] ?? ''));
    $employee = (string) ($reportData['employee'] ?? '—');
    $userNumber = (string) ($reportData['user_number'] ?? '—');
    $department = (string) ($reportData['department'] ?? '—');
    $period = (string) ($reportData['period'] ?? '—');
    $month = (string) ($reportData['month'] ?? '—');

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 8, $toPdfText($companyName), 0, 1);
    if ($logoPath !== '' && is_file($logoPath)) {
        try {
            $pdf->Image($logoPath, 150, 10, 46);
        } catch (Throwable $exception) {
            // Ignorar logo inválido e continuar com o PDF.
        }
    }
    if ($companyLine !== '') {
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->MultiCell(0, 5, $toPdfText($companyLine), 0, 'L');
        $pdf->SetTextColor(31, 41, 55);
    }

    $pdf->Ln(1);
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(0, 8, $toPdfText('Mapa mensal de picagens'), 0, 1);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->Cell(0, 5.6, $toPdfText('Período: ' . $period), 0, 1);
    $pdf->Cell(0, 5.6, $toPdfText('Colaborador: ' . $employee), 0, 1);
    $pdf->Cell(0, 5.6, $toPdfText('Número: ' . ($userNumber !== '' ? $userNumber : '—')), 0, 1);
    $pdf->Cell(0, 5.6, $toPdfText('Departamento: ' . ($department !== '' ? $department : '—')), 0, 1);
    $pdf->Cell(0, 5.6, $toPdfText('Mês de referência: ' . $month), 0, 1);
    $pdf->Ln(1.5);

    $headers = [
        ['Data', 21],
        ['Dia', 10],
        ['Tipo', 16],
        ['Picagens', 70],
        ['BH', 14],
        ['Justificação', 59],
    ];
    $pdf->SetFont('Arial', 'B', 8.2);
    foreach ($headers as [$label, $width]) {
        $pdf->Cell($width, 7, $toPdfText($label), 1, 0, 'L', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 7.4);
    $lineHeight = 5.4;
    foreach ((array) ($reportData['rows'] ?? []) as $row) {
        $slotsText = implode(' ', (array) ($row['slots'] ?? []));
        $cells = [
            $fitText((string) ($row['date'] ?? ''), 12),
            $fitText((string) ($row['weekday'] ?? ''), 5),
            $fitText((string) ($row['type'] ?? ''), 9),
            $fitText($slotsText, 58),
            $fitText((string) ($row['bh'] ?? ''), 8),
            $fitText((string) ($row['justification'] ?? ''), 52),
        ];

        if ($pdf->GetY() > 268) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 8.2);
            foreach ($headers as [$label, $width]) {
                $pdf->Cell($width, 7, $toPdfText($label), 1, 0, 'L', true);
            }
            $pdf->Ln();
            $pdf->SetFont('Arial', '', 7.4);
        }

        foreach ($headers as $idx => $header) {
            $width = (int) $header[1];
            $pdf->Cell($width, $lineHeight, $toPdfText($cells[$idx]), 1, 0, 'L');
        }
        $pdf->Ln();
    }

    if (!empty($reportData['summary']) && is_array($reportData['summary'])) {
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 6, $toPdfText('Resumo mensal'), 0, 1);
        $pdf->SetFont('Arial', '', 8.8);
        foreach ($reportData['summary'] as $summaryLine) {
            $pdf->Cell(0, 4.8, $toPdfText((string) $summaryLine), 0, 1);
        }
    }

    try {
        return (string) $pdf->Output('S');
    } catch (Throwable $exception) {
        return null;
    }
}

function taskforce_generate_pdf_from_html(string $html): ?string
{
    if (trim($html) === '') {
        return null;
    }

    if (class_exists('\Mpdf\Mpdf')) {
        try {
            $mpdfTempDir = rtrim(sys_get_temp_dir(), '/\\') . '/taskforce_mpdf';
            if (!is_dir($mpdfTempDir)) {
                @mkdir($mpdfTempDir, 0750, true);
            }
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'tempDir' => $mpdfTempDir,
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 10,
            ]);
            $mpdf->WriteHTML($html);
            $pdfBinary = (string) $mpdf->Output('', 'S');
            if ($pdfBinary !== '' && strncmp($pdfBinary, '%PDF', 4) === 0) {
                return $pdfBinary;
            }
        } catch (Throwable $exception) {
            // Fall through to CLI engines.
        }
    }

    if (!function_exists('shell_exec')) {
        return null;
    }

    $tmpDir = sys_get_temp_dir();
    $htmlFile = @tempnam($tmpDir, 'tf_html_');
    $pdfFile = @tempnam($tmpDir, 'tf_pdf_');
    if ($htmlFile === false || $pdfFile === false) {
        return null;
    }

    $cleanup = static function () use ($htmlFile, $pdfFile): void {
        @unlink($htmlFile);
        @unlink($pdfFile);
    };

    if (@file_put_contents($htmlFile, $html) === false) {
        $cleanup();
        return null;
    }

    $generated = false;

    $wkhtmlPath = trim((string) @shell_exec('command -v wkhtmltopdf 2>/dev/null'));
    if ($wkhtmlPath !== '') {
        $command = escapeshellarg($wkhtmlPath)
            . ' --quiet --encoding utf-8 --page-size A4 '
            . escapeshellarg($htmlFile) . ' '
            . escapeshellarg($pdfFile) . ' 2>&1';
        @shell_exec($command);
        $generated = true;
    } else {
        $chromeCandidates = [
            'chromium-browser',
            'chromium',
            'chromium-headless-shell',
            'google-chrome',
            'google-chrome-stable',
            'chrome',
            'microsoft-edge',
            'microsoft-edge-stable',
            'msedge',
        ];
        foreach ($chromeCandidates as $chromeCandidate) {
            $chromePath = trim((string) @shell_exec('command -v ' . escapeshellarg($chromeCandidate) . ' 2>/dev/null'));
            if ($chromePath === '') {
                continue;
            }

            $command = escapeshellarg($chromePath)
                . ' --headless --disable-gpu --no-sandbox --no-pdf-header-footer '
                . '--print-to-pdf=' . escapeshellarg($pdfFile) . ' '
                . escapeshellarg('file://' . $htmlFile) . ' 2>&1';
            @shell_exec($command);
            $generated = true;
            break;
        }
    }

    if (!$generated) {
        $cleanup();
        return null;
    }

    $pdfBinary = @file_get_contents($pdfFile);
    $cleanup();

    if (!is_string($pdfBinary) || $pdfBinary === '' || strncmp($pdfBinary, '%PDF', 4) !== 0) {
        return null;
    }

    return $pdfBinary;
}

function taskforce_store_generated_pdf_on_server(string $pdfContent, string $fileName, string $scope = 'relatorios-rh'): ?array
{
    if ($pdfContent === '' || strncmp($pdfContent, '%PDF', 4) !== 0) {
        return null;
    }

    $safeName = trim(basename($fileName));
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        $safeName = 'documento.pdf';
    }
    if (!str_ends_with(strtolower($safeName), '.pdf')) {
        $safeName .= '.pdf';
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $safeName) ?: 'documento.pdf';
    $safeName = trim($safeName, '-_.');
    if ($safeName === '') {
        $safeName = 'documento.pdf';
    }

    $rootStorage = __DIR__ . '/storage';
    $folderDate = (new DateTimeImmutable('now'))->format('Y/m/d');
    $targetDir = rtrim($rootStorage, '/\\') . '/generated-pdfs/' . trim($scope, '/\\') . '/' . $folderDate;
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return null;
    }

    $nameWithoutExt = pathinfo($safeName, PATHINFO_FILENAME);
    $extension = pathinfo($safeName, PATHINFO_EXTENSION);
    $suffix = bin2hex(random_bytes(3));
    $storedName = $nameWithoutExt . '-' . $suffix . ($extension !== '' ? '.' . $extension : '.pdf');
    $targetPath = rtrim($targetDir, '/\\') . '/' . $storedName;
    $written = @file_put_contents($targetPath, $pdfContent, LOCK_EX);
    if ($written === false || (int) $written !== strlen($pdfContent)) {
        return null;
    }

    $storedContent = @file_get_contents($targetPath);
    if (!is_string($storedContent) || $storedContent === '' || strncmp($storedContent, '%PDF', 4) !== 0) {
        return null;
    }

    return [
        'absolute_path' => $targetPath,
        'relative_path' => 'storage/generated-pdfs/' . trim($scope, '/\\') . '/' . $folderDate . '/' . $storedName,
        'name' => $storedName,
        'content' => $storedContent,
    ];
}

function taskforce_generate_monthly_attendance_report(PDO $pdo, array $user, DateTimeImmutable $referenceDate): array
{
    $periodStart = $referenceDate->modify('first day of this month')->setTime(0, 0, 0);
    $periodEnd = $referenceDate->modify('last day of this month')->setTime(23, 59, 59);
    $periodStartDate = $periodStart->format('Y-m-d');
    $periodEndDate = $periodEnd->format('Y-m-d');
    $reportMonthLabel = taskforce_month_label_pt($periodStart);

    $scheduleStmt = $pdo->prepare(
        'SELECT s.name, s.start_time, s.end_time, s.second_start_time, s.second_end_time, s.break_minutes, s.weekdays_mask
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

    $logoPath = app_setting($pdo, 'logo_report_dark', '');
    $logoUrl = '';
    if ($logoPath !== '') {
        $logoUrl = app_base_url() . '/' . ltrim($logoPath, '/');
    }
    $ralewayFontCss = '@import url("https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap");';
    $ralewayFontFile = __DIR__ . '/assets/fonts/Raleway-Regular.ttf';
    if (is_file($ralewayFontFile)) {
        $ralewayFontCss = '@font-face{font-family:"Raleway";font-style:normal;font-weight:400;src:url("file://'
            . str_replace('\\', '/', $ralewayFontFile)
            . '") format("truetype");}';
    }

    $rowsHtml = '';
    foreach ($rows as $row) {
        $rowsHtml .= '<tr>'
            . '<td>' . h($row['date']) . '</td>'
            . '<td>' . h($row['weekday']) . '</td>'
            . '<td>' . h($row['type']) . '</td>'
            . '<td>' . h(implode(' ', $row['slots'])) . '</td>'
            . '<td>' . h($row['bh']) . '</td>'
            . '<td>' . h($row['justification']) . '</td>'
            . '</tr>';
    }

    $userNumberLabel = (string) ($user['user_number'] ?? '');
    if ($userNumberLabel === '') {
        $userNumberLabel = '—';
    }

    $departmentLabel = (string) ($user['department'] ?? '');
    if ($departmentLabel === '') {
        $departmentLabel = '—';
    }

    $companyContacts = [];
    if ($companyAddress !== '') {
        $companyContacts[] = $companyAddress;
    }
    if ($companyPhone !== '') {
        $companyContacts[] = 'Telefone: ' . $companyPhone;
    }
    if ($companyEmail !== '') {
        $companyContacts[] = 'Email: ' . $companyEmail;
    }

    $summaryItems = [
        ['Dias com picagens', (string) $daysWithEntries],
        ['Dias totalmente validados', (string) $daysValidated],
        ['Horas trabalhadas', taskforce_format_minutes_signed($totalWorkedMinutes)],
        ['Saldo BH do mês', taskforce_format_minutes_signed($totalBhMinutes)],
        ['Saldo férias estimado', number_format($vacationBalance, 1, ',', '') . ' dias'],
    ];
    $summaryHtml = '';
    foreach ($summaryItems as [$label, $value]) {
        $summaryHtml .= '<td><span class="label">' . h((string) $label) . '</span><span class="value">' . h((string) $value) . '</span></td>';
    }

    $htmlBody = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . $ralewayFontCss
        . 'body{font-family:"Raleway",Arial,sans-serif;color:#1f2937;font-size:12px;margin:24px;}'
        . '.pdf-header{width:100%;border-collapse:collapse;border-bottom:2px solid #e5e7eb;margin-bottom:14px;padding-bottom:8px;}'
        . '.pdf-header td{vertical-align:top;padding-bottom:8px;}'
        . '.brand-name{font-size:20px;font-weight:700;color:#0f172a;margin-bottom:4px;}'
        . '.brand-contacts{color:#6b7280;font-size:11px;line-height:1.45;}'
        . '.header{width:100%;border-collapse:collapse;margin-bottom:4px;}'
        . '.header td{vertical-align:top;}'
        . '.header .logo{text-align:right;}'
        . '.header .logo img{max-height:70px;}'
        . 'h1{font-size:20px;margin:0 0 8px;}'
        . '.meta{margin:2px 0;}'
        . '.metric-grid{width:100%;border-collapse:separate;border-spacing:10px 0;margin:6px 0 14px;}'
        . '.metric-grid td{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;width:20%;}'
        . '.metric-grid .label{display:block;color:#64748b;font-size:10px;margin-bottom:4px;text-transform:uppercase;letter-spacing:.03em;}'
        . '.metric-grid .value{display:block;color:#0f172a;font-size:14px;font-weight:700;}'
        . '.data-table{width:100%;border-collapse:collapse;margin-top:10px;font-size:11px;}'
        . '.data-table th,.data-table td{border:1px solid #d1d5db;padding:6px;vertical-align:top;}'
        . '.data-table th{background:#f3f4f6;}'
        . '</style></head><body>'
        . '<table class="pdf-header" role="presentation"><tr><td><div class="brand-name">' . h((string) $companyName) . '</div><div class="brand-contacts">' . h(implode(' · ', $companyContacts)) . '</div></td>'
        . '<td class="logo" width="220">'
        . ($logoUrl !== '' ? '<img src="' . h($logoUrl) . '" alt="Logótipo empresa">' : '')
        . '</td></tr></table>'
        . '<table class="header" role="presentation"><tr>'
        . '<td><h1>Mapa mensal de picagens</h1>'
        . '<p class="meta"><strong>Período:</strong> ' . h($periodStart->format('d/m/Y') . ' - ' . $periodEnd->format('d/m/Y')) . '</p>'
        . '<p class="meta"><strong>Colaborador:</strong> ' . h((string) ($user['name'] ?? '')) . '</p>'
        . '<p class="meta"><strong>Número:</strong> ' . h($userNumberLabel) . '</p>'
        . '<p class="meta"><strong>Departamento:</strong> ' . h($departmentLabel) . '</p>'
        . '<p class="meta"><strong>Mês de referência:</strong> ' . h($reportMonthLabel) . '</p></td>'
        . '<td></td>'
        . '</tr></table>'
        . '<table class="metric-grid" role="presentation"><tr>' . $summaryHtml . '</tr></table>'
        . '<table class="data-table"><thead><tr><th>Data</th><th>Dia</th><th>Tipo</th><th>Picagens</th><th>BH</th><th>Justificação</th></tr></thead><tbody>'
        . $rowsHtml
        . '</tbody></table>'
        . '</body></html>';

    $logoFilePath = '';
    if ($logoPath !== '') {
        $candidatePath = __DIR__ . '/' . ltrim($logoPath, '/');
        if (is_file($candidatePath)) {
            $logoFilePath = $candidatePath;
        }
    }
    $pdfEngine = 'html';
    $pdfContent = taskforce_generate_pdf_from_html($htmlBody);
    if ($pdfContent === null) {
        $pdfEngine = 'fpdf';
        $pdfContent = taskforce_generate_monthly_attendance_fpdf_pdf([
            'company_name' => (string) $companyName,
            'company_contacts' => $companyContacts,
            'logo_path' => $logoFilePath,
            'period' => $periodStart->format('d/m/Y') . ' - ' . $periodEnd->format('d/m/Y'),
            'employee' => (string) ($user['name'] ?? ''),
            'user_number' => $userNumberLabel,
            'department' => $departmentLabel,
            'month' => $reportMonthLabel,
            'rows' => $rows,
            'summary' => [
                'Dias com picagens: ' . $daysWithEntries,
                'Dias totalmente validados: ' . $daysValidated,
                'Horas trabalhadas: ' . taskforce_format_minutes_signed($totalWorkedMinutes),
                'Saldo BH do mês: ' . taskforce_format_minutes_signed($totalBhMinutes),
                'Saldo de férias estimado: ' . number_format($vacationBalance, 1, ',', '') . ' dias',
            ],
        ]);
    }
    if ($pdfContent === null) {
        $pdfEngine = 'layout';
        $pdfContent = taskforce_generate_monthly_layout_pdf([
            'period' => $periodStart->format('d/m/Y') . ' - ' . $periodEnd->format('d/m/Y'),
            'employee' => (string) ($user['name'] ?? ''),
            'month' => $reportMonthLabel,
            'rows' => $rows,
            'summary' => [
                'Dias com picagens: ' . $daysWithEntries,
                'Dias totalmente validados: ' . $daysValidated,
                'Horas trabalhadas: ' . taskforce_format_minutes_signed($totalWorkedMinutes),
                'Saldo BH do mês: ' . taskforce_format_minutes_signed($totalBhMinutes),
                'Saldo de férias estimado: ' . number_format($vacationBalance, 1, ',', '') . ' dias',
            ],
            'logo_path' => $logoFilePath,
            'lines' => $lines,
        ]);
    }

    $pdfFileName = 'mapa-mensal-' . preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string) ($user['name'] ?? 'colaborador'))) . '-' . $periodStart->format('Y-m') . '.pdf';
    $storedPdf = null;
    $storeStatus = 'not_attempted';
    if (is_string($pdfContent) && $pdfContent !== '' && strncmp($pdfContent, '%PDF', 4) === 0) {
        $storedPdf = taskforce_store_generated_pdf_on_server($pdfContent, $pdfFileName, 'mapa-presencas');
        $storeStatus = is_array($storedPdf) ? 'stored' : 'store_failed';
    } else {
        $pdfEngine = 'failed';
        $storeStatus = 'invalid_pdf';
    }

    return [
        'subject' => '[TaskForce RH] Mapa mensal de picagens - ' . (string) ($user['name'] ?? '') . ' - ' . $periodStart->format('m/Y'),
        'body' => implode(PHP_EOL, $lines),
        'html_body' => $htmlBody,
        'pdf_filename' => $pdfFileName,
        'pdf_content' => $pdfContent,
        'stored_pdf' => $storedPdf,
        'pdf_debug' => [
            'engine' => $pdfEngine,
            'store_status' => $storeStatus,
            'has_pdf_content' => is_string($pdfContent) && $pdfContent !== '',
            'stored_relative_path' => is_array($storedPdf) ? (string) ($storedPdf['relative_path'] ?? '') : '',
        ],
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
