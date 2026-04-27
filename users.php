<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!is_admin($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores.');
}

$accessProfileOptions = ['Utilizador', 'Chefias', 'RH', 'Administração'];
$userTypeOptions = ['Funcionário', 'Administrador', 'Trabalhador Externo', 'Prestador'];
$timezoneOptions = ['Europe/Lisbon', 'Europe/Madrid', 'UTC'];

$departmentOptionsStmt = $pdo->query('SELECT d.id, d.name, g.name AS group_name FROM hr_departments d LEFT JOIN hr_department_groups g ON g.id = d.group_id ORDER BY d.name COLLATE NOCASE ASC');
$departmentOptions = $departmentOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
$scheduleOptionsStmt = $pdo->query('SELECT id, name, start_time, end_time FROM hr_schedules ORDER BY name COLLATE NOCASE ASC');
$scheduleOptions = $scheduleOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
$departmentNameById = [];
foreach ($departmentOptions as $departmentOption) {
    $departmentNameById[(int) $departmentOption['id']] = (string) $departmentOption['name'];
}

$flashSuccess = null;
$flashError = null;
$bulkImportSummary = null;

function find_user_conflict(PDO $pdo, string $field, string $value, ?int $excludeUserId = null): ?array
{
    $normalizedValue = trim($value);
    if ($normalizedValue === '' || !in_array($field, ['email', 'username'], true)) {
        return null;
    }

    $sql = 'SELECT id, name, username, email FROM users WHERE LOWER(TRIM(' . $field . ')) = LOWER(TRIM(?))';
    $params = [$normalizedValue];
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function build_user_conflict_message(string $field, string $value, ?array $existingUser = null): string
{
    $label = $field === 'email' ? 'email' : 'utilizador';
    $message = 'Não foi possível guardar o utilizador: o ' . $label . ' “' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '” já está em uso';

    if ($existingUser) {
        $existingName = trim((string) ($existingUser['name'] ?? ''));
        if ($existingName !== '') {
            $message .= ' por ' . htmlspecialchars($existingName, ENT_QUOTES, 'UTF-8');
        }
    }

    return $message . '.';
}

function build_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $parts = array_slice($parts, 0, 3);
    $initials = '';
    foreach ($parts as $part) {
        $firstChar = mb_substr((string) $part, 0, 1, 'UTF-8');
        if ($firstChar !== '') {
            $initials .= mb_strtoupper($firstChar, 'UTF-8');
        }
    }

    return $initials;
}



function normalize_bulk_header(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    if ($ascii !== false) {
        $value = strtolower($ascii);
    }

    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function spreadsheet_column_index(string $cellRef): int
{
    if (!preg_match('/([A-Z]+)/', strtoupper($cellRef), $matches)) {
        return 0;
    }

    $letters = $matches[1];
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function parse_csv_rows(string $filePath): array
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível ler o ficheiro CSV.');
    }

    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rows[] = array_map(static fn ($value) => trim((string) $value), $row);
    }
    fclose($handle);

    return $rows;
}

function parse_spreadsheetml_rows(string $filePath): array
{
    $xml = simplexml_load_file($filePath);
    if (!$xml) {
        throw new RuntimeException('Não foi possível ler o ficheiro Excel (.xls XML).');
    }

    $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
    $rows = [];
    foreach ($xml->xpath('//ss:Worksheet[1]/ss:Table/ss:Row') as $rowNode) {
        $row = [];
        $columnIndex = 0;
        foreach ($rowNode->Cell as $cell) {
            $attributes = $cell->attributes('urn:schemas-microsoft-com:office:spreadsheet');
            if (isset($attributes['Index'])) {
                $columnIndex = max(0, ((int) $attributes['Index']) - 1);
            }

            $valueNodes = $cell->xpath('./ss:Data');
            $value = $valueNodes && isset($valueNodes[0]) ? trim((string) $valueNodes[0]) : trim((string) $cell);
            $row[$columnIndex] = $value;
            $columnIndex++;
        }

        if ($row !== []) {
            ksort($row);
            // Preserve sparse indexes so empty spreadsheet cells do not shift subsequent columns.
            $rows[] = $row;
        }
    }

    return $rows;
}

function parse_xlsx_rows(string $filePath): array
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Não foi possível abrir o ficheiro .xlsx.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        if ($shared) {
            foreach ($shared->si as $item) {
                $textParts = [];
                if (isset($item->t)) {
                    $textParts[] = (string) $item->t;
                }
                foreach ($item->r as $run) {
                    $textParts[] = (string) $run->t;
                }
                $sharedStrings[] = implode('', $textParts);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('O ficheiro .xlsx não contém a primeira folha esperada.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('Não foi possível ler os dados da folha Excel.');
    }

    $sheet->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];
    foreach ($sheet->xpath('//a:sheetData/a:row') as $rowNode) {
        $row = [];
        foreach ($rowNode->c as $cell) {
            $ref = (string) ($cell['r'] ?? 'A1');
            $type = (string) ($cell['t'] ?? '');
            $valueNode = $cell->v;
            $value = $valueNode !== null ? (string) $valueNode : '';
            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            }
            $row[spreadsheet_column_index($ref)] = trim($value);
        }

        if ($row !== []) {
            ksort($row);
            // Preserve sparse indexes so empty spreadsheet cells do not shift subsequent columns.
            $rows[] = $row;
        }
    }

    return $rows;
}

function parse_uploaded_user_rows(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Selecione um ficheiro Excel válido para importar.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    return match ($extension) {
        'csv' => parse_csv_rows($tmpPath),
        'xlsx' => parse_xlsx_rows($tmpPath),
        'xls', 'xml' => parse_spreadsheetml_rows($tmpPath),
        default => throw new RuntimeException('Formato não suportado. Use .xlsx, .xls (XML 2003) ou .csv.'),
    };
}

function parse_binary_flag(string $value): int
{
    $normalized = normalize_bulk_header($value);
    if ($normalized === '' || in_array($normalized, ['1', 'sim', 'yes', 'true', 'ativo'], true)) {
        return 1;
    }
    if (in_array($normalized, ['0', 'nao', 'no', 'false', 'inativo'], true)) {
        return 0;
    }

    throw new RuntimeException('Campos booleanos devem ser 1/0, sim/não ou true/false.');
}

function get_bulk_value(array $rowData, array $headers, string $default = ''): string
{
    foreach ($headers as $header) {
        if (array_key_exists($header, $rowData)) {
            return trim((string) $rowData[$header]);
        }
    }

    return $default;
}

function output_users_template(): void
{
    $content = <<<'XML'
<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="Utilizadores">
  <Table>
   <Row>
    <Cell><Data ss:Type="String">name</Data></Cell>
    <Cell><Data ss:Type="String">username</Data></Cell>
    <Cell><Data ss:Type="String">email</Data></Cell>
    <Cell><Data ss:Type="String">password</Data></Cell>
    <Cell><Data ss:Type="String">access_profile</Data></Cell>
    <Cell><Data ss:Type="String">user_type</Data></Cell>
    <Cell><Data ss:Type="String">is_admin</Data></Cell>
    <Cell><Data ss:Type="String">is_active</Data></Cell>
    <Cell><Data ss:Type="String">must_change_password</Data></Cell>
    <Cell><Data ss:Type="String">pin_code</Data></Cell>
    <Cell><Data ss:Type="String">pin_only_login</Data></Cell>
    <Cell><Data ss:Type="String">email_notifications_active</Data></Cell>
    <Cell><Data ss:Type="String">sms_notifications_active</Data></Cell>
    <Cell><Data ss:Type="String">send_access_email</Data></Cell>
    <Cell><Data ss:Type="String">department_id</Data></Cell>
    <Cell><Data ss:Type="String">department</Data></Cell>
    <Cell><Data ss:Type="String">schedule_id</Data></Cell>
    <Cell><Data ss:Type="String">user_number</Data></Cell>
    <Cell><Data ss:Type="String">title</Data></Cell>
    <Cell><Data ss:Type="String">short_name</Data></Cell>
    <Cell><Data ss:Type="String">initials</Data></Cell>
    <Cell><Data ss:Type="String">profession</Data></Cell>
    <Cell><Data ss:Type="String">category</Data></Cell>
    <Cell><Data ss:Type="String">manager_name</Data></Cell>
    <Cell><Data ss:Type="String">hire_date</Data></Cell>
    <Cell><Data ss:Type="String">termination_date</Data></Cell>
    <Cell><Data ss:Type="String">timezone</Data></Cell>
    <Cell><Data ss:Type="String">phone</Data></Cell>
    <Cell><Data ss:Type="String">mobile</Data></Cell>
    <Cell><Data ss:Type="String">notes</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">João Silva</Data></Cell>
    <Cell><Data ss:Type="String">joao.silva</Data></Cell>
    <Cell><Data ss:Type="String">joao.silva@empresa.pt</Data></Cell>
    <Cell><Data ss:Type="String">Temp1234!</Data></Cell>
    <Cell><Data ss:Type="String">Utilizador</Data></Cell>
    <Cell><Data ss:Type="String">Funcionário</Data></Cell>
    <Cell><Data ss:Type="String">0</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">123456</Data></Cell>
    <Cell><Data ss:Type="String">0</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">0</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">Financeiro</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">20</Data></Cell>
    <Cell><Data ss:Type="String">Técnico</Data></Cell>
    <Cell><Data ss:Type="String">João</Data></Cell>
    <Cell><Data ss:Type="String">JS</Data></Cell>
    <Cell><Data ss:Type="String">Contabilista</Data></Cell>
    <Cell><Data ss:Type="String">Senior</Data></Cell>
    <Cell><Data ss:Type="String">Ana Costa</Data></Cell>
    <Cell><Data ss:Type="String">2026-01-10</Data></Cell>
    <Cell><Data ss:Type="String"></Data></Cell>
    <Cell><Data ss:Type="String">Europe/Lisbon</Data></Cell>
    <Cell><Data ss:Type="String">912345678</Data></Cell>
    <Cell><Data ss:Type="String">936789123</Data></Cell>
    <Cell><Data ss:Type="String">Utilizador criado por importação.</Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
XML;

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_utilizadores.xls"');
    echo $content;
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'bulk-template') {
    output_users_template();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $isAdmin = (int) ($_POST['is_admin'] ?? 0);
        $accessProfile = trim((string) ($_POST['access_profile'] ?? 'Utilizador'));
        $isActive = (int) ($_POST['is_active'] ?? 0);
        $mustChangePassword = (int) ($_POST['must_change_password'] ?? 0);
        $pinCode = preg_replace('/\D+/', '', (string) ($_POST['pin_code'] ?? ''));
        $pinCodeHash = $pinCode !== '' ? password_hash($pinCode, PASSWORD_DEFAULT) : null;
        $pinOnlyLogin = (int) ($_POST['pin_only_login'] ?? 0);

        $userType = trim((string) ($_POST['user_type'] ?? 'Funcionário'));
        $userNumber = trim((string) ($_POST['user_number'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $shortName = trim((string) ($_POST['short_name'] ?? ''));
        $initials = strtoupper(trim((string) ($_POST['initials'] ?? '')));
        if ($initials === '') {
            $initials = build_initials($name);
        }
        $emailNotificationsActive = (int) ($_POST['email_notifications_active'] ?? 0);
        $smsNotificationsActive = (int) ($_POST['sms_notifications_active'] ?? 0);
        $profession = trim((string) ($_POST['profession'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $managerName = trim((string) ($_POST['manager_name'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        $hireDate = trim((string) ($_POST['hire_date'] ?? ''));
        $terminationDate = trim((string) ($_POST['termination_date'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Lisbon'));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $sendAccessEmail = (int) ($_POST['send_access_email'] ?? 0);

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            $flashError = 'Preencha nome, utilizador, email e password para criar utilizador.';
        } elseif ($pinCode !== '' && !preg_match('/^\d{6}$/', $pinCode)) {
            $flashError = 'O PIN deve ter exatamente 6 dígitos.';
        } elseif ($pinOnlyLogin === 1 && $pinCode === '') {
            $flashError = 'Defina um PIN de 6 dígitos para login apenas com PIN.';
        } elseif ($existingEmailUser = find_user_conflict($pdo, 'email', $email)) {
            $flashError = build_user_conflict_message('email', $email, $existingEmailUser);
        } elseif ($existingUsernameUser = find_user_conflict($pdo, 'username', $username)) {
            $flashError = build_user_conflict_message('username', $username, $existingUsernameUser);
        } else {
            if (!in_array($accessProfile, $accessProfileOptions, true)) {
                $accessProfile = 'Utilizador';
            }
            if (!in_array($userType, $userTypeOptions, true)) {
                $userType = 'Funcionário';
            }
            if (!in_array($timezone, $timezoneOptions, true)) {
                $timezone = 'Europe/Lisbon';
            }

            if ($departmentId > 0 && isset($departmentNameById[$departmentId])) {
                $department = $departmentNameById[$departmentId];
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO users(name, username, email, password, is_admin, access_profile, is_active, must_change_password, pin_code_hash, pin_code, pin_only_login, user_type, user_number, title, short_name, initials, email_notifications_active, sms_notifications_active, profession, category, manager_name, department, department_id, schedule_id, hire_date, termination_date, timezone, phone, mobile, notes, send_access_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $name,
                    $username,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $isAdmin,
                    $accessProfile,
                    $isActive,
                    $mustChangePassword,
                    $pinCodeHash,
                    $pinCodeHash,
                    $pinOnlyLogin,
                    $userType,
                    $userNumber,
                    $title,
                    $shortName,
                    $initials,
                    $emailNotificationsActive,
                    $smsNotificationsActive,
                    $profession,
                    $category,
                    $managerName,
                    $department,
                    $departmentId > 0 ? $departmentId : null,
                    $scheduleId > 0 ? $scheduleId : null,
                    $hireDate,
                    $terminationDate,
                    $timezone,
                    $phone,
                    $mobile,
                    $notes,
                    $sendAccessEmail,
                ]);
                $flashSuccess = 'Utilizador criado com sucesso.';
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'users.email') !== false) {
                    $flashError = build_user_conflict_message('email', $email, find_user_conflict($pdo, 'email', $email));
                } elseif (stripos($e->getMessage(), 'users.username') !== false) {
                    $flashError = build_user_conflict_message('username', $username, find_user_conflict($pdo, 'username', $username));
                } else {
                    error_log('[TaskForce][users.php] Erro ao criar utilizador: ' . $e->getMessage());
                    $flashError = 'Não foi possível criar utilizador. Verifique os dados e tente novamente.';
                }
            }
        }
    }

    if ($action === 'update_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $isTargetAdmin = (int) ($_POST['is_admin'] ?? 0);
        $accessProfile = trim((string) ($_POST['access_profile'] ?? 'Utilizador'));
        $isActive = (int) ($_POST['is_active'] ?? 0);
        $mustChangePassword = (int) ($_POST['must_change_password'] ?? 0);
        $pinCode = preg_replace('/\D+/', '', (string) ($_POST['pin_code'] ?? ''));
        $pinCodeHash = $pinCode !== '' ? password_hash($pinCode, PASSWORD_DEFAULT) : null;
        $pinOnlyLogin = (int) ($_POST['pin_only_login'] ?? 0);

        $userType = trim((string) ($_POST['user_type'] ?? 'Funcionário'));
        $userNumber = trim((string) ($_POST['user_number'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $shortName = trim((string) ($_POST['short_name'] ?? ''));
        $initials = strtoupper(trim((string) ($_POST['initials'] ?? '')));
        if ($initials === '') {
            $initials = build_initials($name);
        }
        $emailNotificationsActive = (int) ($_POST['email_notifications_active'] ?? 0);
        $smsNotificationsActive = (int) ($_POST['sms_notifications_active'] ?? 0);
        $profession = trim((string) ($_POST['profession'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $managerName = trim((string) ($_POST['manager_name'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        $hireDate = trim((string) ($_POST['hire_date'] ?? ''));
        $terminationDate = trim((string) ($_POST['termination_date'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Lisbon'));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $sendAccessEmail = (int) ($_POST['send_access_email'] ?? 0);

        $existingPinCodeHash = null;
        if ($targetUserId > 0) {
            $existingUserStmt = $pdo->prepare('SELECT pin_code_hash FROM users WHERE id = ? LIMIT 1');
            $existingUserStmt->execute([$targetUserId]);
            $existingUserRow = $existingUserStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $existingPinCodeHash = $existingUserRow ? (string) ($existingUserRow['pin_code_hash'] ?? '') : null;
        }

        if ($targetUserId <= 0 || $name === '' || $username === '' || $email === '') {
            $flashError = 'Dados inválidos para atualizar utilizador.';
        } elseif ($pinCode !== '' && !preg_match('/^\d{6}$/', $pinCode)) {
            $flashError = 'O PIN deve ter exatamente 6 dígitos.';
        } elseif ($pinOnlyLogin === 1 && $pinCode === '' && trim((string) $existingPinCodeHash) === '') {
            $flashError = 'Defina um PIN de 6 dígitos para login apenas com PIN.';
        } elseif ($existingEmailUser = find_user_conflict($pdo, 'email', $email, $targetUserId)) {
            $flashError = build_user_conflict_message('email', $email, $existingEmailUser);
        } elseif ($existingUsernameUser = find_user_conflict($pdo, 'username', $username, $targetUserId)) {
            $flashError = build_user_conflict_message('username', $username, $existingUsernameUser);
        } else {
            if (!in_array($accessProfile, $accessProfileOptions, true)) {
                $accessProfile = 'Utilizador';
            }
            if (!in_array($userType, $userTypeOptions, true)) {
                $userType = 'Funcionário';
            }
            if (!in_array($timezone, $timezoneOptions, true)) {
                $timezone = 'Europe/Lisbon';
            }

            if ($departmentId > 0 && isset($departmentNameById[$departmentId])) {
                $department = $departmentNameById[$departmentId];
            }

            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ?, pin_code_hash = COALESCE(?, pin_code_hash), pin_code = COALESCE(?, pin_code), pin_only_login = ?, user_type = ?, user_number = ?, title = ?, short_name = ?, initials = ?, email_notifications_active = ?, sms_notifications_active = ?, profession = ?, category = ?, manager_name = ?, department = ?, department_id = ?, schedule_id = ?, hire_date = ?, termination_date = ?, timezone = ?, phone = ?, mobile = ?, notes = ?, send_access_email = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $pinCodeHash, $pinCodeHash, $pinOnlyLogin, $userType, $userNumber, $title, $shortName, $initials, $emailNotificationsActive, $smsNotificationsActive, $profession, $category, $managerName, $department, $departmentId > 0 ? $departmentId : null, $scheduleId > 0 ? $scheduleId : null, $hireDate, $terminationDate, $timezone, $phone, $mobile, $notes, $sendAccessEmail, $targetUserId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ?, pin_code_hash = COALESCE(?, pin_code_hash), pin_code = COALESCE(?, pin_code), pin_only_login = ?, user_type = ?, user_number = ?, title = ?, short_name = ?, initials = ?, email_notifications_active = ?, sms_notifications_active = ?, profession = ?, category = ?, manager_name = ?, department = ?, department_id = ?, schedule_id = ?, hire_date = ?, termination_date = ?, timezone = ?, phone = ?, mobile = ?, notes = ?, send_access_email = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $pinCodeHash, $pinCodeHash, $pinOnlyLogin, $userType, $userNumber, $title, $shortName, $initials, $emailNotificationsActive, $smsNotificationsActive, $profession, $category, $managerName, $department, $departmentId > 0 ? $departmentId : null, $scheduleId > 0 ? $scheduleId : null, $hireDate, $terminationDate, $timezone, $phone, $mobile, $notes, $sendAccessEmail, $targetUserId]);
                }
                $flashSuccess = 'Utilizador atualizado com sucesso.';
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'users.email') !== false) {
                    $flashError = build_user_conflict_message('email', $email, find_user_conflict($pdo, 'email', $email, $targetUserId));
                } elseif (stripos($e->getMessage(), 'users.username') !== false) {
                    $flashError = build_user_conflict_message('username', $username, find_user_conflict($pdo, 'username', $username, $targetUserId));
                } else {
                    error_log('[TaskForce][users.php] Erro ao atualizar utilizador #' . $targetUserId . ': ' . $e->getMessage());
                    $flashError = 'Não foi possível atualizar utilizador. Verifique os dados e tente novamente.';
                }
            }
        }
    }

    if ($action === 'bulk_import_users') {
        try {
            $rawRows = parse_uploaded_user_rows($_FILES['bulk_file'] ?? []);
            if (!$rawRows) {
                throw new RuntimeException('O ficheiro está vazio.');
            }

            $headerRow = array_shift($rawRows);
            $headerMap = [];
            foreach ($headerRow as $index => $header) {
                $normalized = normalize_bulk_header((string) $header);
                if ($normalized !== '') {
                    $headerMap[$normalized] = $index;
                }
            }

            foreach (['name', 'username', 'email', 'password'] as $requiredHeader) {
                if (!array_key_exists($requiredHeader, $headerMap)) {
                    throw new RuntimeException('O template deve conter as colunas obrigatórias: name, username, email, password.');
                }
            }

            $existingUsersByEmail = [];
            $existingUsersByUsername = [];
            $existingUsersPinHashById = [];
            foreach ($pdo->query('SELECT id, email, username, pin_code_hash FROM users')->fetchAll(PDO::FETCH_ASSOC) as $existingUserRow) {
                $existingId = (int) ($existingUserRow['id'] ?? 0);
                $normalizedEmail = mb_strtolower(trim((string) ($existingUserRow['email'] ?? '')), 'UTF-8');
                if ($existingId > 0 && $normalizedEmail !== '') {
                    $existingUsersByEmail[$normalizedEmail] = $existingId;
                }

                $normalizedUsername = mb_strtolower(trim((string) ($existingUserRow['username'] ?? '')), 'UTF-8');
                if ($existingId > 0 && $normalizedUsername !== '') {
                    $existingUsersByUsername[$normalizedUsername] = $existingId;
                }

                if ($existingId > 0) {
                    $existingUsersPinHashById[$existingId] = trim((string) ($existingUserRow['pin_code_hash'] ?? ''));
                }
            }

            $scheduleIds = [];
            foreach ($scheduleOptions as $scheduleOption) {
                $scheduleIds[(int) $scheduleOption['id']] = true;
            }

            $processed = [];
            $errors = [];
            $pendingEmails = [];
            $pendingUsernames = [];
            $rowsToUpsert = [];
            foreach ($rawRows as $rowIndex => $row) {
                $lineNumber = $rowIndex + 2;
                $rowData = [];
                foreach ($headerMap as $header => $index) {
                    $rowData[$header] = trim((string) ($row[$index] ?? ''));
                }
                if (implode('', $rowData) === '') {
                    continue;
                }

                $name = trim((string) ($rowData['name'] ?? ''));
                $username = trim((string) ($rowData['username'] ?? ''));
                $email = trim((string) ($rowData['email'] ?? ''));
                $password = (string) ($rowData['password'] ?? '');
                if ($name === '' || $username === '' || $email === '') {
                    $errors[] = 'Linha ' . $lineNumber . ': name, username e email são obrigatórios.';
                    continue;
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'Linha ' . $lineNumber . ': email inválido.';
                    continue;
                }

                $normalizedEmail = mb_strtolower($email, 'UTF-8');
                $normalizedUsername = mb_strtolower($username, 'UTF-8');
                $existingByEmailId = $existingUsersByEmail[$normalizedEmail] ?? null;
                $existingByUsernameId = $existingUsersByUsername[$normalizedUsername] ?? null;
                if ($existingByEmailId !== null && $existingByUsernameId !== null && $existingByEmailId !== $existingByUsernameId) {
                    $errors[] = 'Linha ' . $lineNumber . ': email e username pertencem a utilizadores diferentes.';
                    continue;
                }

                $targetUserId = $existingByEmailId ?? $existingByUsernameId;
                $targetKey = $targetUserId !== null ? ('u' . $targetUserId) : ('new:' . $lineNumber);
                if (isset($pendingEmails[$normalizedEmail]) && $pendingEmails[$normalizedEmail] !== $targetKey) {
                    $errors[] = 'Linha ' . $lineNumber . ': email repetido no ficheiro.';
                    continue;
                }
                if (isset($pendingUsernames[$normalizedUsername]) && $pendingUsernames[$normalizedUsername] !== $targetKey) {
                    $errors[] = 'Linha ' . $lineNumber . ': username repetido no ficheiro.';
                    continue;
                }
                if ($targetUserId === null && $password === '') {
                    $errors[] = 'Linha ' . $lineNumber . ': password é obrigatória para novos utilizadores.';
                    continue;
                }

                try {
                    $isAdminValue = parse_binary_flag((string) ($rowData['is_admin'] ?? '0'));
                    $isActiveValue = parse_binary_flag((string) ($rowData['is_active'] ?? '1'));
                } catch (RuntimeException $exception) {
                    $errors[] = 'Linha ' . $lineNumber . ': ' . $exception->getMessage();
                    continue;
                }

                $accessProfile = get_bulk_value($rowData, ['access_profile', 'perfil_acesso'], 'Utilizador');
                if (!in_array($accessProfile, $accessProfileOptions, true)) {
                    $accessProfile = 'Utilizador';
                }
                $userType = get_bulk_value($rowData, ['user_type', 'tipo_utilizador'], 'Funcionário');
                if (!in_array($userType, $userTypeOptions, true)) {
                    $userType = 'Funcionário';
                }

                $departmentId = (int) get_bulk_value($rowData, ['department_id', 'departamento_id'], '0');
                $department = get_bulk_value($rowData, ['department', 'departamento'], '');
                if ($departmentId > 0 && isset($departmentNameById[$departmentId])) {
                    $department = $departmentNameById[$departmentId];
                } elseif ($departmentId > 0 && !isset($departmentNameById[$departmentId])) {
                    $errors[] = 'Linha ' . $lineNumber . ': department_id inválido.';
                    continue;
                }

                $scheduleId = (int) get_bulk_value($rowData, ['schedule_id', 'horario_id'], '0');
                if ($scheduleId > 0 && !isset($scheduleIds[$scheduleId])) {
                    $errors[] = 'Linha ' . $lineNumber . ': schedule_id inválido.';
                    continue;
                }

                $hireDate = get_bulk_value($rowData, ['hire_date', 'data_admissao'], '');
                if ($hireDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) {
                    $errors[] = 'Linha ' . $lineNumber . ': hire_date deve estar no formato YYYY-MM-DD.';
                    continue;
                }

                $terminationDate = get_bulk_value($rowData, ['termination_date', 'data_saida'], '');
                if ($terminationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $terminationDate)) {
                    $errors[] = 'Linha ' . $lineNumber . ': termination_date deve estar no formato YYYY-MM-DD.';
                    continue;
                }

                $timezone = get_bulk_value($rowData, ['timezone', 'fuso_horario'], 'Europe/Lisbon');
                if (!in_array($timezone, $timezoneOptions, true)) {
                    $timezone = 'Europe/Lisbon';
                }

                $initials = strtoupper(get_bulk_value($rowData, ['initials', 'sigla'], ''));
                if ($initials === '') {
                    $initials = build_initials($name);
                }

                $pinCodeRaw = preg_replace('/\D+/', '', get_bulk_value($rowData, ['pin_code', 'pin', 'pin_tablet'], ''));
                if ($pinCodeRaw !== '' && !preg_match('/^\d{6}$/', $pinCodeRaw)) {
                    $errors[] = 'Linha ' . $lineNumber . ': pin_code deve ter exatamente 6 dígitos.';
                    continue;
                }
                $pinCodeHash = $pinCodeRaw !== '' ? password_hash($pinCodeRaw, PASSWORD_DEFAULT) : null;

                try {
                    $mustChangePassword = parse_binary_flag(get_bulk_value($rowData, ['must_change_password', 'obrigar_alteracao_password'], '0'));
                    $emailNotificationsActive = parse_binary_flag(get_bulk_value($rowData, ['email_notifications_active', 'ativo_para_email'], '1'));
                    $smsNotificationsActive = parse_binary_flag(get_bulk_value($rowData, ['sms_notifications_active', 'ativo_para_sms'], '0'));
                    $sendAccessEmail = parse_binary_flag(get_bulk_value($rowData, ['send_access_email', 'enviar_dados_acesso'], '0'));
                    $pinOnlyLogin = parse_binary_flag(get_bulk_value($rowData, ['pin_only_login', 'login_apenas_pin', 'login_apenas_com_pin_shopfloor'], '0'));
                } catch (RuntimeException $exception) {
                    $errors[] = 'Linha ' . $lineNumber . ': ' . $exception->getMessage();
                    continue;
                }

                $existingPinHash = $targetUserId !== null ? ($existingUsersPinHashById[(int) $targetUserId] ?? '') : '';
                if ($pinOnlyLogin === 1 && $pinCodeHash === null && trim((string) $existingPinHash) === '') {
                    $errors[] = 'Linha ' . $lineNumber . ': para login apenas com PIN, preencha pin_code (6 dígitos).';
                    continue;
                }

                $basePayload = [
                    $name,
                    $username,
                    $email,
                    $isAdminValue,
                    $accessProfile,
                    $isActiveValue,
                    $mustChangePassword,
                    $pinCodeHash,
                    $pinOnlyLogin,
                    $userType,
                    get_bulk_value($rowData, ['user_number', 'numero'], ''),
                    get_bulk_value($rowData, ['title', 'titulo'], ''),
                    get_bulk_value($rowData, ['short_name', 'nome_curto'], ''),
                    $initials,
                    $emailNotificationsActive,
                    $smsNotificationsActive,
                    get_bulk_value($rowData, ['profession', 'profissao'], ''),
                    get_bulk_value($rowData, ['category', 'categoria'], ''),
                    get_bulk_value($rowData, ['manager_name', 'responsavel'], ''),
                    $department,
                    $departmentId > 0 ? $departmentId : null,
                    $scheduleId > 0 ? $scheduleId : null,
                    $hireDate,
                    $terminationDate,
                    $timezone,
                    get_bulk_value($rowData, ['phone', 'telefone'], ''),
                    get_bulk_value($rowData, ['mobile', 'telemovel'], ''),
                    get_bulk_value($rowData, ['notes', 'observacoes'], ''),
                    $sendAccessEmail,
                ];
                if ($targetUserId !== null) {
                    $rowsToUpsert[] = [
                        'mode' => 'update',
                        'user_id' => $targetUserId,
                        'base' => $basePayload,
                        'password' => $password,
                    ];
                } else {
                    $rowsToUpsert[] = [
                        'mode' => 'insert',
                        'base' => $basePayload,
                        'password' => $password,
                    ];
                }

                $pendingEmails[$normalizedEmail] = $targetKey;
                $pendingUsernames[$normalizedUsername] = $targetKey;
                $processed[] = ['name' => $name, 'email' => $email, 'username' => $username];
            }

            if ($errors !== []) {
                $flashError = 'A importação foi cancelada porque existem erros no ficheiro.';
            } else {
                $insertStmt = $pdo->prepare('INSERT INTO users(name, username, email, password, is_admin, access_profile, is_active, must_change_password, pin_code_hash, pin_code, pin_only_login, user_type, user_number, title, short_name, initials, email_notifications_active, sms_notifications_active, profession, category, manager_name, department, department_id, schedule_id, hire_date, termination_date, timezone, phone, mobile, notes, send_access_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $updateStmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ?, pin_code_hash = COALESCE(?, pin_code_hash), pin_code = COALESCE(?, pin_code), pin_only_login = ?, user_type = ?, user_number = ?, title = ?, short_name = ?, initials = ?, email_notifications_active = ?, sms_notifications_active = ?, profession = ?, category = ?, manager_name = ?, department = ?, department_id = ?, schedule_id = ?, hire_date = ?, termination_date = ?, timezone = ?, phone = ?, mobile = ?, notes = ?, send_access_email = ? WHERE id = ?');
                $updateWithPasswordStmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ?, pin_code_hash = COALESCE(?, pin_code_hash), pin_code = COALESCE(?, pin_code), pin_only_login = ?, user_type = ?, user_number = ?, title = ?, short_name = ?, initials = ?, email_notifications_active = ?, sms_notifications_active = ?, profession = ?, category = ?, manager_name = ?, department = ?, department_id = ?, schedule_id = ?, hire_date = ?, termination_date = ?, timezone = ?, phone = ?, mobile = ?, notes = ?, send_access_email = ? WHERE id = ?');
                $pdo->beginTransaction();
                foreach ($rowsToUpsert as $rowToUpsert) {
                    $base = $rowToUpsert['base'];
                    $passwordValue = (string) ($rowToUpsert['password'] ?? '');
                    if (($rowToUpsert['mode'] ?? '') === 'update') {
                        $targetId = (int) ($rowToUpsert['user_id'] ?? 0);
                        if ($targetId <= 0) {
                            throw new RuntimeException('Utilizador inválido para atualização durante importação.');
                        }

                        if ($passwordValue !== '') {
                            $updateWithPasswordStmt->execute(array_merge([
                                $base[0], // name
                                $base[1], // username
                                $base[2], // email
                                password_hash($passwordValue, PASSWORD_DEFAULT),
                            ], array_slice($base, 3), [$targetId]));
                        } else {
                            $updateStmt->execute(array_merge($base, [$targetId]));
                        }
                        continue;
                    }

                    $insertStmt->execute([
                        $base[0], // name
                        $base[1], // username
                        $base[2], // email
                        password_hash($passwordValue, PASSWORD_DEFAULT),
                        $base[3], // is_admin
                        $base[4], // access_profile
                        $base[5], // is_active
                        $base[6], // must_change_password
                        $base[7], // pin_code_hash
                        $base[7], // pin_code
                        $base[8], // pin_only_login
                        $base[9], // user_type
                        $base[10], // user_number
                        $base[11], // title
                        $base[12], // short_name
                        $base[13], // initials
                        $base[14], // email_notifications_active
                        $base[15], // sms_notifications_active
                        $base[16], // profession
                        $base[17], // category
                        $base[18], // manager_name
                        $base[19], // department
                        $base[20], // department_id
                        $base[21], // schedule_id
                        $base[22], // hire_date
                        $base[23], // termination_date
                        $base[24], // timezone
                        $base[25], // phone
                        $base[26], // mobile
                        $base[27], // notes
                        $base[28], // send_access_email
                    ]);
                }
                $pdo->commit();
                $flashSuccess = 'Importação de utilizadores concluída com sucesso.';
            }
            $bulkImportSummary = ['processed' => $processed, 'errors' => $errors];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flashError = $exception->getMessage();
        }
    }

    if ($action === 'delete_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $flashError = 'Utilizador inválido para eliminação.';
        } elseif ($targetUserId === $userId) {
            $flashError = 'Não é possível eliminar o utilizador atualmente autenticado.';
        } else {
            $targetStmt = $pdo->prepare('SELECT id, name, is_admin FROM users WHERE id = ? LIMIT 1');
            $targetStmt->execute([$targetUserId]);
            $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $flashError = 'Utilizador não encontrado.';
            } else {
                $isTargetAdmin = (int) ($targetUser['is_admin'] ?? 0) === 1;
                if ($isTargetAdmin) {
                    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
                    if ($adminCount <= 1) {
                        $flashError = 'Não é possível eliminar o último administrador do sistema.';
                    }
                }
            }
        }

        if ($flashError === null) {
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $deleteStmt->execute([$targetUserId]);
                $flashSuccess = 'Utilizador eliminado com sucesso.';
            } catch (PDOException $e) {
                error_log('[TaskForce][users.php] Erro ao eliminar utilizador #' . $targetUserId . ': ' . $e->getMessage());
                $flashError = 'Não foi possível eliminar o utilizador porque existem registos associados.';
            }
        }
    }
}

$allowedPerPageOptions = [15, 25, 50, 100, 'all'];
$perPageRaw = $_GET['per_page'] ?? '15';
$perPage = '15';
if (is_string($perPageRaw) || is_numeric($perPageRaw)) {
    $perPageCandidate = is_string($perPageRaw) ? strtolower(trim($perPageRaw)) : (string) (int) $perPageRaw;
    if (in_array($perPageCandidate, array_map(static fn ($option) => (string) $option, $allowedPerPageOptions), true)) {
        $perPage = $perPageCandidate;
    }
}

$searchTerm = trim((string) ($_GET['search'] ?? ''));
$selectedDepartmentId = max(0, (int) ($_GET['department_id'] ?? 0));
$selectedScheduleId = max(0, (int) ($_GET['schedule_id'] ?? 0));
$selectedAccessProfile = trim((string) ($_GET['access_profile'] ?? ''));
$selectedUserType = trim((string) ($_GET['user_type'] ?? ''));
$selectedStatus = trim((string) ($_GET['status'] ?? ''));

$filterSqlParts = [];
$filterParams = [];

if ($searchTerm !== '') {
    $filterSqlParts[] = '(name LIKE :search OR username LIKE :search OR email LIKE :search OR user_number LIKE :search)';
    $filterParams[':search'] = '%' . $searchTerm . '%';
}

if ($selectedDepartmentId > 0) {
    $filterSqlParts[] = 'department_id = :department_id';
    $filterParams[':department_id'] = $selectedDepartmentId;
}

if ($selectedScheduleId > 0) {
    $filterSqlParts[] = 'schedule_id = :schedule_id';
    $filterParams[':schedule_id'] = $selectedScheduleId;
}

if ($selectedAccessProfile !== '' && in_array($selectedAccessProfile, $accessProfileOptions, true)) {
    $filterSqlParts[] = 'LOWER(TRIM(access_profile)) = LOWER(TRIM(:access_profile))';
    $filterParams[':access_profile'] = $selectedAccessProfile;
} else {
    $selectedAccessProfile = '';
}

if ($selectedUserType !== '' && in_array($selectedUserType, $userTypeOptions, true)) {
    $filterSqlParts[] = 'LOWER(TRIM(user_type)) = LOWER(TRIM(:user_type))';
    $filterParams[':user_type'] = $selectedUserType;
} else {
    $selectedUserType = '';
}

if ($selectedStatus === 'active') {
    $filterSqlParts[] = 'is_active = 1';
} elseif ($selectedStatus === 'inactive') {
    $filterSqlParts[] = 'is_active = 0';
} else {
    $selectedStatus = '';
}

$filtersWhereSql = $filterSqlParts !== [] ? (' WHERE ' . implode(' AND ', $filterSqlParts)) : '';
$activeFiltersCount = ($searchTerm !== '' ? 1 : 0)
    + ($selectedDepartmentId > 0 ? 1 : 0)
    + ($selectedScheduleId > 0 ? 1 : 0)
    + ($selectedAccessProfile !== '' ? 1 : 0)
    + ($selectedUserType !== '' ? 1 : 0)
    + ($selectedStatus !== '' ? 1 : 0);

$page = max(1, (int) ($_GET['page'] ?? 1));
$isAllPerPage = $perPage === 'all';
$perPageLimit = $isAllPerPage ? null : (int) $perPage;
$offset = $isAllPerPage ? 0 : (($page - 1) * $perPageLimit);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users' . $filtersWhereSql);
foreach ($filterParams as $paramName => $paramValue) {
    $countStmt->bindValue($paramName, $paramValue);
}
$countStmt->execute();
$totalUsers = (int) $countStmt->fetchColumn();
$totalPages = $isAllPerPage ? 1 : max(1, (int) ceil($totalUsers / $perPageLimit));
if ($isAllPerPage) {
    $page = 1;
} elseif ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPageLimit;
}

$usersBaseSql = 'SELECT id, name, username, email, is_admin, access_profile, is_active, must_change_password, pin_only_login, created_at, user_type, user_number, title, short_name, initials, email_notifications_active, sms_notifications_active, profession, category, manager_name, department, department_id, schedule_id, hire_date, termination_date, timezone, phone, mobile, notes, send_access_email FROM users'
    . $filtersWhereSql
    . ' ORDER BY created_at DESC';
if ($isAllPerPage) {
    $usersStmt = $pdo->prepare($usersBaseSql);
    foreach ($filterParams as $paramName => $paramValue) {
        $usersStmt->bindValue($paramName, $paramValue);
    }
    $usersStmt->execute();
} else {
    $usersStmt = $pdo->prepare($usersBaseSql . ' LIMIT ? OFFSET ?');
    foreach ($filterParams as $paramName => $paramValue) {
        $usersStmt->bindValue($paramName, $paramValue);
    }
    $usersStmt->bindValue(1, $perPageLimit, PDO::PARAM_INT);
    $usersStmt->bindValue(2, $offset, PDO::PARAM_INT);
    $usersStmt->execute();
}
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$filterQueryParams = [
    'search' => $searchTerm,
    'department_id' => $selectedDepartmentId > 0 ? (string) $selectedDepartmentId : '',
    'schedule_id' => $selectedScheduleId > 0 ? (string) $selectedScheduleId : '',
    'access_profile' => $selectedAccessProfile,
    'user_type' => $selectedUserType,
    'status' => $selectedStatus,
    'per_page' => $perPage,
];

$managerOptions = $pdo->query('SELECT name FROM users WHERE name IS NOT NULL AND TRIM(name) <> "" ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$departmentNameOptions = $pdo->query('SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND TRIM(department) <> "" ORDER BY department ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

$pageTitle = 'Utilizadores';
require __DIR__ . '/partials/header.php';
?>
<a href="dashboard.php" class="btn btn-link px-0">&larr; Voltar à dashboard</a>

<div class="card shadow-sm soft-card">
    <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0">Utilizadores</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="users.php?download=bulk-template"><i class="bi bi-download"></i> Template Excel</a>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bulkImportUsersModal"><i class="bi bi-upload"></i> Importar</button>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">Novo utilizador</button>
        </div>
    </div>
    <div class="card-body px-4 pb-4">
        <?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
        <?php if ($bulkImportSummary): ?>
            <div class="alert <?= !empty($bulkImportSummary['errors']) ? 'alert-warning' : 'alert-info' ?>">
                <strong>Resumo da importação:</strong>
                <div>Registos processados: <?= count($bulkImportSummary['processed']) ?></div>
                <div>Erros: <?= count($bulkImportSummary['errors']) ?></div>
                <?php if (!empty($bulkImportSummary['errors'])): ?>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($bulkImportSummary['errors'] as $bulkError): ?>
                            <li><?= h((string) $bulkError) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="get" class="border rounded-3 p-3 mb-3 bg-light-subtle">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="searchUsers" class="form-label small text-muted mb-1">Pesquisar utilizadores</label>
                    <input
                        id="searchUsers"
                        type="search"
                        name="search"
                        class="form-control form-control-sm"
                        placeholder="Nome, utilizador, email, número"
                        value="<?= h($searchTerm) ?>"
                    >
                </div>
                <div class="col-6 col-md-2">
                    <label for="departmentFilter" class="form-label small text-muted mb-1">Equipa</label>
                    <select id="departmentFilter" name="department_id" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <?php foreach ($departmentOptions as $departmentOption): ?>
                            <option value="<?= (int) $departmentOption['id'] ?>" <?= $selectedDepartmentId === (int) $departmentOption['id'] ? 'selected' : '' ?>>
                                <?= h((string) $departmentOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="scheduleFilter" class="form-label small text-muted mb-1">Turno</label>
                    <select id="scheduleFilter" name="schedule_id" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($scheduleOptions as $scheduleOption): ?>
                            <option value="<?= (int) $scheduleOption['id'] ?>" <?= $selectedScheduleId === (int) $scheduleOption['id'] ? 'selected' : '' ?>>
                                <?= h((string) $scheduleOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="statusFilter" class="form-label small text-muted mb-1">Estado</label>
                    <select id="statusFilter" name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="active" <?= $selectedStatus === 'active' ? 'selected' : '' ?>>Ativo</option>
                        <option value="inactive" <?= $selectedStatus === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="profileFilter" class="form-label small text-muted mb-1">Perfil</label>
                    <select id="profileFilter" name="access_profile" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($accessProfileOptions as $accessProfileOption): ?>
                            <option value="<?= h($accessProfileOption) ?>" <?= $selectedAccessProfile === $accessProfileOption ? 'selected' : '' ?>>
                                <?= h($accessProfileOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="userTypeFilter" class="form-label small text-muted mb-1">Tipo</label>
                    <select id="userTypeFilter" name="user_type" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($userTypeOptions as $userTypeOption): ?>
                            <option value="<?= h($userTypeOption) ?>" <?= $selectedUserType === $userTypeOption ? 'selected' : '' ?>>
                                <?= h($userTypeOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="perPage" class="form-label small text-muted mb-1">Mostrar</label>
                    <select id="perPage" name="per_page" class="form-select form-select-sm">
                        <option value="15" <?= $perPage === '15' ? 'selected' : '' ?>>15</option>
                        <option value="25" <?= $perPage === '25' ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $perPage === '50' ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $perPage === '100' ? 'selected' : '' ?>>100</option>
                        <option value="all" <?= $perPage === 'all' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-12 col-md-12 d-flex gap-2 justify-content-between align-items-center">
                    <div class="small text-muted">
                        <?= $totalUsers ?> resultado(s)
                        <?= $activeFiltersCount > 0 ? 'com ' . $activeFiltersCount . ' filtro(s) ativo(s)' : '' ?>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="users.php" class="btn btn-sm btn-outline-secondary">Limpar filtros</a>
                        <button type="submit" class="btn btn-sm btn-primary">Aplicar filtros</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="d-flex justify-content-end align-items-center mb-3">
            <span class="small text-muted">utilizadores por página</span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>#</th><th>Nome</th><th>Utilizador</th><th>Email</th><th>Perfil</th><th>Tipo</th><th>Estado</th><th>Segurança</th><th>Criado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= (int) $user['id'] ?></td>
                        <td><?= h($user['name']) ?></td>
                        <td><?= h((string) ($user['username'] ?? '')) ?></td>
                        <td><?= h($user['email']) ?></td>
                        <td>
                            <?= h((string) ($user['access_profile'] ?? 'Utilizador')) ?>
                            <?= (int) $user['is_admin'] === 1 ? '<span class="badge text-bg-dark ms-1">Admin</span>' : '' ?>
                        </td>
                        <td><?= h((string) ($user['user_type'] ?? 'Funcionário')) ?></td>
                        <td><?= (int) ($user['is_active'] ?? 1) === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-warning">Inativo</span>' ?></td>
                        <td><?= (int) ($user['must_change_password'] ?? 0) === 1 ? '<span class="badge text-bg-info">Troca de senha pendente</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h(date('d/m/Y', strtotime((string) $user['created_at']))) ?></td>
                        <td><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= (int) $user['id'] ?>">Editar</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php
                        $pageQueryParams = $filterQueryParams;
                        $pageQueryParams['page'] = (string) $i;
                        $pageQueryString = http_build_query(array_filter(
                            $pageQueryParams,
                            static fn ($value) => $value !== ''
                        ));
                        ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="users.php?<?= h($pageQueryString) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<style>
    .user-form-compact .form-label { margin-bottom: .25rem; font-size: .85rem; }
    .user-form-compact .form-control,
    .user-form-compact .form-select { font-size: .9rem; padding: .35rem .6rem; min-height: calc(1.5em + .7rem + 2px); }
    .user-form-compact .form-check-label { font-size: .9rem; }
</style>

<datalist id="managerOptions">
    <?php foreach ($managerOptions as $managerOption): ?>
        <option value="<?= h((string) $managerOption) ?>"></option>
    <?php endforeach; ?>
</datalist>

<datalist id="departmentOptions">
    <?php foreach ($departmentNameOptions as $departmentOption): ?>
        <option value="<?= h((string) $departmentOption) ?>"></option>
    <?php endforeach; ?>
</datalist>


<div class="modal fade" id="bulkImportUsersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="bulk_import_users">
            <div class="modal-header"><h5 class="modal-title">Importar utilizadores em bulk</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Faça download do template, preencha os utilizadores e carregue o ficheiro (.xlsx, .xls XML 2003 ou .csv).</p>
                <label class="form-label">Ficheiro de importação</label>
                <input class="form-control" type="file" name="bulk_file" accept=".xlsx,.xls,.xml,.csv" required>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-secondary" href="users.php?download=bulk-template">Download template</a>
                <button class="btn btn-primary">Importar utilizadores</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content user-form-compact" method="post">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-header"><h5 class="modal-title">Novo utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Nome</label><input class="form-control js-initials-source" name="name" placeholder="Nome" required></div>
                    <div class="col-md-3"><label class="form-label">Número</label><input class="form-control" name="user_number" placeholder="Número"></div>
                    <div class="col-md-3"><label class="form-label">Sigla (automático)</label><input class="form-control js-initials-target" name="initials" placeholder="Sigla" readonly></div>
                    <div class="col-md-6"><label class="form-label">Utilizador</label><input class="form-control" name="username" placeholder="Utilizador" required></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" placeholder="Email" required></div>
                    <div class="col-md-4">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="user_type" required>
                            <?php foreach ($userTypeOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $option === 'Funcionário' ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Perfil de acesso</label>
                        <select class="form-select" name="access_profile" required>
                            <?php foreach ($accessProfileOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $option === 'Utilizador' ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Password</label><input class="form-control" type="password" name="password" placeholder="Password" required></div>
                    <div class="col-md-4"><label class="form-label">PIN (6 dígitos)</label><input class="form-control" type="text" name="pin_code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="Opcional"></div>
                    <div class="col-md-4"><label class="form-label">Profissão</label><input class="form-control" name="profession" placeholder="Profissão"></div>
                    <div class="col-md-4"><label class="form-label">Categoria</label><input class="form-control" name="category" placeholder="Categoria"></div>
                    <div class="col-md-4"><label class="form-label">Responsável</label><input class="form-control" name="manager_name" placeholder="Responsável" list="managerOptions" autocomplete="off"></div>
                    <div class="col-md-4">
                        <label class="form-label">Departamento</label>
                        <select class="form-select" name="department_id">
                            <option value="">Sem departamento</option>
                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                <option value="<?= (int) $departmentOption['id'] ?>"><?= h($departmentOption['name']) ?><?= !empty($departmentOption['group_name']) ? ' · ' . h($departmentOption['group_name']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Horário</label>
                        <select class="form-select" name="schedule_id">
                            <option value="">Sem horário</option>
                            <?php foreach ($scheduleOptions as $scheduleOption): ?>
                                <option value="<?= (int) $scheduleOption['id'] ?>"><?= h($scheduleOption['name']) ?> (<?= h((string) $scheduleOption['start_time']) ?>-<?= h((string) $scheduleOption['end_time']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Data de admissão</label><input class="form-control" type="date" name="hire_date"></div>
                    <div class="col-md-4"><label class="form-label">Data saída</label><input class="form-control" type="date" name="termination_date"></div>
                    <div class="col-md-4">
                        <label class="form-label">Fuso horário</label>
                        <select class="form-select" name="timezone">
                            <?php foreach ($timezoneOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $option === 'Europe/Lisbon' ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Telefone</label><input class="form-control" name="phone" placeholder="Telefone"></div>
                    <div class="col-md-4"><label class="form-label">Telemóvel</label><input class="form-control" name="mobile" placeholder="Telemóvel"></div>
                    <div class="col-md-4"></div>
                    <div class="col-12"><label class="form-label">Observações</label><textarea class="form-control" name="notes" rows="3" placeholder="Observações"></textarea></div>
                </div>

                <hr>

                <div class="row g-2">
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdmin"><label class="form-check-label" for="isAdmin">Administrador</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" checked><label class="form-check-label" for="isActive">Ativo</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="email_notifications_active" value="1" id="emailNotificationsActive" checked><label class="form-check-label" for="emailNotificationsActive">Ativo para email</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_notifications_active" value="1" id="smsNotificationsActive"><label class="form-check-label" for="smsNotificationsActive">Ativo para SMS</label></div>
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="send_access_email" value="1" id="sendAccessEmail"><label class="form-check-label" for="sendAccessEmail">Enviar dados de acesso</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mustChangePassword"><label class="form-check-label" for="mustChangePassword">Obrigar alteração da senha no próximo login</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="pin_only_login" value="1" id="pinOnlyLogin"><label class="form-check-label" for="pinOnlyLogin">Login apenas com PIN (Shopfloor)</label></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Criar utilizador</button></div>
        </form>
    </div>
</div>

<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?= (int) $user['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content user-form-compact" method="post">
            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
            <input type="hidden" name="action" value="update_user">
            <div class="modal-header"><h5 class="modal-title">Editar utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Nome</label><input class="form-control js-initials-source" name="name" value="<?= h($user['name']) ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Número</label><input class="form-control" name="user_number" value="<?= h((string) ($user['user_number'] ?? '')) ?>" placeholder="Número"></div>
                    <div class="col-md-3"><label class="form-label">Sigla (automático)</label><input class="form-control js-initials-target" name="initials" value="<?= h((string) ($user['initials'] ?? '')) ?>" placeholder="Sigla" readonly></div>
                    <div class="col-md-6"><label class="form-label">Utilizador</label><input class="form-control" name="username" value="<?= h((string) ($user['username'] ?? '')) ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= h($user['email']) ?>" required></div>
                    <div class="col-md-4">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="user_type" required>
                            <?php foreach ($userTypeOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= ((string) ($user['user_type'] ?? 'Funcionário')) === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Perfil de acesso</label>
                        <select class="form-select" name="access_profile" required>
                            <?php foreach ($accessProfileOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= ((string) ($user['access_profile'] ?? 'Utilizador')) === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Nova password (opcional)</label><input class="form-control" type="password" name="password" placeholder="Nova password (opcional)"></div>
                    <div class="col-md-4"><label class="form-label">Novo PIN (6 dígitos)</label><input class="form-control" type="text" name="pin_code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="Opcional"></div>
                    <div class="col-md-4"><label class="form-label">Profissão</label><input class="form-control" name="profession" value="<?= h((string) ($user['profession'] ?? '')) ?>" placeholder="Profissão"></div>
                    <div class="col-md-4"><label class="form-label">Categoria</label><input class="form-control" name="category" value="<?= h((string) ($user['category'] ?? '')) ?>" placeholder="Categoria"></div>
                    <div class="col-md-4"><label class="form-label">Responsável</label><input class="form-control" name="manager_name" value="<?= h((string) ($user['manager_name'] ?? '')) ?>" placeholder="Responsável" list="managerOptions" autocomplete="off"></div>
                    <div class="col-md-4">
                        <label class="form-label">Departamento</label>
                        <select class="form-select" name="department_id">
                            <option value="">Sem departamento</option>
                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                <option value="<?= (int) $departmentOption['id'] ?>" <?= (int) ($user['department_id'] ?? 0) === (int) $departmentOption['id'] ? 'selected' : '' ?>><?= h($departmentOption['name']) ?><?= !empty($departmentOption['group_name']) ? ' · ' . h($departmentOption['group_name']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Horário</label>
                        <select class="form-select" name="schedule_id">
                            <option value="">Sem horário</option>
                            <?php foreach ($scheduleOptions as $scheduleOption): ?>
                                <option value="<?= (int) $scheduleOption['id'] ?>" <?= (int) ($user['schedule_id'] ?? 0) === (int) $scheduleOption['id'] ? 'selected' : '' ?>><?= h($scheduleOption['name']) ?> (<?= h((string) $scheduleOption['start_time']) ?>-<?= h((string) $scheduleOption['end_time']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Data de admissão</label><input class="form-control" type="date" name="hire_date" value="<?= h((string) ($user['hire_date'] ?? '')) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Data saída</label><input class="form-control" type="date" name="termination_date" value="<?= h((string) ($user['termination_date'] ?? '')) ?>"></div>
                    <div class="col-md-4">
                        <label class="form-label">Fuso horário</label>
                        <select class="form-select" name="timezone">
                            <?php foreach ($timezoneOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= ((string) ($user['timezone'] ?? 'Europe/Lisbon')) === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Telefone</label><input class="form-control" name="phone" value="<?= h((string) ($user['phone'] ?? '')) ?>" placeholder="Telefone"></div>
                    <div class="col-md-4"><label class="form-label">Telemóvel</label><input class="form-control" name="mobile" value="<?= h((string) ($user['mobile'] ?? '')) ?>" placeholder="Telemóvel"></div>
                    <div class="col-md-4"></div>
                    <div class="col-12"><label class="form-label">Observações</label><textarea class="form-control" name="notes" rows="3" placeholder="Observações"><?= h((string) ($user['notes'] ?? '')) ?></textarea></div>
                </div>

                <hr>

                <div class="row g-2">
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdminEdit<?= (int) $user['id'] ?>" <?= (int) $user['is_admin'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isAdminEdit<?= (int) $user['id'] ?>">Administrador</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActiveEdit<?= (int) $user['id'] ?>" <?= (int) ($user['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isActiveEdit<?= (int) $user['id'] ?>">Ativo</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="email_notifications_active" value="1" id="emailNotificationsActiveEdit<?= (int) $user['id'] ?>" <?= (int) ($user['email_notifications_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="emailNotificationsActiveEdit<?= (int) $user['id'] ?>">Ativo para email</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_notifications_active" value="1" id="smsNotificationsActiveEdit<?= (int) $user['id'] ?>" <?= (int) ($user['sms_notifications_active'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="smsNotificationsActiveEdit<?= (int) $user['id'] ?>">Ativo para SMS</label></div>
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="send_access_email" value="1" id="sendAccessEmailEdit<?= (int) $user['id'] ?>" <?= (int) ($user['send_access_email'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="sendAccessEmailEdit<?= (int) $user['id'] ?>">Enviar dados de acesso</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mustChangePasswordEdit<?= (int) $user['id'] ?>" <?= (int) ($user['must_change_password'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="mustChangePasswordEdit<?= (int) $user['id'] ?>">Obrigar alteração da senha no próximo login</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="pin_only_login" value="1" id="pinOnlyLoginEdit<?= (int) $user['id'] ?>" <?= (int) ($user['pin_only_login'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="pinOnlyLoginEdit<?= (int) $user['id'] ?>">Login apenas com PIN (Shopfloor)</label></div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button
                    type="submit"
                    class="btn btn-outline-danger"
                    name="action"
                    value="delete_user"
                    formnovalidate
                    onclick="return confirm('Tem a certeza que deseja eliminar este utilizador? Esta ação não pode ser anulada.');"
                >Eliminar utilizador</button>
                <button type="submit" class="btn btn-primary">Guardar utilizador</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
    function buildInitialsFromName(name) {
        return name.trim().split(/\s+/).filter(Boolean).slice(0, 3).map((part) => part.charAt(0).toUpperCase()).join('');
    }

    document.querySelectorAll('.modal-content.user-form-compact').forEach((form) => {
        const source = form.querySelector('.js-initials-source');
        const target = form.querySelector('.js-initials-target');
        if (!source || !target) {
            return;
        }

        const syncInitials = () => {
            target.value = buildInitialsFromName(source.value);
        };

        source.addEventListener('input', syncInitials);
        if (!target.value) {
            syncInitials();
        }
    });
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
