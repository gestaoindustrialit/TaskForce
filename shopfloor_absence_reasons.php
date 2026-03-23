<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo);
$isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
$isRh = (string) ($user['access_profile'] ?? '') === 'RH';

if (!$isAdmin && !$isRh) {
    http_response_code(403);
    exit('Acesso reservado a administradores e RH.');
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

function parse_uploaded_reason_rows(array $file): array
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

function map_reason_rows(array $rawRows): array
{
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

    $requiredHeaders = ['reason_type', 'reason_code', 'sage_code', 'label', 'color'];
    foreach ($requiredHeaders as $requiredHeader) {
        if (!array_key_exists($requiredHeader, $headerMap)) {
            throw new RuntimeException('O template deve conter as colunas: reason_type, reason_code, sage_code, label e color.');
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

        $rowData['show_in_shopfloor'] = $rowData['show_in_shopfloor'] ?? '1';
        $rowData['is_active'] = $rowData['is_active'] ?? '1';
        $rowData['_row_number'] = $rowNumber + 2;
        $mappedRows[] = $rowData;
    }

    return $mappedRows;
}

function parse_binary_flag(string $value, string $fieldName, int $rowNumber): int
{
    $normalized = normalize_bulk_header($value);
    if ($normalized === '' || in_array($normalized, ['1', 'sim', 'yes', 'true', 'ativo', 'visivel'], true)) {
        return 1;
    }
    if (in_array($normalized, ['0', 'nao', 'no', 'false', 'inativo', 'oculto'], true)) {
        return 0;
    }

    throw new RuntimeException('Linha ' . $rowNumber . ': ' . $fieldName . ' deve ser 1/0, sim/não ou true/false.');
}

function output_absence_reason_template(): void
{
    $content = <<<XML
<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="MotivosAusencia">
  <Table>
   <Row>
    <Cell><Data ss:Type="String">reason_type</Data></Cell>
    <Cell><Data ss:Type="String">reason_code</Data></Cell>
    <Cell><Data ss:Type="String">sage_code</Data></Cell>
    <Cell><Data ss:Type="String">label</Data></Cell>
    <Cell><Data ss:Type="String">color</Data></Cell>
    <Cell><Data ss:Type="String">show_in_shopfloor</Data></Cell>
    <Cell><Data ss:Type="String">is_active</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Ausência</Data></Cell>
    <Cell><Data ss:Type="String">MOT-010</Data></Cell>
    <Cell><Data ss:Type="String">100</Data></Cell>
    <Cell><Data ss:Type="String">Falta sem perda de remuneração</Data></Cell>
    <Cell><Data ss:Type="String">#2563eb</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Atraso</Data></Cell>
    <Cell><Data ss:Type="String">ATR-001</Data></Cell>
    <Cell><Data ss:Type="String">200</Data></Cell>
    <Cell><Data ss:Type="String">Atraso justificado</Data></Cell>
    <Cell><Data ss:Type="String">#f59e0b</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
    <Cell><Data ss:Type="String">1</Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
XML;

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_motivos_ausencia.xls"');
    echo $content;
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'bulk-template') {
    output_absence_reason_template();
}

$flashSuccess = null;
$flashError = null;
$bulkImportSummary = null;
$allowedReasonTypes = ['Ausência', 'Atraso', 'Outro'];

$editingReasonId = (int) ($_GET['edit_id'] ?? 0);
$editingReason = null;
if ($editingReasonId > 0) {
    $editStmt = $pdo->prepare('SELECT id, reason_type, reason_code, sage_code, label, color, show_in_shopfloor FROM shopfloor_absence_reasons WHERE id = ? LIMIT 1');
    $editStmt->execute([$editingReasonId]);
    $editingReason = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editingReason) {
        $editingReasonId = 0;
    }
}

if (!function_exists('reason_sort_link')) {
    function reason_sort_link(string $column, string $label, string $currentSort, string $currentDirection): string
    {
        $isCurrent = $column === $currentSort;
        $nextDirection = $isCurrent && $currentDirection === 'ASC' ? 'desc' : 'asc';
        $arrow = $isCurrent ? ($currentDirection === 'ASC' ? '↑' : '↓') : '↕';

        return '<a class="text-decoration-none text-reset d-inline-flex align-items-center gap-1" href="?sort=' . urlencode($column) . '&direction=' . urlencode($nextDirection) . '">' . h($label) . '<span class="small text-muted">' . $arrow . '</span></a>';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $reasonType = trim((string) ($_POST['reason_type'] ?? 'Ausência'));
    if (!in_array($reasonType, $allowedReasonTypes, true)) {
        $reasonType = 'Ausência';
    }
    $reasonCode = strtoupper(trim((string) ($_POST['reason_code'] ?? '')));
    $sageCode = strtoupper(trim((string) ($_POST['sage_code'] ?? '')));
    $label = trim((string) ($_POST['label'] ?? ''));
    $color = trim((string) ($_POST['color'] ?? '#2563eb'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#2563eb';
    }

    if ($action === 'create_reason' || $action === 'update_reason') {
        if ($label === '') {
            $flashError = 'Indique a descrição do motivo.';
        } elseif ($reasonCode === '') {
            $flashError = 'Indique o código do motivo.';
        } elseif ($sageCode === '') {
            $flashError = 'Indique o código SAGE do motivo.';
        } else {
            try {
                if ($action === 'create_reason') {
                    $stmt = $pdo->prepare('INSERT INTO shopfloor_absence_reasons(reason_type, reason_code, sage_code, label, color, show_in_shopfloor, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, 1, ?)');
                    $stmt->execute([$reasonType, $reasonCode, $sageCode, $label, $color, $userId]);
                    log_app_event($pdo, $userId, 'shopfloor.absence_reason.create', 'Motivo de ausência criado.', ['reason_type' => $reasonType, 'reason_code' => $reasonCode, 'sage_code' => $sageCode, 'label' => $label, 'color' => $color]);
                    $flashSuccess = 'Motivo criado com sucesso.';
                } else {
                    $reasonId = (int) ($_POST['reason_id'] ?? 0);
                    if ($reasonId <= 0) {
                        $flashError = 'Motivo inválido para edição.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET reason_type = ?, reason_code = ?, sage_code = ?, label = ?, color = ? WHERE id = ?');
                        $stmt->execute([$reasonType, $reasonCode, $sageCode, $label, $color, $reasonId]);
                        log_app_event($pdo, $userId, 'shopfloor.absence_reason.update', 'Motivo de ausência editado.', ['reason_id' => $reasonId, 'reason_type' => $reasonType, 'reason_code' => $reasonCode, 'sage_code' => $sageCode, 'label' => $label, 'color' => $color]);
                        $flashSuccess = 'Motivo atualizado com sucesso.';
                        $editingReasonId = 0;
                        $editingReason = null;
                    }
                }
            } catch (PDOException $exception) {
                $flashError = 'Não foi possível guardar o motivo (o código do motivo ou a descrição já existem).';
            }
        }
    }

    if ($action === 'bulk_import_reasons') {
        try {
            $rawRows = parse_uploaded_reason_rows($_FILES['bulk_file'] ?? []);
            $rows = map_reason_rows($rawRows);
            if (!$rows) {
                throw new RuntimeException('O ficheiro não contém linhas de importação para processar.');
            }

            $processed = [];
            $errors = [];
            $insertStmt = $pdo->prepare('INSERT INTO shopfloor_absence_reasons(reason_type, reason_code, sage_code, label, color, show_in_shopfloor, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

            $pdo->beginTransaction();
            foreach ($rows as $row) {
                $rowReasonType = trim((string) ($row['reason_type'] ?? ''));
                $rowReasonCode = strtoupper(trim((string) ($row['reason_code'] ?? '')));
                $rowSageCode = strtoupper(trim((string) ($row['sage_code'] ?? '')));
                $rowLabel = trim((string) ($row['label'] ?? ''));
                $rowColor = trim((string) ($row['color'] ?? '#2563eb'));

                if (!in_array($rowReasonType, $allowedReasonTypes, true)) {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': reason_type deve ser Ausência, Atraso ou Outro.';
                    continue;
                }
                if ($rowReasonCode === '') {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': reason_code é obrigatório.';
                    continue;
                }
                if ($rowSageCode === '') {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': sage_code é obrigatório.';
                    continue;
                }
                if ($rowLabel === '') {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': label é obrigatório.';
                    continue;
                }
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $rowColor)) {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': color deve estar no formato hexadecimal, por exemplo #2563eb.';
                    continue;
                }

                try {
                    $showInShopfloor = parse_binary_flag((string) ($row['show_in_shopfloor'] ?? '1'), 'show_in_shopfloor', (int) $row['_row_number']);
                    $isActive = parse_binary_flag((string) ($row['is_active'] ?? '1'), 'is_active', (int) $row['_row_number']);
                } catch (RuntimeException $exception) {
                    $errors[] = $exception->getMessage();
                    continue;
                }

                try {
                    $insertStmt->execute([$rowReasonType, $rowReasonCode, $rowSageCode, $rowLabel, $rowColor, $showInShopfloor, $isActive, $userId]);
                    $processed[] = [
                        'reason_type' => $rowReasonType,
                        'reason_code' => $rowReasonCode,
                        'sage_code' => $rowSageCode,
                        'label' => $rowLabel,
                    ];
                } catch (PDOException $exception) {
                    $errors[] = 'Linha ' . $row['_row_number'] . ': não foi possível guardar o motivo (código, SAGE ou descrição duplicados).';
                }
            }

            if ($errors !== []) {
                $pdo->rollBack();
                $flashError = 'A importação foi cancelada porque existem erros no ficheiro.';
                $bulkImportSummary = ['processed' => $processed, 'errors' => $errors];
            } else {
                $pdo->commit();
                log_app_event($pdo, $userId, 'shopfloor.absence_reason.bulk_import', 'Importação bulk de motivos realizada.', ['rows' => count($processed)]);
                $flashSuccess = 'Importação concluída com sucesso.';
                $bulkImportSummary = ['processed' => $processed, 'errors' => []];
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flashError = $exception->getMessage();
        }
    }

    if ($action === 'toggle_reason') {
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        $newState = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        if ($reasonId <= 0) {
            $flashError = 'Motivo inválido.';
        } else {
            $stmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET is_active = ? WHERE id = ?');
            $stmt->execute([$newState, $reasonId]);
            log_app_event($pdo, $userId, 'shopfloor.absence_reason.toggle', 'Estado do motivo de ausência alterado.', ['reason_id' => $reasonId, 'is_active' => $newState]);
            $flashSuccess = 'Estado do motivo atualizado.';
        }
    }

    if ($action === 'toggle_shopfloor_visibility') {
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        $showInShopfloor = (int) ($_POST['show_in_shopfloor'] ?? 0) === 1 ? 1 : 0;
        if ($reasonId <= 0) {
            $flashError = 'Motivo inválido.';
        } else {
            $stmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET show_in_shopfloor = ? WHERE id = ?');
            $stmt->execute([$showInShopfloor, $reasonId]);
            log_app_event($pdo, $userId, 'shopfloor.absence_reason.shopfloor_visibility', 'Visibilidade do motivo no Shopfloor alterada.', ['reason_id' => $reasonId, 'show_in_shopfloor' => $showInShopfloor]);
            $flashSuccess = 'Visibilidade do motivo no Shopfloor atualizada.';
        }
    }

    if ($action === 'delete_reason') {
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        if ($reasonId <= 0) {
            $flashError = 'Motivo inválido para eliminar.';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM shopfloor_absence_reasons WHERE id = ?');
            $deleteStmt->execute([$reasonId]);
            log_app_event($pdo, $userId, 'shopfloor.absence_reason.delete', 'Motivo de ausência eliminado.', ['reason_id' => $reasonId]);
            $flashSuccess = 'Motivo eliminado com sucesso.';
            if ($editingReasonId === $reasonId) {
                $editingReasonId = 0;
                $editingReason = null;
            }
        }
    }
}

$allowedSortFields = [
    'reason_type' => 'reason_type',
    'reason_code' => 'reason_code',
    'sage_code' => 'sage_code',
    'label' => 'label',
    'created_at' => 'created_at',
    'is_active' => 'is_active'
];
$sort = (string) ($_GET['sort'] ?? 'label');
if (!array_key_exists($sort, $allowedSortFields)) {
    $sort = 'label';
}
$direction = strtolower((string) ($_GET['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$orderBy = $allowedSortFields[$sort];
$reasons = $pdo->query('SELECT id, reason_type, reason_code, sage_code, label, color, show_in_shopfloor, is_active, created_at FROM shopfloor_absence_reasons ORDER BY is_active DESC, ' . $orderBy . ' COLLATE NOCASE ' . $direction . ', label COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Motivos de ausência';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Motivos de ausência</h1>
    <a class="btn btn-outline-secondary" href="shopfloor.php">Voltar ao Shopfloor</a>
</div>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h4 mb-3"><?= $editingReason ? 'Editar motivo' : 'Novo motivo' ?></h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="<?= $editingReason ? 'update_reason' : 'create_reason' ?>">
            <?php if ($editingReason): ?>
                <input type="hidden" name="reason_id" value="<?= (int) $editingReason['id'] ?>">
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">Tipo</label>
                <select name="reason_type" class="form-select" required>
                    <?php foreach ($allowedReasonTypes as $type): ?>
                        <option value="<?= h($type) ?>" <?= ($editingReason && (string) $editingReason['reason_type'] === $type) ? 'selected' : '' ?>><?= h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Código do motivo</label>
                <input type="text" name="reason_code" class="form-control" placeholder="Ex: MOT-010" value="<?= h((string) ($editingReason['reason_code'] ?? '')) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Código SAGE</label>
                <input type="text" name="sage_code" class="form-control" placeholder="Ex: 100" value="<?= h((string) ($editingReason['sage_code'] ?? '')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Descrição</label>
                <input type="text" name="label" class="form-control" placeholder="Ex: Falta sem perda de remuneração" value="<?= h((string) ($editingReason['label'] ?? '')) ?>" required>
            </div>
            <div class="col-md-1">
                <label class="form-label">Cor</label>
                <input type="color" name="color" class="form-control form-control-color" value="<?= h((string) ($editingReason['color'] ?? '#2563eb')) ?>" title="Escolher cor">
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-primary" title="Guardar motivo" aria-label="Guardar motivo">
                    <i class="bi bi-floppy"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <h2 class="h4 mb-1">Importação em bulk</h2>
                <p class="text-muted mb-0">Faça download do template e importe vários motivos de uma só vez por Excel ou CSV.</p>
            </div>
            <a class="btn btn-outline-secondary" href="shopfloor_absence_reasons.php?download=bulk-template"><i class="bi bi-download"></i> Download template Excel</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="bulk_import_reasons">
            <div class="col-lg-9">
                <label class="form-label">Ficheiro Excel/CSV</label>
                <input class="form-control" type="file" name="bulk_file" accept=".xlsx,.xls,.xml,.csv" required>
                <div class="form-text">Colunas esperadas: <code>reason_type</code>, <code>reason_code</code>, <code>sage_code</code>, <code>label</code>, <code>color</code>, <code>show_in_shopfloor</code> e <code>is_active</code>.</div>
            </div>
            <div class="col-lg-3 d-grid">
                <button class="btn btn-primary">Importar motivos</button>
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
                            <thead><tr><th>Tipo</th><th>Cód. motivo</th><th>Cód. SAGE</th><th>Descrição</th></tr></thead>
                            <tbody>
                            <?php foreach ($bulkImportSummary['processed'] as $processedRow): ?>
                                <tr>
                                    <td><?= h($processedRow['reason_type']) ?></td>
                                    <td><?= h($processedRow['reason_code']) ?></td>
                                    <td><?= h($processedRow['sage_code']) ?></td>
                                    <td><?= h($processedRow['label']) ?></td>
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
        <h2 class="h4 mb-3">Lista de motivos</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th><?= reason_sort_link('reason_type', 'Tipo', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('reason_code', 'Cód. motivo', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('sage_code', 'Cód. SAGE', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('label', 'Motivo', $sort, $direction) ?></th>
                    <th>Cor</th>
                    <th>Shopfloor</th>
                    <th><?= reason_sort_link('created_at', 'Criado em', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('is_active', 'Estado', $sort, $direction) ?></th>
                    <th class="text-end">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($reasons): foreach ($reasons as $reason): ?>
                    <tr>
                        <td><?= h((string) ($reason['reason_type'] ?? 'Ausência')) ?></td>
                        <td><span class="badge text-bg-light border"><?= h((string) $reason['reason_code']) ?></span></td>
                        <td><span class="badge text-bg-light border"><?= h((string) $reason['sage_code']) ?></span></td>
                        <td class="fw-semibold"><?= h((string) $reason['label']) ?></td>
                        <td>
                            <span class="d-inline-block rounded border" style="width:20px;height:20px;background:<?= h((string) $reason['color']) ?>;"></span>
                            <span class="small text-muted ms-1"><?= h((string) $reason['color']) ?></span>
                        </td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle_shopfloor_visibility">
                                <input type="hidden" name="reason_id" value="<?= (int) $reason['id'] ?>">
                                <input type="hidden" name="show_in_shopfloor" value="<?= (int) ($reason['show_in_shopfloor'] ?? 1) === 1 ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-sm <?= (int) ($reason['show_in_shopfloor'] ?? 1) === 1 ? 'btn-outline-secondary' : 'btn-outline-warning' ?>">
                                    <?= (int) ($reason['show_in_shopfloor'] ?? 1) === 1 ? 'Visível' : 'Oculto' ?>
                                </button>
                            </form>
                        </td>
                        <td><?= h((string) $reason['created_at']) ?></td>
                        <td>
                            <?php if ((int) $reason['is_active'] === 1): ?>
                                <span class="badge text-bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="shopfloor_absence_reasons.php?edit_id=<?= (int) $reason['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar" aria-label="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_reason">
                                    <input type="hidden" name="reason_id" value="<?= (int) $reason['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= (int) $reason['is_active'] === 1 ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm <?= (int) $reason['is_active'] === 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>" title="<?= (int) $reason['is_active'] === 1 ? 'Desativar' : 'Ativar' ?>" aria-label="<?= (int) $reason['is_active'] === 1 ? 'Desativar' : 'Ativar' ?>">
                                        <i class="bi <?= (int) $reason['is_active'] === 1 ? 'bi-toggle-off' : 'bi-toggle-on' ?>"></i>
                                    </button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Tem a certeza que pretende eliminar este motivo?');">
                                    <input type="hidden" name="action" value="delete_reason">
                                    <input type="hidden" name="reason_id" value="<?= (int) $reason['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar" aria-label="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="text-muted">Sem motivos registados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
