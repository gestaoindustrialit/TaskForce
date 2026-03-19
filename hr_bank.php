<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

function parse_hms_to_seconds(string $value): ?int
{
    $value = trim($value);
    if (preg_match('/^(-)?(\d{1,3}):(\d{2})$/', $value, $matches)) {
        $signal = $matches[1] === '-' ? -1 : 1;
        $hours = (int) $matches[2];
        $minutes = (int) $matches[3];
        if ($minutes > 59) {
            return null;
        }
        return $signal * (($hours * 3600) + ($minutes * 60));
    }
    if (!preg_match('/^(-)?(\d{1,3}):(\d{2}):(\d{2})$/', $value, $matches)) {
        return null;
    }
    $signal = $matches[1] === '-' ? -1 : 1;
    $hours = (int) $matches[2];
    $minutes = (int) $matches[3];
    $seconds = (int) $matches[4];
    if ($minutes > 59 || $seconds > 59) {
        return null;
    }
    return $signal * (($hours * 3600) + ($minutes * 60) + $seconds);
}

function format_seconds_hms(int $seconds): string
{
    $abs = abs($seconds);
    $hours = intdiv($abs, 3600);
    $minutes = intdiv($abs % 3600, 60);
    $secs = $abs % 60;
    $prefix = $seconds < 0 ? '-' : '';
    return sprintf('%s%02d:%02d:%02d', $prefix, $hours, $minutes, $secs);
}

function normalize_spreadsheet_header(string $value): string
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
            $rows[] = array_values($row);
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
            $rows[] = array_values($row);
        }
    }

    return $rows;
}

function parse_uploaded_adjustment_rows(array $file): array
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

function map_adjustment_rows(array $rawRows): array
{
    if (!$rawRows) {
        throw new RuntimeException('O ficheiro está vazio.');
    }

    $headerRow = array_shift($rawRows);
    $headerMap = [];
    foreach ($headerRow as $index => $header) {
        $normalized = normalize_spreadsheet_header((string) $header);
        if ($normalized !== '') {
            $headerMap[$normalized] = $index;
        }
    }

    $requiredHeaders = ['user_number', 'adjustment_type', 'delta_hms', 'action_date', 'reason'];
    foreach ($requiredHeaders as $requiredHeader) {
        if (!array_key_exists($requiredHeader, $headerMap)) {
            throw new RuntimeException('O template deve conter as colunas: user_number, adjustment_type, delta_hms, action_date e reason.');
        }
    }

    $mappedRows = [];
    foreach ($rawRows as $rowNumber => $row) {
        $rowData = [];
        foreach ($headerMap as $header => $index) {
            $rowData[$header] = trim((string) ($row[$index] ?? ''));
        }

        if (implode('', $rowData) === '') {
            continue;
        }

        $rowData['_row_number'] = $rowNumber + 2;
        $mappedRows[] = $rowData;
    }

    return $mappedRows;
}

function apply_hour_bank_adjustment(PDO $pdo, int $targetUserId, string $adjustmentType, int $signedSeconds, string $reason, string $actionDate, int $adminUserId): void
{
    $deltaMinutes = (int) round($signedSeconds / 60);
    $deltaHours = $deltaMinutes / 60;

    $existingBalanceStmt = $pdo->prepare('SELECT balance_hours FROM shopfloor_hour_banks WHERE user_id = ? LIMIT 1');
    $existingBalanceStmt->execute([$targetUserId]);
    $existingBalance = $existingBalanceStmt->fetchColumn();

    if ($existingBalance === false) {
        $insertStmt = $pdo->prepare('INSERT INTO shopfloor_hour_banks(user_id, balance_hours, notes, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
        $insertStmt->execute([$targetUserId, $deltaHours, $reason, $adminUserId]);
    } else {
        $newBalance = ((float) $existingBalance) + $deltaHours;
        $updateStmt = $pdo->prepare('UPDATE shopfloor_hour_banks SET balance_hours = ?, notes = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
        $updateStmt->execute([$newBalance, $reason, $adminUserId, $targetUserId]);
    }

    $logStmt = $pdo->prepare('INSERT INTO hr_hour_bank_logs(user_id, delta_minutes, reason, created_by, action_type, action_date) VALUES (?, ?, ?, ?, ?, ?)');
    $logStmt->execute([$targetUserId, $deltaMinutes, $reason, $adminUserId, $adjustmentType, $actionDate]);
}

function output_excel_template(): void
{
    $content = <<<XML
<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="AjustesBancoHoras">
  <Table>
   <Row>
    <Cell><Data ss:Type="String">user_number</Data></Cell>
    <Cell><Data ss:Type="String">adjustment_type</Data></Cell>
    <Cell><Data ss:Type="String">delta_hms</Data></Cell>
    <Cell><Data ss:Type="String">action_date</Data></Cell>
    <Cell><Data ss:Type="String">reason</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">1001</Data></Cell>
    <Cell><Data ss:Type="String">credito</Data></Cell>
    <Cell><Data ss:Type="String">01:30</Data></Cell>
    <Cell><Data ss:Type="String">2026-03-19</Data></Cell>
    <Cell><Data ss:Type="String">Acerto payroll</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">1002</Data></Cell>
    <Cell><Data ss:Type="String">debito</Data></Cell>
    <Cell><Data ss:Type="String">00:45</Data></Cell>
    <Cell><Data ss:Type="String">2026-03-19</Data></Cell>
    <Cell><Data ss:Type="String">Regularização manual</Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
XML;

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_ajustes_banco_horas.xls"');
    echo $content;
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'bulk-template') {
    output_excel_template();
}

$flashSuccess = null;
$flashError = null;
$bulkImportSummary = null;

$users = $pdo->query('SELECT id, name, email, user_number FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$selectedUserId = (int) ($_GET['user_id'] ?? ($users ? (int) $users[0]['id'] : 0));
$userOptionsById = [];
foreach ($users as $userRow) {
    $userOptionsById[(int) $userRow['id']] = $userRow;
}
if ($selectedUserId > 0 && !isset($userOptionsById[$selectedUserId]) && $users) {
    $selectedUserId = (int) $users[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'adjust_balance') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $adjustmentType = trim((string) ($_POST['adjustment_type'] ?? ''));
        $deltaHms = trim((string) ($_POST['delta_hms'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $actionDate = trim((string) ($_POST['action_date'] ?? ''));
        $durationSeconds = parse_hms_to_seconds($deltaHms);

        if ($targetUserId <= 0 || !isset($userOptionsById[$targetUserId])) {
            $flashError = 'Selecione um colaborador válido.';
        } elseif (!in_array($adjustmentType, ['credito', 'debito'], true)) {
            $flashError = 'Selecione se o ajuste é crédito ou débito.';
        } elseif ($durationSeconds === null || $durationSeconds === 0) {
            $flashError = 'Indique o ajuste no formato hh:mm.';
        } elseif ($reason === '') {
            $flashError = 'Indique o motivo do ajuste.';
        } elseif ($actionDate === '' || !DateTimeImmutable::createFromFormat('Y-m-d', $actionDate)) {
            $flashError = 'Indique uma data válida para o ajuste.';
        } else {
            $signedSeconds = $adjustmentType === 'debito' ? -abs($durationSeconds) : abs($durationSeconds);
            apply_hour_bank_adjustment($pdo, $targetUserId, $adjustmentType, $signedSeconds, $reason, $actionDate, $userId);
            log_app_event($pdo, $userId, 'hr.hour_bank.adjust', 'Ajuste individual de banco de horas realizado.', ['target_user_id' => $targetUserId, 'adjustment_type' => $adjustmentType, 'delta_hms' => format_seconds_hms($signedSeconds), 'action_date' => $actionDate]);

            $flashSuccess = 'Saldo ajustado com sucesso.';
            $selectedUserId = $targetUserId;
        }
    } elseif ($action === 'bulk_adjust_balance') {
        try {
            $rawRows = parse_uploaded_adjustment_rows($_FILES['bulk_file'] ?? []);
            $rows = map_adjustment_rows($rawRows);
            if (!$rows) {
                throw new RuntimeException('O ficheiro não contém linhas de importação para processar.');
            }

            $userNumberStmt = $pdo->query('SELECT id, user_number, name FROM users WHERE is_active = 1 AND TRIM(COALESCE(user_number, "")) <> ""');
            $usersByNumber = [];
            foreach ($userNumberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usersByNumber[trim((string) $row['user_number'])] = $row;
            }

            $processed = [];
            $errors = [];
            $pdo->beginTransaction();
            foreach ($rows as $row) {
                $userNumberKey = trim((string) $row['user_number']);
                $adjustmentType = $row['adjustment_type'];
                $durationSeconds = parse_hms_to_seconds($row['delta_hms']);
                $actionDate = $row['action_date'];
                $reason = $row['reason'];

                if (!isset($usersByNumber[$userNumberKey])) {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': colaborador não encontrado para o nº ' . $row['user_number'] . '.';
                    continue;
                }
                if (!in_array($adjustmentType, ['credito', 'debito'], true)) {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': adjustment_type deve ser credito ou debito.';
                    continue;
                }
                if ($durationSeconds === null || $durationSeconds === 0) {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': delta_hms deve estar em hh:mm.';
                    continue;
                }
                if ($reason === '') {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': reason é obrigatório.';
                    continue;
                }
                if ($actionDate === '' || !DateTimeImmutable::createFromFormat('Y-m-d', $actionDate)) {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': action_date deve estar em YYYY-MM-DD.';
                    continue;
                }

                $signedSeconds = $adjustmentType === 'debito' ? -abs($durationSeconds) : abs($durationSeconds);
                $targetUser = $usersByNumber[$userNumberKey];
                apply_hour_bank_adjustment($pdo, (int) $targetUser['id'], $adjustmentType, $signedSeconds, $reason, $actionDate, $userId);
                $processed[] = [
                    'name' => (string) $targetUser['name'],
                    'user_number' => (string) $targetUser['user_number'],
                    'delta' => format_seconds_hms($signedSeconds),
                    'type' => $adjustmentType,
                    'action_date' => $actionDate,
                ];
            }

            if ($errors !== []) {
                $pdo->rollBack();
                $flashError = 'A importação foi cancelada porque existem erros no ficheiro.';
            } else {
                $pdo->commit();
                log_app_event($pdo, $userId, 'hr.hour_bank.bulk_import', 'Importação bulk de banco de horas realizada.', ['rows' => count($processed)]);
                $flashSuccess = 'Importação concluída com sucesso.';
                $bulkImportSummary = ['processed' => $processed, 'errors' => []];
                if ($processed !== []) {
                    $firstImportedUserNumber = trim((string) $processed[0]['user_number']);
                    if (isset($usersByNumber[$firstImportedUserNumber])) {
                        $selectedUserId = (int) $usersByNumber[$firstImportedUserNumber]['id'];
                    }
                }
            }

            if ($errors !== []) {
                $bulkImportSummary = ['processed' => $processed, 'errors' => $errors];
            }
        } catch (Throwable $exception) {
            $flashError = $exception->getMessage();
        }
    }
}

$selectedUser = $selectedUserId > 0 ? ($userOptionsById[$selectedUserId] ?? null) : null;
$balanceStmt = $pdo->prepare('SELECT balance_hours FROM shopfloor_hour_banks WHERE user_id = ? LIMIT 1');
$balanceStmt->execute([$selectedUserId]);
$balanceHours = (float) ($balanceStmt->fetchColumn() ?: 0);
$balanceMinutes = (int) round($balanceHours * 60);

$historyStmt = $pdo->prepare('SELECT l.created_at, l.action_date, l.action_type, l.delta_minutes, l.reason, u.name AS admin_name FROM hr_hour_bank_logs l LEFT JOIN users u ON u.id = l.created_by WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 30');
$historyStmt->execute([$selectedUserId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Banco de horas';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Banco de horas</h1>
        <p class="text-muted mb-0">Ajustes manuais com histórico, seleção rápida de colaborador e importação bulk por Excel.</p>
    </div>
</div>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-6 col-xl-5">
                <label for="selected-user-id" class="form-label">Colaborador selecionado</label>
                <select class="form-select" id="selected-user-id" name="user_id" onchange="this.form.submit()">
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $selectedUserId ? 'selected' : '' ?>><?= h((string) $u['name']) ?><?= !empty($u['email']) ? ' · ' . h((string) $u['email']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Ao trocar o colaborador, o saldo atual, o formulário e o histórico passam a referir-se ao utilizador selecionado.</div>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100"><div class="card-body">
            <p class="text-muted mb-1">Saldo atual<?= $selectedUser ? ' · ' . h((string) $selectedUser['name']) : '' ?></p>
            <p class="display-5 mb-0"><?= h(format_seconds_hms($balanceMinutes * 60)) ?></p>
        </div></div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h5">Ajustar saldo</h2>
        <?php if ($selectedUser): ?>
            <p class="text-muted small mb-3">O ajuste manual será aplicado a <strong><?= h((string) $selectedUser['name']) ?></strong><?= !empty($selectedUser['email']) ? ' (' . h((string) $selectedUser['email']) . ')' : '' ?>.</p>
        <?php endif; ?>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="user_id" value="<?= (int) $selectedUserId ?>">
            <div class="col-md-3"><label class="form-label">Tipo</label><select class="form-select" name="adjustment_type" required><option value="">Selecione</option><option value="credito">Crédito</option><option value="debito">Débito</option></select></div>
            <div class="col-md-3"><label class="form-label">Ajuste (hh:mm)</label><input class="form-control" name="delta_hms" placeholder="01:30" required></div>
            <div class="col-md-2"><label class="form-label">Data da ação</label><input class="form-control" type="date" name="action_date" value="<?= h(date('Y-m-d')) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Motivo</label><input class="form-control" name="reason" placeholder="Ex: Ajuste payroll" required></div>
            <div class="col-md-1 d-grid"><button class="btn btn-dark">Aplicar</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <h2 class="h5 mb-1">Ajuste em bulk</h2>
                <p class="text-muted mb-0">Importe um ficheiro Excel com várias linhas para aplicar créditos ou débitos a vários colaboradores de uma só vez.</p>
            </div>
            <a class="btn btn-outline-secondary" href="hr_bank.php?download=bulk-template"><i class="bi bi-download"></i> Download template Excel</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="bulk_adjust_balance">
            <div class="col-lg-8">
                <label class="form-label">Ficheiro Excel</label>
                <input class="form-control" type="file" name="bulk_file" accept=".xlsx,.xls,.xml,.csv" required>
                <div class="form-text">Template esperado com as colunas: <code>user_number</code>, <code>adjustment_type</code>, <code>delta_hms</code>, <code>action_date</code> e <code>reason</code>.</div>
            </div>
            <div class="col-lg-4 d-grid">
                <button class="btn btn-primary">Importar ajustes</button>
            </div>
        </form>

        <?php if ($bulkImportSummary): ?>
            <div class="mt-4">
                <?php if (!empty($bulkImportSummary['errors'])): ?>
                    <div class="alert alert-warning mb-3">
                        <strong>Foram encontrados erros:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($bulkImportSummary['errors'] as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($bulkImportSummary['processed'])): ?>
                    <h3 class="h6">Pré-visualização das linhas processadas</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Colaborador</th><th>Nº colaborador</th><th>Tipo</th><th>Delta</th><th>Data da ação</th></tr></thead>
                            <tbody>
                            <?php foreach ($bulkImportSummary['processed'] as $processedRow): ?>
                                <tr>
                                    <td><?= h($processedRow['name']) ?></td>
                                    <td><?= h($processedRow['user_number']) ?></td>
                                    <td><?= h($processedRow['type']) ?></td>
                                    <td><?= h($processedRow['delta']) ?></td>
                                    <td><?= h($processedRow['action_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Histórico<?= $selectedUser ? ' · ' . h((string) $selectedUser['name']) : '' ?></h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0"><thead><tr><th>Quando</th><th>Data da ação</th><th>Tipo</th><th>Delta</th><th>Motivo</th><th>Admin</th></tr></thead><tbody>
            <?php if (!$history): ?><tr><td colspan="6" class="text-muted">Sem ajustes registados.</td></tr><?php endif; ?>
            <?php foreach ($history as $row): ?><tr><td><?= h((string) $row['created_at']) ?></td><td><?= h((string) ($row['action_date'] ?? '—')) ?></td><td><?= h((string) ($row['action_type'] ?? '—')) ?></td><td><?= h(format_seconds_hms(((int) $row['delta_minutes']) * 60)) ?></td><td><?= h((string) $row['reason']) ?></td><td><?= h((string) ($row['admin_name'] ?? '—')) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <h2 class="h5 mb-1">Ajuste em bulk</h2>
                <p class="text-muted mb-0">Importe um ficheiro Excel com várias linhas para aplicar créditos ou débitos a vários colaboradores de uma só vez.</p>
            </div>
            <a class="btn btn-outline-secondary" href="hr_bank.php?download=bulk-template"><i class="bi bi-download"></i> Download template Excel</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="bulk_adjust_balance">
            <div class="col-lg-8">
                <label class="form-label">Ficheiro Excel</label>
                <input class="form-control" type="file" name="bulk_file" accept=".xlsx,.xls,.xml,.csv" required>
                <div class="form-text">Template esperado com as colunas: <code>email</code>, <code>adjustment_type</code>, <code>delta_hms</code>, <code>action_date</code> e <code>reason</code>.</div>
            </div>
            <div class="col-lg-4 d-grid">
                <button class="btn btn-primary">Importar ajustes</button>
            </div>
        </form>

        <?php if ($bulkImportSummary): ?>
            <div class="mt-4">
                <?php if (!empty($bulkImportSummary['errors'])): ?>
                    <div class="alert alert-warning mb-3">
                        <strong>Foram encontrados erros:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($bulkImportSummary['errors'] as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($bulkImportSummary['processed'])): ?>
                    <h3 class="h6">Pré-visualização das linhas processadas</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Colaborador</th><th>Email</th><th>Tipo</th><th>Delta</th><th>Data da ação</th></tr></thead>
                            <tbody>
                            <?php foreach ($bulkImportSummary['processed'] as $processedRow): ?>
                                <tr>
                                    <td><?= h($processedRow['name']) ?></td>
                                    <td><?= h($processedRow['email']) ?></td>
                                    <td><?= h($processedRow['type']) ?></td>
                                    <td><?= h($processedRow['delta']) ?></td>
                                    <td><?= h($processedRow['action_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
