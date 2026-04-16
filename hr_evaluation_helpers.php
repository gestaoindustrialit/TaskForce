<?php

$taskforceComposerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($taskforceComposerAutoload)) {
    require_once $taskforceComposerAutoload;
}

function taskforce_evaluation_periods(): array
{
    return [
        'jan_abr' => 'Janeiro - Abril',
        'mai_ago' => 'Maio - Agosto',
        'set_dez' => 'Setembro - Dezembro',
    ];
}

function taskforce_evaluation_period_label(string $period): string
{
    $periods = taskforce_evaluation_periods();
    return $periods[$period] ?? $period;
}

function taskforce_evaluation_period_sort(string $period): int
{
    return match ($period) {
        'jan_abr' => 1,
        'mai_ago' => 2,
        'set_dez' => 3,
        default => 999,
    };
}

function taskforce_evaluation_profiles(): array
{
    return [
        'operador' => 'Operador',
        'responsavel_support' => 'Responsável / Support',
    ];
}

function taskforce_default_rule_config(string $profileKey): array
{
    if ($profileKey === 'responsavel_support') {
        return [
            'profile_key' => 'responsavel_support',
            'periods' => taskforce_evaluation_periods(),
            'scores' => [
                'performance' => ['0' => 0.0, '1' => 37.5, '2' => 75.0, '3' => 0.0],
                'behavior' => ['0' => 0.0, '1' => 12.5, '2' => 25.0, '3' => 0.0],
            ],
            'counts' => [
                'punctuality' => ['zero_value' => 50.0, 'penalty_per_unit' => -5.0],
                'absence' => ['zero_value' => 100.0, 'penalty_per_unit' => -100.0],
                'final_absence_bonus' => ['0' => 250.0, '1' => 125.0, '2' => 62.5, '3_plus' => 0.0],
            ],
            'maximums' => ['period_total' => 250.0, 'year_total' => 1000.0],
        ];
    }

    return [
        'profile_key' => 'operador',
        'periods' => taskforce_evaluation_periods(),
        'scores' => [
            'performance' => ['0' => 0.0, '1' => 12.5, '2' => 25.0, '3' => 0.0],
            'behavior' => ['0' => 0.0, '1' => 12.5, '2' => 25.0, '3' => 0.0],
        ],
        'counts' => [
            'punctuality' => ['zero_value' => 25.0, 'penalty_per_unit' => -2.5],
            'absence' => ['zero_value' => 50.0, 'penalty_per_unit' => -50.0],
            'final_absence_bonus' => ['0' => 250.0, '1' => 125.0, '2' => 62.5, '3_plus' => 0.0],
        ],
        'maximums' => ['period_total' => 125.0, 'year_total' => 625.0],
    ];
}

function taskforce_normalize_rule_config(array $config, string $profileKey): array
{
    $default = taskforce_default_rule_config($profileKey);

    $normalized = $default;
    $normalized['profile_key'] = $profileKey;
    $normalized['periods'] = taskforce_evaluation_periods();

    foreach (['performance', 'behavior'] as $scoreType) {
        foreach (['0', '1', '2', '3'] as $score) {
            if (isset($config['scores'][$scoreType][$score]) && is_numeric($config['scores'][$scoreType][$score])) {
                $normalized['scores'][$scoreType][$score] = (float) $config['scores'][$scoreType][$score];
            }
        }
    }

    foreach (['punctuality', 'absence'] as $countType) {
        if (isset($config['counts'][$countType]['zero_value']) && is_numeric($config['counts'][$countType]['zero_value'])) {
            $normalized['counts'][$countType]['zero_value'] = (float) $config['counts'][$countType]['zero_value'];
        }
        if (isset($config['counts'][$countType]['penalty_per_unit']) && is_numeric($config['counts'][$countType]['penalty_per_unit'])) {
            $normalized['counts'][$countType]['penalty_per_unit'] = (float) $config['counts'][$countType]['penalty_per_unit'];
        }
    }

    foreach (['0', '1', '2', '3_plus'] as $finalKey) {
        if (isset($config['counts']['final_absence_bonus'][$finalKey]) && is_numeric($config['counts']['final_absence_bonus'][$finalKey])) {
            $normalized['counts']['final_absence_bonus'][$finalKey] = (float) $config['counts']['final_absence_bonus'][$finalKey];
        }
    }

    if (isset($config['maximums']['period_total']) && is_numeric($config['maximums']['period_total'])) {
        $normalized['maximums']['period_total'] = (float) $config['maximums']['period_total'];
    }
    if (isset($config['maximums']['year_total']) && is_numeric($config['maximums']['year_total'])) {
        $normalized['maximums']['year_total'] = (float) $config['maximums']['year_total'];
    }

    return $normalized;
}

function taskforce_fetch_evaluation_employee(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.user_number, u.name, u.email, u.department_id, u.award_profile, u.award_eligible,
                d.name AS department_name, d.group_id AS department_group_id,
                g.name AS department_group_name
         FROM users u
         LEFT JOIN hr_departments d ON d.id = u.department_id
         LEFT JOIN hr_department_groups g ON g.id = d.group_id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function taskforce_resolve_evaluation_rule(PDO $pdo, int $awardYear, string $profileKey, ?int $departmentId): array
{
    $profileKey = array_key_exists($profileKey, taskforce_evaluation_profiles()) ? $profileKey : 'operador';
    $departmentGroupId = null;

    if ($departmentId !== null && $departmentId > 0) {
        $groupStmt = $pdo->prepare('SELECT group_id FROM hr_departments WHERE id = ? LIMIT 1');
        $groupStmt->execute([$departmentId]);
        $departmentGroupId = $groupStmt->fetchColumn();
        $departmentGroupId = $departmentGroupId !== false ? (int) $departmentGroupId : null;
    }

    $yearStmt = $pdo->prepare('SELECT DISTINCT award_year FROM hr_evaluation_rule_sets WHERE is_active = 1 AND profile_key = ? AND award_year <= ? ORDER BY award_year DESC');
    $yearStmt->execute([$profileKey, $awardYear]);
    $years = $yearStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($awardYear, array_map('intval', $years), true)) {
        array_unshift($years, $awardYear);
    }

    foreach ($years as $yearCandidate) {
        $targetYear = (int) $yearCandidate;

        $queries = [
            ['sql' => 'SELECT * FROM hr_evaluation_rule_sets WHERE is_active = 1 AND award_year = ? AND profile_key = ? AND department_id = ? ORDER BY priority ASC, id DESC LIMIT 1', 'params' => [$targetYear, $profileKey, $departmentId]],
            ['sql' => 'SELECT * FROM hr_evaluation_rule_sets WHERE is_active = 1 AND award_year = ? AND profile_key = ? AND department_group_id = ? AND department_id IS NULL ORDER BY priority ASC, id DESC LIMIT 1', 'params' => [$targetYear, $profileKey, $departmentGroupId]],
            ['sql' => 'SELECT * FROM hr_evaluation_rule_sets WHERE is_active = 1 AND award_year = ? AND profile_key = ? AND department_group_id IS NULL AND department_id IS NULL ORDER BY priority ASC, id DESC LIMIT 1', 'params' => [$targetYear, $profileKey]],
        ];

        foreach ($queries as $query) {
            if (in_array(null, $query['params'], true) || in_array(0, $query['params'], true)) {
                continue;
            }
            $stmt = $pdo->prepare($query['sql']);
            $stmt->execute($query['params']);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                $config = json_decode((string) ($rule['config_json'] ?? ''), true);
                $normalizedConfig = taskforce_normalize_rule_config(is_array($config) ? $config : [], $profileKey);

                return [
                    'id' => (int) $rule['id'],
                    'name' => (string) $rule['name'],
                    'profile_key' => (string) $rule['profile_key'],
                    'award_year' => (int) $rule['award_year'],
                    'config' => $normalizedConfig,
                    'source' => 'rule_set',
                ];
            }
        }
    }

    return [
        'id' => 0,
        'name' => 'Default helper · ' . (taskforce_evaluation_profiles()[$profileKey] ?? 'Operador'),
        'profile_key' => $profileKey,
        'award_year' => $awardYear,
        'config' => taskforce_default_rule_config($profileKey),
        'source' => 'helper_default',
    ];
}

function taskforce_calculate_period_values(array $ruleConfig, array $input): array
{
    $performanceScore = (int) ($input['performance_score'] ?? 0);
    $behaviorScore = (int) ($input['behavior_score'] ?? 0);
    $punctualityCount = max(0, (int) ($input['punctuality_count'] ?? 0));
    $absenceCount = max(0, (int) ($input['absence_count'] ?? 0));

    $performanceValue = (float) ($ruleConfig['scores']['performance'][(string) $performanceScore] ?? 0.0);
    $behaviorValue = (float) ($ruleConfig['scores']['behavior'][(string) $behaviorScore] ?? 0.0);

    $punctualityValue = $punctualityCount === 0
        ? (float) ($ruleConfig['counts']['punctuality']['zero_value'] ?? 0.0)
        : $punctualityCount * (float) ($ruleConfig['counts']['punctuality']['penalty_per_unit'] ?? 0.0);

    $absenceValue = $absenceCount === 0
        ? (float) ($ruleConfig['counts']['absence']['zero_value'] ?? 0.0)
        : $absenceCount * (float) ($ruleConfig['counts']['absence']['penalty_per_unit'] ?? 0.0);

    $periodTotal = $performanceValue + $behaviorValue + $punctualityValue + $absenceValue;
    $maxPeriodTotal = (float) ($ruleConfig['maximums']['period_total'] ?? 0.0);
    $periodGap = $maxPeriodTotal - $periodTotal;

    return [
        'performance_value' => $performanceValue,
        'behavior_value' => $behaviorValue,
        'punctuality_value' => $punctualityValue,
        'absence_value' => $absenceValue,
        'period_total' => $periodTotal,
        'max_period_total' => $maxPeriodTotal,
        'period_gap' => $periodGap,
    ];
}

function taskforce_calculate_final_bonus(array $ruleConfig, int $finalAbsenceCount): float
{
    $map = $ruleConfig['counts']['final_absence_bonus'] ?? [];

    if ($finalAbsenceCount >= 3) {
        return (float) ($map['3_plus'] ?? 0.0);
    }

    return (float) ($map[(string) $finalAbsenceCount] ?? 0.0);
}

function taskforce_fetch_year_evaluations(PDO $pdo, int $userId, int $year): array
{
    $stmt = $pdo->prepare('SELECT * FROM hr_evaluations WHERE user_id = ? AND award_year = ? ORDER BY award_year ASC');
    $stmt->execute([$userId, $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    usort($rows, static function (array $a, array $b): int {
        return taskforce_evaluation_period_sort((string) ($a['award_period'] ?? '')) <=> taskforce_evaluation_period_sort((string) ($b['award_period'] ?? ''));
    });

    return $rows;
}

function taskforce_calculate_year_summary(PDO $pdo, int $userId, int $year, array $ruleConfig, int $finalAbsenceCount = 0, ?int $editingEvaluationId = null, ?array $editingValues = null): array
{
    $rows = taskforce_fetch_year_evaluations($pdo, $userId, $year);

    $yearPeriodsTotal = 0.0;
    foreach ($rows as $row) {
        $rowId = (int) ($row['id'] ?? 0);
        if ($editingEvaluationId !== null && $rowId === $editingEvaluationId) {
            continue;
        }
        $yearPeriodsTotal += (float) ($row['period_total'] ?? 0.0);
    }

    if (is_array($editingValues) && isset($editingValues['period_total']) && is_numeric($editingValues['period_total'])) {
        $yearPeriodsTotal += (float) $editingValues['period_total'];
    }

    $finalBonusValue = taskforce_calculate_final_bonus($ruleConfig, max(0, $finalAbsenceCount));
    $yearTotalWithBonus = $yearPeriodsTotal + $finalBonusValue;
    $maxYearTotal = (float) ($ruleConfig['maximums']['year_total'] ?? 0.0);
    $yearGap = $maxYearTotal - $yearTotalWithBonus;

    return [
        'year_periods_total' => $yearPeriodsTotal,
        'final_bonus_value' => $finalBonusValue,
        'year_total_with_bonus' => $yearTotalWithBonus,
        'max_year_total' => $maxYearTotal,
        'year_gap' => $yearGap,
    ];
}

function taskforce_money(float $value): string
{
    return number_format($value, 2, ',', ' ') . ' €';
}

function taskforce_get_evaluation_branding(PDO $pdo): array
{
    $companyName = trim((string) app_setting($pdo, 'company_name', 'TaskForce'));
    $companyAddress = trim((string) app_setting($pdo, 'company_address', ''));
    $companyPhone = trim((string) app_setting($pdo, 'company_phone', ''));
    $companyEmail = trim((string) app_setting($pdo, 'company_email', ''));
    $logoPath = trim((string) app_setting($pdo, 'logo_report_dark', ''));

    $logoUrl = '';
    if ($logoPath !== '') {
        $logoUrl = rtrim((string) app_base_url(), '/') . '/' . ltrim($logoPath, '/');
    }

    return [
        'company_name' => $companyName !== '' ? $companyName : 'TaskForce',
        'company_address' => $companyAddress,
        'company_phone' => $companyPhone,
        'company_email' => $companyEmail,
        'logo_url' => $logoUrl,
    ];
}

function taskforce_build_company_contact_line(array $branding): string
{
    $contacts = [];
    if ((string) ($branding['company_phone'] ?? '') !== '') {
        $contacts[] = 'Telefone: ' . (string) $branding['company_phone'];
    }
    if ((string) ($branding['company_email'] ?? '') !== '') {
        $contacts[] = 'Email: ' . (string) $branding['company_email'];
    }

    return implode(' · ', $contacts);
}

function taskforce_build_evaluation_mail_html(string $employeeName, string $leadMessage, array $branding): string
{
    $companyName = h((string) ($branding['company_name'] ?? 'TaskForce'));
    $contactLine = h(taskforce_build_company_contact_line($branding));
    return '<!doctype html><html lang="pt"><head><meta charset="utf-8"><style>'
        . 'body{margin:0;background:#f3f6fb;padding:20px;font-family:Arial,sans-serif;color:#1f2937;}'
        . '.card{max-width:640px;margin:0 auto;background:#fff;border:1px solid #d7dde8;border-radius:14px;padding:20px;}'
        . 'h1{font-size:18px;margin:0 0 12px;} p{margin:0 0 12px;line-height:1.5;}'
        . '.footer{margin-top:18px;padding-top:12px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;}'
        . '</style></head><body><div class="card">'
        . '<h1>Olá ' . h($employeeName) . ',</h1>'
        . '<p>' . h($leadMessage) . '</p>'
        . '<p>Cumprimentos,<br><strong>Equipa RH</strong></p>'
        . '<div class="footer"><strong>' . $companyName . '</strong>'
        . ($contactLine !== '' ? '<br>' . $contactLine : '')
        . '</div></div></body></html>';
}

function taskforce_store_generated_pdf_on_server(string $pdfContent, string $fileName, string $scope = 'hr-evaluations'): ?array
{
    if ($pdfContent === '' || strncmp($pdfContent, '%PDF', 4) !== 0) {
        return null;
    }

    $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '-', basename($fileName));
    if (!is_string($safeName) || $safeName === '' || $safeName === '.' || $safeName === '..') {
        $safeName = 'documento.pdf';
    }
    if (!str_ends_with(strtolower($safeName), '.pdf')) {
        $safeName .= '.pdf';
    }

    $rootStorage = function_exists('app_config')
        ? (string) app_config('paths.storage', __DIR__ . '/storage')
        : __DIR__ . '/storage';
    $folderDate = date('Y/m');
    $targetDir = rtrim($rootStorage, '/\\') . '/generated-pdfs/' . trim($scope, '/\\') . '/' . $folderDate;
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
        return null;
    }

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        $suffix = substr(sha1(uniqid((string) mt_rand(), true)), 0, 8);
    }

    $storedName = date('Ymd-His') . '-' . $suffix . '-' . $safeName;
    $targetPath = $targetDir . '/' . $storedName;
    $written = @file_put_contents($targetPath, $pdfContent, LOCK_EX);
    if ($written === false || (int) $written !== strlen($pdfContent)) {
        @unlink($targetPath);
        return null;
    }

    $diskContent = @file_get_contents($targetPath);
    if (!is_string($diskContent) || $diskContent === '' || strncmp($diskContent, '%PDF', 4) !== 0) {
        @unlink($targetPath);
        return null;
    }

    return [
        'absolute_path' => $targetPath,
        'relative_path' => 'storage/generated-pdfs/' . trim($scope, '/\\') . '/' . $folderDate . '/' . $storedName,
        'content' => $diskContent,
    ];
}

function taskforce_pdf_generation_diagnostics(): string
{
    $engines = [];
    if (class_exists('\Mpdf\Mpdf')) {
        $engines[] = 'mpdf';
    }
    if (function_exists('shell_exec')) {
        $wkhtml = trim((string) @shell_exec('command -v wkhtmltopdf 2>/dev/null'));
        if ($wkhtml !== '') {
            $engines[] = 'wkhtmltopdf';
        }
        foreach (['chromium-browser', 'chromium', 'google-chrome', 'google-chrome-stable', 'msedge'] as $bin) {
            $path = trim((string) @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($path !== '') {
                $engines[] = $bin;
                break;
            }
        }
    }

    if (!$engines) {
        return 'Nenhum motor PDF disponível (mpdf/wkhtmltopdf/chromium).';
    }

    return 'Motores detetados: ' . implode(', ', array_unique($engines)) . '.';
}

function taskforce_can_use_mpdf_engine(): bool
{
    return class_exists('\\Mpdf\\Mpdf');
}

function taskforce_generate_single_evaluation_fpdf_pdf(array $reportData): ?string
{
    if (!class_exists('FPDF')) {
        return null;
    }

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();

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

    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(220, 226, 234);
    $pdf->SetTextColor(31, 41, 55);

    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, $toPdfText((string) ($reportData['title'] ?? 'Avaliação de desempenho')), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $toPdfText((string) ($reportData['employee'] ?? '')), 1, 1, 'L', true);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->Cell(70, 7, $toPdfText('Ano: ' . (string) ($reportData['year'] ?? '')), 1);
    $pdf->Cell(120, 7, $toPdfText('Período: ' . (string) ($reportData['period'] ?? '—')), 1, 1);
    $pdf->Cell(95, 7, $toPdfText('Departamento: ' . (string) ($reportData['department'] ?? '—')), 1);
    $pdf->Cell(95, 7, $toPdfText('Perfil: ' . (string) ($reportData['profile'] ?? '—')), 1, 1);
    $pdf->Cell(0, 7, $toPdfText('Data entrevista: ' . (string) ($reportData['interview_date'] ?? '—')), 1, 1);
    $pdf->Ln(4);

    $metrics = (array) ($reportData['metrics'] ?? []);
    foreach ([
        ['Performance', $metrics['performance'] ?? '—'],
        ['Comportamento', $metrics['behavior'] ?? '—'],
        ['Pontualidade', $metrics['punctuality'] ?? '—'],
        ['Absentismo', $metrics['absence'] ?? '—'],
        ['Total período', $metrics['period_total'] ?? '—'],
    ] as [$label, $value]) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(55, 9, $toPdfText((string) $label), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(135, 9, $toPdfText((string) $value), 1, 1);
    }

    if (!empty($reportData['closure'])) {
        $closure = (array) $reportData['closure'];
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $toPdfText('Fecho anual'), 0, 1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(55, 9, $toPdfText('Bónus final'), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(135, 9, $toPdfText((string) ($closure['final_bonus_value'] ?? '—')), 1, 1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(55, 9, $toPdfText('Total anual'), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(135, 9, $toPdfText((string) ($closure['year_total_with_bonus'] ?? '—')), 1, 1);
    }

    $notes = trim((string) ($reportData['general_notes'] ?? ''));
    if ($notes !== '') {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $toPdfText('Observações RH'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 7, $toPdfText($notes), 1);
    }

    if (!empty($reportData['sent_by'])) {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(0, 7, $toPdfText('Enviado por: ' . (string) $reportData['sent_by']), 0, 1);
    }

    try {
        return (string) $pdf->Output('S');
    } catch (Throwable $exception) {
        return null;
    }
}

function taskforce_send_evaluation_pdf(PDO $pdo, array $employee, array $evaluation, ?array $closure, int $year, string $periodLabel, ?string $sentBy = null): array
{
    $recipientEmail = trim((string) ($employee['email'] ?? ''));
    if ($recipientEmail === '') {
        return ['ok' => false, 'message' => 'O colaborador avaliado não tem email configurado.'];
    }

    $employeeLabel = trim((string) ($employee['user_number'] ?? '')) !== ''
        ? (string) $employee['user_number'] . ' - ' . (string) ($employee['name'] ?? '')
        : (string) ($employee['name'] ?? 'Colaborador');

    $branding = taskforce_get_evaluation_branding($pdo);
    $companyContactLine = taskforce_build_company_contact_line($branding);
    $profileLabel = taskforce_evaluation_profiles()[(string) ($evaluation['award_profile'] ?? $employee['award_profile'] ?? '')]
        ?? (string) ($evaluation['award_profile'] ?? $employee['award_profile'] ?? '—');
    $departmentLabel = (string) ($evaluation['department_name'] ?? $employee['department_name'] ?? '—');
    $interviewDate = (string) (($evaluation['interview_date'] ?? '') ?: '—');
    $generalNotes = trim((string) ($evaluation['general_notes'] ?? ''));

    $lines = [
        'TaskForce RH - Avaliação de desempenho',
        (string) ($branding['company_name'] ?? 'TaskForce'),
        (string) ($branding['company_address'] ?? ''),
        $companyContactLine,
        'Colaborador: ' . $employeeLabel,
        'Ano: ' . $year . ' | Período: ' . $periodLabel,
        'Departamento: ' . $departmentLabel,
        'Perfil: ' . $profileLabel,
        'Data entrevista: ' . $interviewDate,
        '',
        'Performance: ' . (int) ($evaluation['performance_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['performance_value'] ?? 0)) . ')',
        'Comportamento: ' . (int) ($evaluation['behavior_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['behavior_value'] ?? 0)) . ')',
        'Pontualidade: ' . (int) ($evaluation['punctuality_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['punctuality_value'] ?? 0)) . ')',
        'Absentismo: ' . (int) ($evaluation['absence_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['absence_value'] ?? 0)) . ')',
        'Total período: ' . taskforce_money((float) ($evaluation['period_total'] ?? 0)),
    ];

    if ($closure) {
        $lines[] = '';
        $lines[] = 'Fecho anual';
        $lines[] = 'Bónus final: ' . taskforce_money((float) ($closure['final_bonus_value'] ?? 0));
        $lines[] = 'Total anual: ' . taskforce_money((float) ($closure['year_total_with_bonus'] ?? 0));
    }

    if ($generalNotes !== '') {
        $lines[] = '';
        $lines[] = 'Observações RH: ' . $generalNotes;
    }

    if ($sentBy) {
        $lines[] = '';
        $lines[] = 'Enviado por: ' . $sentBy;
    }

    $pdfContent = taskforce_generate_basic_pdf($lines);
    $safeName = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string) ($employee['name'] ?? 'colaborador')));
    $fileName = 'avaliacao-' . trim((string) $safeName, '-') . '-' . $year . '-' . (string) ($evaluation['award_period'] ?? 'periodo') . '.pdf';

    $singlePayload = [
        'title' => 'Avaliação de desempenho',
        'employee' => $employeeLabel,
        'year' => (string) $year,
        'period' => $periodLabel,
        'department' => $departmentLabel,
        'profile' => $profileLabel,
        'interview_date' => $interviewDate,
        'general_notes' => $generalNotes,
        'sent_by' => $sentBy ?? '',
        'metrics' => [
            'performance' => (int) ($evaluation['performance_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['performance_value'] ?? 0)) . ')',
            'behavior' => (int) ($evaluation['behavior_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['behavior_value'] ?? 0)) . ')',
            'punctuality' => (int) ($evaluation['punctuality_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['punctuality_value'] ?? 0)) . ')',
            'absence' => (int) ($evaluation['absence_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['absence_value'] ?? 0)) . ')',
            'period_total' => taskforce_money((float) ($evaluation['period_total'] ?? 0)),
        ],
        'closure' => $closure ? [
            'final_bonus_value' => taskforce_money((float) ($closure['final_bonus_value'] ?? 0)),
            'year_total_with_bonus' => taskforce_money((float) ($closure['year_total_with_bonus'] ?? 0)),
        ] : [],
        'lines' => $lines,
    ];

    $pdfEngine = 'FPDF';
    $pdfContent = taskforce_generate_single_evaluation_fpdf_pdf($singlePayload);

    if ((!is_string($pdfContent) || $pdfContent === '') && taskforce_can_use_mpdf_engine()) {
        $pdfEngine = 'layout oficial';
        $headerLogoHtml = (string) ($branding['logo_url'] ?? '') !== ''
            ? '<div class="brand-logo"><img src="' . h((string) ($branding['logo_url'] ?? '')) . '" alt="Logótipo"></div>'
            : '';
        $headerAddressHtml = (string) ($branding['company_address'] ?? '') !== ''
            ? '<div class="brand-address">' . nl2br(h((string) ($branding['company_address'] ?? ''))) . '</div>'
            : '';
        $footerContactHtml = $companyContactLine !== '' ? '<div class="muted">' . h($companyContactLine) . '</div>' : '';

        $html = '<!doctype html><html lang="pt"><head><meta charset="utf-8"><style>'
            . 'body{font-family:Helvetica,Arial,sans-serif;color:#1f2937;font-size:12px;margin:18px;}'
            . '.pdf-header{width:100%;border-collapse:collapse;border-bottom:2px solid #e5e7eb;margin-bottom:14px;padding-bottom:8px;}'
            . '.pdf-header td{vertical-align:top;padding-bottom:8px;}'
            . '.brand-name{font-size:14px;font-weight:700;} .brand-address{font-size:11px;color:#4b5563;margin-top:4px;line-height:1.45;}'
            . '.brand-logo{text-align:right;} .brand-logo img{max-height:56px;max-width:190px;}'
            . '.title{font-size:24px;font-weight:700;margin:0 0 8px;}'
            . '.soft-card{border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;padding:10px 12px;margin-bottom:10px;}'
            . '.meta-table{width:100%;border-collapse:collapse;margin-top:8px;} .meta-table td{padding:5px 6px;border:1px solid #e5e7eb;}'
            . '.metric-grid{width:100%;border-collapse:separate;border-spacing:8px;margin:10px 0;} .metric{border:1px solid #e5e7eb;border-radius:10px;padding:8px;background:#fff;} .label{color:#6b7280;font-size:10px;display:block;} .value{font-size:18px;font-weight:700;display:block;margin-top:3px;}'
            . '.footer{margin-top:14px;padding-top:10px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:11px;}'
            . '</style></head><body>'
            . '<table class="pdf-header" role="presentation"><tr><td><div class="brand-name">' . h((string) ($branding['company_name'] ?? 'TaskForce')) . '</div>' . $headerAddressHtml . '</td><td width="220">' . $headerLogoHtml . '</td></tr></table>'
            . '<div class="title">Avaliação de desempenho</div>'
            . '<div class="soft-card"><strong>' . h($employeeLabel) . '</strong><table class="meta-table"><tr><td>Ano</td><td>' . $year . '</td><td>Período</td><td>' . h($periodLabel) . '</td></tr><tr><td>Departamento</td><td>' . h($departmentLabel) . '</td><td>Perfil</td><td>' . h($profileLabel) . '</td></tr><tr><td>Data entrevista</td><td colspan="3">' . h($interviewDate) . '</td></tr></table></div>'
            . '<table class="metric-grid"><tr>'
            . '<td class="metric"><span class="label">Performance</span><span class="value">' . (int) ($evaluation['performance_score'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['performance_value'] ?? 0))) . ')</span></td>'
            . '<td class="metric"><span class="label">Comportamento</span><span class="value">' . (int) ($evaluation['behavior_score'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['behavior_value'] ?? 0))) . ')</span></td>'
            . '</tr><tr>'
            . '<td class="metric"><span class="label">Pontualidade</span><span class="value">' . (int) ($evaluation['punctuality_count'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['punctuality_value'] ?? 0))) . ')</span></td>'
            . '<td class="metric"><span class="label">Absentismo</span><span class="value">' . (int) ($evaluation['absence_count'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['absence_value'] ?? 0))) . ')</span></td>'
            . '</tr><tr><td class="metric" colspan="2"><span class="label">Total período</span><span class="value">' . h(taskforce_money((float) ($evaluation['period_total'] ?? 0))) . '</span></td></tr></table>'
            . ($generalNotes !== '' ? '<div class="soft-card"><strong>Observações RH</strong><div style="margin-top:6px;">' . nl2br(h($generalNotes)) . '</div></div>' : '')
            . ($closure ? '<div class="soft-card"><strong>Fecho anual</strong><div style="margin-top:6px;">Bónus final: ' . h(taskforce_money((float) ($closure['final_bonus_value'] ?? 0))) . '<br>Total anual: ' . h(taskforce_money((float) ($closure['year_total_with_bonus'] ?? 0))) . '</div></div>' : '')
            . ($sentBy ? '<p class="muted">Enviado por: ' . h($sentBy) . '</p>' : '')
            . '<footer class="footer"><strong>' . h((string) ($branding['company_name'] ?? 'TaskForce')) . '</strong>'
            . ((string) ($branding['company_address'] ?? '') !== '' ? '<div>' . nl2br(h((string) ($branding['company_address'] ?? ''))) . '</div>' : '')
            . $footerContactHtml
            . '</footer></body></html>';
        $pdfContent = taskforce_generate_pdf_from_html($html);
    }

    if (!is_string($pdfContent) || $pdfContent === '') {
        $pdfEngine = 'modo compatibilidade';
        $pdfContent = taskforce_generate_basic_pdf($lines);
    }

    if (!is_string($pdfContent) || $pdfContent === '' || strncmp($pdfContent, '%PDF', 4) !== 0) {
        return [
            'ok' => false,
            'message' => 'Não foi possível gerar o PDF. ' . taskforce_pdf_generation_diagnostics(),
        ];
    }

    $safeName = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string) ($employee['name'] ?? 'colaborador')));
    $fileName = 'avaliacao-' . trim((string) $safeName, '-') . '-' . $year . '-' . (string) ($evaluation['award_period'] ?? 'periodo') . '.pdf';

    $headerLogoHtml = (string) ($branding['logo_url'] ?? '') !== ''
        ? '<div class="brand-logo"><img src="' . h((string) $branding['logo_url']) . '" alt="Logótipo"></div>'
        : '';
    $headerAddressHtml = (string) ($branding['company_address'] ?? '') !== ''
        ? '<div class="brand-address">' . nl2br(h((string) $branding['company_address'])) . '</div>'
        : '';
    $footerContactHtml = $companyContactLine !== '' ? '<div class="muted">' . h($companyContactLine) . '</div>' : '';

    $html = '<!doctype html><html lang="pt"><head><meta charset="utf-8"><style>'
        . 'body{font-family:Helvetica,Arial,sans-serif;color:#1f2937;font-size:12px;margin:18px;}'
        . '.pdf-header{width:100%;border-collapse:collapse;border-bottom:2px solid #e5e7eb;margin-bottom:14px;padding-bottom:8px;}'
        . '.pdf-header td{vertical-align:top;padding-bottom:8px;}'
        . '.brand-name{font-size:14px;font-weight:700;} .brand-address{font-size:11px;color:#4b5563;margin-top:4px;line-height:1.45;}'
        . '.brand-logo{text-align:right;} .brand-logo img{max-height:56px;max-width:190px;}'
        . '.title{font-size:24px;font-weight:700;margin:0 0 8px;}'
        . '.soft-card{border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;padding:10px 12px;margin-bottom:10px;}'
        . '.meta-table{width:100%;border-collapse:collapse;margin-top:8px;} .meta-table td{padding:5px 6px;border:1px solid #e5e7eb;}'
        . '.metric-grid{width:100%;border-collapse:separate;border-spacing:8px;margin:10px 0;}'
        . '.metric{border:1px solid #e5e7eb;border-radius:10px;padding:8px;background:#fff;} .label{color:#6b7280;font-size:10px;display:block;} .value{font-size:18px;font-weight:700;}'
        . 'table{width:100%;border-collapse:collapse;margin-top:10px;} th,td{border-bottom:1px solid #e5e7eb;padding:7px;text-align:left;vertical-align:top;} th{font-size:11px;background:#f3f4f6;}'
        . '.footer{margin-top:14px;padding-top:10px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:11px;}'
        . '.muted{color:#6b7280;}'
        . '</style></head><body>'
        . '<table class="pdf-header" role="presentation"><tr><td><div class="brand-name">' . h((string) ($branding['company_name'] ?? 'TaskForce')) . '</div>' . $headerAddressHtml . '</td>'
        . '<td width="220">' . $headerLogoHtml . '</td></tr></table>'
        . '<h1 class="title">Avaliação de desempenho</h1>'
        . '<div class="soft-card"><strong>' . h($employeeLabel) . '</strong>'
        . '<table class="meta-table"><tr>'
        . '<td><span class="label">Ano</span><strong>' . (int) $year . '</strong></td>'
        . '<td><span class="label">Período</span><strong>' . h($periodLabel) . '</strong></td>'
        . '<td><span class="label">Departamento</span><strong>' . h($departmentLabel) . '</strong></td>'
        . '</tr><tr>'
        . '<td><span class="label">Perfil</span><strong>' . h($profileLabel) . '</strong></td>'
        . '<td colspan="2"><span class="label">Entrevista</span><strong>' . h($interviewDate) . '</strong></td>'
        . '</tr></table></div>'
        . '<table class="metric-grid" role="presentation"><tr>'
        . '<td class="metric"><span class="label">Performance</span><div class="value">' . (int) ($evaluation['performance_score'] ?? 0) . '</div><div>' . h(taskforce_money((float) ($evaluation['performance_value'] ?? 0))) . '</div></td>'
        . '<td class="metric"><span class="label">Comportamento</span><div class="value">' . (int) ($evaluation['behavior_score'] ?? 0) . '</div><div>' . h(taskforce_money((float) ($evaluation['behavior_value'] ?? 0))) . '</div></td>'
        . '<td class="metric"><span class="label">Pontualidade</span><div class="value">' . (int) ($evaluation['punctuality_count'] ?? 0) . '</div><div>' . h(taskforce_money((float) ($evaluation['punctuality_value'] ?? 0))) . '</div></td>'
        . '<td class="metric"><span class="label">Absentismo</span><div class="value">' . (int) ($evaluation['absence_count'] ?? 0) . '</div><div>' . h(taskforce_money((float) ($evaluation['absence_value'] ?? 0))) . '</div></td>'
        . '</tr></table>'
        . '<table><thead><tr><th>Total período</th><th>Bónus final</th><th>Total anual</th></tr></thead><tbody><tr>'
        . '<td><strong>' . h(taskforce_money((float) ($evaluation['period_total'] ?? 0))) . '</strong></td>'
        . '<td>' . h(taskforce_money((float) ($closure['final_bonus_value'] ?? 0))) . '</td>'
        . '<td><strong>' . h(taskforce_money((float) ($closure['year_total_with_bonus'] ?? ($evaluation['period_total'] ?? 0)))) . '</strong></td>'
        . '</tr></tbody></table>'
        . ($generalNotes !== '' ? '<div class="soft-card" style="margin-top:10px;"><span class="label">Observações RH</span>' . nl2br(h($generalNotes)) . '</div>' : '')
        . ($sentBy ? '<p class="muted">Enviado por: ' . h($sentBy) . '</p>' : '')
        . '<footer class="footer"><strong>' . h((string) ($branding['company_name'] ?? 'TaskForce')) . '</strong>'
        . ((string) ($branding['company_address'] ?? '') !== '' ? '<div>' . nl2br(h((string) $branding['company_address'])) . '</div>' : '')
        . $footerContactHtml
        . '</footer></body></html>';

    $pdfEngine = 'layout oficial';
    $pdfContent = taskforce_generate_pdf_from_html($html);
    if (!is_string($pdfContent) || $pdfContent === '') {
        return [
            'ok' => false,
            'message' => 'Não foi possível gerar o PDF com o layout oficial. ' . taskforce_pdf_generation_diagnostics(),
        ];
        $pdfContent = taskforce_generate_evaluation_history_layout_pdf($singleFallbackPayload);
        $pdfEngine = 'modo compatibilidade';
    }
    if (!is_string($pdfContent) || $pdfContent === '' || strncmp($pdfContent, '%PDF', 4) !== 0) {
        return [
            'ok' => false,
            'message' => 'Não foi possível gerar o PDF. ' . taskforce_pdf_generation_diagnostics(),
        ];
    }

    $storedPdf = taskforce_store_generated_pdf_on_server($pdfContent, $fileName, 'avaliacoes');
    if (!is_array($storedPdf) || !isset($storedPdf['content'])) {
        return ['ok' => false, 'message' => 'Não foi possível guardar o PDF no servidor antes do envio.'];
    }

    $subject = '[TaskForce RH] Avaliação ' . $periodLabel . ' - ' . (string) ($employee['name'] ?? 'Colaborador');
    $body = "Olá " . (string) ($employee['name'] ?? '') . ",\n\nSegue em anexo o PDF da sua avaliação (" . $periodLabel . ' de ' . $year . ").\n\nCumprimentos,\nEquipa RH";
    $htmlBody = taskforce_build_evaluation_mail_html(
        (string) ($employee['name'] ?? 'Colaborador'),
        'Segue em anexo o PDF da sua avaliação (' . $periodLabel . ' de ' . $year . ').',
        $branding
    );

    $sent = deliver_report($recipientEmail, $subject, $body, $htmlBody, [[
        'name' => $fileName,
        'mime' => 'application/pdf',
        'content' => (string) $storedPdf['content'],
    ]]);

    return $sent
        ? ['ok' => true, 'message' => 'PDF guardado no servidor e enviado por email para o colaborador avaliado (' . $pdfEngine . ').']
        : ['ok' => false, 'message' => 'Não foi possível enviar o email com o PDF da avaliação.'];
}

function taskforce_send_evaluation_history_pdf(PDO $pdo, array $employee, int $year, array $evaluations, array $metrics, string $predominantRule, ?array $closure, ?string $sentBy = null): array
{
    $recipientEmail = trim((string) ($employee['email'] ?? ''));
    if ($recipientEmail === '') {
        return ['ok' => false, 'message' => 'O colaborador avaliado não tem email configurado.'];
    }
    $branding = taskforce_get_evaluation_branding($pdo);
    $companyContactLine = taskforce_build_company_contact_line($branding);

    $employeeLabel = trim((string) ($employee['user_number'] ?? '')) !== ''
        ? (string) $employee['user_number'] . ' - ' . (string) ($employee['name'] ?? '')
        : (string) ($employee['name'] ?? 'Colaborador');

    $rowsHtml = '';
    foreach ($evaluations as $evaluation) {
        $rowsHtml .= '<tr>'
            . '<td>' . h(taskforce_evaluation_period_label((string) ($evaluation['award_period'] ?? ''))) . '</td>'
            . '<td>' . h((string) (($evaluation['interview_date'] ?? '') ?: '—')) . '</td>'
            . '<td>' . (int) ($evaluation['performance_score'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['performance_value'] ?? 0))) . ')</td>'
            . '<td>' . (int) ($evaluation['behavior_score'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['behavior_value'] ?? 0))) . ')</td>'
            . '<td>' . (int) ($evaluation['punctuality_count'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['punctuality_value'] ?? 0))) . ')</td>'
            . '<td>' . (int) ($evaluation['absence_count'] ?? 0) . ' (' . h(taskforce_money((float) ($evaluation['absence_value'] ?? 0))) . ')</td>'
            . '<td><strong>' . h(taskforce_money((float) ($evaluation['period_total'] ?? 0))) . '</strong></td>'
            . '<td>' . h((string) ($evaluation['general_notes'] ?? '')) . '</td>'
            . '</tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="8">Sem avaliações neste ano.</td></tr>';
    }

    $closureHtml = !$closure
        ? '<p>Ainda sem fecho anual registado para este colaborador.</p>'
        : '<p>Absentismo final: <strong>' . (int) ($closure['final_absence_count'] ?? 0) . '</strong></p>'
            . '<p>Bónus final: <strong>' . h(taskforce_money((float) ($closure['final_bonus_value'] ?? 0))) . '</strong></p>'
            . '<p>Total anual c/ bónus: <strong>' . h(taskforce_money((float) ($closure['year_total_with_bonus'] ?? 0))) . '</strong></p>'
            . '<p>Notas: ' . h((string) ($closure['final_notes'] ?? '')) . '</p>';

    $headerLogoHtml = (string) ($branding['logo_url'] ?? '') !== ''
        ? '<div class="brand-logo"><img src="' . h((string) $branding['logo_url']) . '" alt="Logótipo"></div>'
        : '';
    $headerAddressHtml = (string) ($branding['company_address'] ?? '') !== ''
        ? '<div class="brand-address">' . nl2br(h((string) $branding['company_address'])) . '</div>'
        : '';
    $footerContactHtml = $companyContactLine !== '' ? '<div class="muted">' . h($companyContactLine) . '</div>' : '';

    $html = '<!doctype html><html lang="pt"><head><meta charset="utf-8"><style>'
        . 'body{font-family:Helvetica,Arial,sans-serif;color:#1f2937;font-size:12px;padding:18px;}'
        . '.pdf-header{width:100%;border-collapse:collapse;border-bottom:2px solid #e5e7eb;margin-bottom:14px;padding-bottom:8px;}'
        . '.pdf-header td{vertical-align:top;padding-bottom:8px;}'
        . '.brand-name{font-size:14px;font-weight:700;} .brand-address{font-size:11px;color:#4b5563;margin-top:4px;line-height:1.45;}'
        . '.brand-logo{text-align:right;} .brand-logo img{max-height:56px;max-width:190px;}'
        . 'h1{font-size:24px;margin:0 0 12px;} h2{font-size:18px;margin:0 0 10px;}'
        . '.card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:12px;}'
        . '.grid{width:100%;border-collapse:separate;border-spacing:8px;} .grid td{border:1px solid #e5e7eb;border-radius:10px;padding:10px;vertical-align:top;}'
        . '.label{display:block;color:#6b7280;font-size:11px;margin-bottom:2px;} .value{font-size:24px;font-weight:700;}'
        . 'table{width:100%;border-collapse:collapse;} th,td{border-bottom:1px solid #e5e7eb;padding:7px;text-align:left;font-size:11px;}'
        . 'th{font-size:12px;} .muted{color:#6b7280;}'
        . '.footer{margin-top:14px;padding-top:10px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:11px;}'
        . '</style></head><body>'
        . '<table class="pdf-header" role="presentation"><tr><td><div class="brand-name">' . h((string) ($branding['company_name'] ?? 'TaskForce')) . '</div>' . $headerAddressHtml . '</td>'
        . '<td width="220">' . $headerLogoHtml . '</td></tr></table>'
        . '<h1>Histórico de avaliações</h1>'
        . '<div class="card">'
        . '<strong>' . h($employeeLabel) . '</strong><br>'
        . 'Ano: ' . $year . ' &nbsp;|&nbsp; Departamento: ' . h((string) ($employee['department_name'] ?? '—')) . '<br>'
        . 'Perfil: ' . h((string) ($employee['award_profile'] ?? 'operador')) . ' &nbsp;|&nbsp; Regra predominante: ' . h($predominantRule)
        . '</div>'
        . '<table class="grid"><tr>'
        . '<td><span class="label">Nº avaliações</span><span class="value">' . (int) ($metrics['count'] ?? 0) . '</span></td>'
        . '<td><span class="label">Soma prémios período</span><span class="value">' . h(taskforce_money((float) ($metrics['sum_period_total'] ?? 0))) . '</span></td>'
        . '<td><span class="label">Bónus final</span><span class="value">' . h(taskforce_money((float) ($closure['final_bonus_value'] ?? 0))) . '</span></td>'
        . '<td><span class="label">Total anual</span><span class="value">' . h(taskforce_money((float) ($closure['year_total_with_bonus'] ?? ($metrics['sum_period_total'] ?? 0)))) . '</span></td>'
        . '</tr></table>'
        . '<div class="card"><h2>Avaliações do ano</h2>'
        . '<table><thead><tr><th>Período</th><th>Entrevista</th><th>Performance</th><th>Comportamento</th><th>Pontualidade</th><th>Absentismo</th><th>Total período</th><th>Observações RH</th></tr></thead><tbody>'
        . $rowsHtml . '</tbody></table></div>'
        . '<div class="card"><h2>Fecho anual</h2>' . $closureHtml . '</div>'
        . ($sentBy ? '<p class="muted">Enviado por: ' . h($sentBy) . '</p>' : '')
        . '<footer class="footer"><strong>' . h((string) ($branding['company_name'] ?? 'TaskForce')) . '</strong>'
        . ((string) ($branding['company_address'] ?? '') !== '' ? '<div>' . nl2br(h((string) $branding['company_address'])) . '</div>' : '')
        . $footerContactHtml
        . '</footer>'
        . '</body></html>';

    $fallbackPayload = [
        'title' => 'Histórico de avaliações',
        'employee' => $employeeLabel,
        'year' => (string) $year,
        'department' => (string) ($employee['department_name'] ?? '—'),
        'profile' => (string) ($employee['award_profile'] ?? 'operador'),
        'predominant_rule' => $predominantRule,
        'metrics' => $metrics,
        'closure' => $closure ?? [],
        'evaluations' => $evaluations,
        'sent_by' => $sentBy ?? '',
        'lines' => [
            'TaskForce RH - Histórico de avaliações',
            (string) ($branding['company_name'] ?? 'TaskForce'),
            (string) ($branding['company_address'] ?? ''),
            $companyContactLine,
            'Colaborador: ' . $employeeLabel,
            'Ano: ' . $year,
            'Nº avaliações: ' . (int) ($metrics['count'] ?? 0),
            'Soma prémios período: ' . taskforce_money((float) ($metrics['sum_period_total'] ?? 0)),
            'Regra predominante: ' . $predominantRule,
        ],
    ];

    $pdfEngine = 'FPDF';
    $pdfContent = taskforce_generate_evaluation_history_fpdf_pdf($fallbackPayload);
    if ((!is_string($pdfContent) || $pdfContent === '') && taskforce_can_use_mpdf_engine()) {
        $pdfEngine = 'layout oficial';
        $pdfContent = taskforce_generate_pdf_from_html($html);
    }
    if (!is_string($pdfContent) || $pdfContent === '') {
        $pdfContent = taskforce_generate_evaluation_history_layout_pdf($fallbackPayload);
        $pdfEngine = 'modo compatibilidade';
    }
    if (!is_string($pdfContent) || $pdfContent === '' || strncmp($pdfContent, '%PDF', 4) !== 0) {
        return [
            'ok' => false,
            'message' => 'Não foi possível gerar o PDF. ' . taskforce_pdf_generation_diagnostics(),
        ];
    }

    $safeName = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string) ($employee['name'] ?? 'colaborador')));
    $fileName = 'historico-avaliacoes-' . trim((string) $safeName, '-') . '-' . $year . '.pdf';
    $storedPdf = taskforce_store_generated_pdf_on_server($pdfContent, $fileName, 'historico-avaliacoes');
    if (!is_array($storedPdf) || !isset($storedPdf['content'])) {
        return ['ok' => false, 'message' => 'Não foi possível guardar o PDF no servidor antes do envio.'];
    }
    $subject = '[TaskForce RH] Histórico de avaliações ' . $year . ' - ' . (string) ($employee['name'] ?? 'Colaborador');
    $body = "Olá " . (string) ($employee['name'] ?? '') . ",\n\nSegue em anexo o PDF do seu histórico de avaliações de " . $year . ".\n\nCumprimentos,\nEquipa RH";
    $htmlBody = taskforce_build_evaluation_mail_html(
        (string) ($employee['name'] ?? 'Colaborador'),
        'Segue em anexo o PDF do seu histórico de avaliações de ' . $year . '.',
        $branding
    );

    $sent = deliver_report($recipientEmail, $subject, $body, $htmlBody, [[
        'name' => $fileName,
        'mime' => 'application/pdf',
        'content' => (string) $storedPdf['content'],
    ]]);

    return $sent
        ? ['ok' => true, 'message' => 'PDF do histórico guardado no servidor e enviado por email para o colaborador avaliado (' . $pdfEngine . ').']
        : ['ok' => false, 'message' => 'Não foi possível enviar o email com o PDF do histórico.'];
}

function taskforce_generate_evaluation_history_fpdf_pdf(array $reportData): ?string
{
    if (!class_exists('FPDF')) {
        return null;
    }

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();

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
    $fitText = static function (string $value, int $limit = 48): string {
        if ($limit <= 0) {
            return '';
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '—';
        }
        if (function_exists('mb_strimwidth')) {
            $short = mb_strimwidth($trimmed, 0, $limit, '…', 'UTF-8');
            return $short !== '' ? $short : '—';
        }
        if (strlen($trimmed) <= $limit) {
            return $trimmed;
        }
        return substr($trimmed, 0, max(0, $limit - 3)) . '...';
    };

    $metrics = (array) ($reportData['metrics'] ?? []);
    $closure = (array) ($reportData['closure'] ?? []);
    $evaluations = (array) ($reportData['evaluations'] ?? []);

    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(220, 226, 234);
    $pdf->SetTextColor(31, 41, 55);

    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, $toPdfText((string) ($reportData['title'] ?? 'Histórico de avaliações')), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $toPdfText((string) ($reportData['employee'] ?? '')), 1, 1, 'L', true);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->Cell(40, 7, $toPdfText('Ano: ' . (string) ($reportData['year'] ?? '')), 1);
    $pdf->Cell(150, 7, $toPdfText('Departamento: ' . $fitText((string) ($reportData['department'] ?? '—'), 76)), 1, 1);
    $pdf->Cell(0, 7, $toPdfText('Regra predominante: ' . $fitText((string) ($reportData['predominant_rule'] ?? '—'), 96)), 1, 1);
    $pdf->Ln(3);

    $metricWidth = 47.5;
    $metricBlocks = [
        ['Nº avaliações', (string) ((int) ($metrics['count'] ?? 0))],
        ['Soma prémios período', taskforce_money((float) ($metrics['sum_period_total'] ?? 0))],
        ['Bónus final', taskforce_money((float) ($closure['final_bonus_value'] ?? 0))],
        ['Total anual', taskforce_money((float) ($closure['year_total_with_bonus'] ?? ($metrics['sum_period_total'] ?? 0)))],
    ];
    foreach ($metricBlocks as [$label, $value]) {
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($metricWidth, 6, $toPdfText($label), 1, 0, 'L', true);
    }
    $pdf->Ln();
    foreach ($metricBlocks as [, $value]) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell($metricWidth, 9, $toPdfText($value), 1, 0, 'L');
    }
    $pdf->Ln(13);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, $toPdfText('Avaliações do ano'), 0, 1);
    $pdf->SetFont('Arial', 'B', 8.2);
    $headers = [
        ['Período', 25], ['Entrevista', 20], ['Performance', 25], ['Comportamento', 25],
        ['Pontualidade', 24], ['Absentismo', 22], ['Total', 18], ['Obs. RH', 31],
    ];
    foreach ($headers as [$label, $w]) {
        $pdf->Cell($w, 7, $toPdfText($label), 1, 0, 'L', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 7.8);
    if (!$evaluations) {
        $pdf->Cell(210 - 20, 7, $toPdfText('Sem avaliações neste ano.'), 1, 1);
    } else {
        foreach ($evaluations as $evaluation) {
            $row = [
                $fitText(taskforce_evaluation_period_label((string) ($evaluation['award_period'] ?? '')), 18),
                $fitText((string) (($evaluation['interview_date'] ?? '') ?: '—'), 12),
                $fitText((int) ($evaluation['performance_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['performance_value'] ?? 0)) . ')', 22),
                $fitText((int) ($evaluation['behavior_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['behavior_value'] ?? 0)) . ')', 22),
                $fitText((int) ($evaluation['punctuality_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['punctuality_value'] ?? 0)) . ')', 22),
                $fitText((int) ($evaluation['absence_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['absence_value'] ?? 0)) . ')', 20),
                $fitText(taskforce_money((float) ($evaluation['period_total'] ?? 0)), 14),
                $fitText((string) ($evaluation['general_notes'] ?? ''), 30),
            ];
            foreach ($row as $idx => $cell) {
                $w = (int) $headers[$idx][1];
                $pdf->Cell($w, 7, $toPdfText($cell), 1, 0, 'L');
            }
            $pdf->Ln();
        }
    }

    if (!empty($reportData['sent_by'])) {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(75, 85, 99);
        $pdf->Cell(0, 6, $toPdfText('Enviado por: ' . (string) $reportData['sent_by']), 0, 1);
    }

    try {
        return (string) $pdf->Output('S');
    } catch (Throwable $exception) {
        return null;
    }
}

function taskforce_generate_evaluation_history_layout_pdf(array $reportData): string
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
    $cardBg = imagecolorallocate($image, 249, 250, 251);
    imagefill($image, 0, 0, $white);

    $toRenderableText = static function (string $value): string {
        if (!function_exists('iconv')) {
            return $value;
        }
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted !== false ? $converted : $value;
    };
    $drawText = static function ($image, int $size, int $x, int $y, int $color, string $content) use ($canUseTtf, $fontPath, $toRenderableText): void {
        if ($canUseTtf) {
            imagettftext($image, $size, 0, $x, $y, $color, $fontPath, $content);
            return;
        }
        $font = $size >= 18 ? 5 : ($size >= 14 ? 4 : 3);
        imagestring($image, $font, $x, $y - imagefontheight($font), $toRenderableText($content), $color);
    };

    $y = $scale(70);
    $drawText($image, $scale(30), $scale(60), $y, $text, (string) ($reportData['title'] ?? 'Histórico de avaliações'));
    $y += $scale(44);

    imagefilledrectangle($image, $scale(60), $y, $scale(1180), $y + $scale(120), $cardBg);
    imagerectangle($image, $scale(60), $y, $scale(1180), $y + $scale(120), $border);
    $drawText($image, $scale(16), $scale(80), $y + $scale(34), $text, (string) ($reportData['employee'] ?? ''));
    $drawText($image, $scale(14), $scale(80), $y + $scale(64), $muted, 'Ano: ' . (string) ($reportData['year'] ?? ''));
    $drawText($image, $scale(14), $scale(420), $y + $scale(64), $muted, 'Regra predominante: ' . (string) ($reportData['predominant_rule'] ?? '—'));
    $drawText($image, $scale(14), $scale(800), $y + $scale(64), $muted, 'Departamento: ' . (string) ($reportData['department'] ?? '—'));
    $y += $scale(150);

    $metrics = (array) ($reportData['metrics'] ?? []);
    $closure = (array) ($reportData['closure'] ?? []);
    $metricCards = [
        ['Nº avaliações', (string) ((int) ($metrics['count'] ?? 0))],
        ['Soma prémios período', taskforce_money((float) ($metrics['sum_period_total'] ?? 0))],
        ['Bónus final', taskforce_money((float) ($closure['final_bonus_value'] ?? 0))],
        ['Total anual', taskforce_money((float) ($closure['year_total_with_bonus'] ?? ($metrics['sum_period_total'] ?? 0)))],
    ];
    $cardWidth = $scale(270);
    $x = $scale(60);
    foreach ($metricCards as [$label, $value]) {
        imagefilledrectangle($image, $x, $y, $x + $cardWidth, $y + $scale(105), $cardBg);
        imagerectangle($image, $x, $y, $x + $cardWidth, $y + $scale(105), $border);
        $drawText($image, $scale(12), $x + $scale(14), $y + $scale(30), $muted, $label);
        $drawText($image, $scale(24), $x + $scale(14), $y + $scale(76), $text, $value);
        $x += $cardWidth + $scale(18);
    }
    $y += $scale(140);

    $drawText($image, $scale(20), $scale(60), $y, $text, 'Avaliações do ano');
    $y += $scale(25);
    $columns = [
        ['Período', $scale(180)],
        ['Entrevista', $scale(150)],
        ['Performance', $scale(180)],
        ['Comportamento', $scale(180)],
        ['Pontualidade', $scale(170)],
        ['Absentismo', $scale(160)],
        ['Total', $scale(120)],
    ];
    $x = $scale(60);
    foreach ($columns as [$label, $w]) {
        imagefilledrectangle($image, $x, $y, $x + $w, $y + $scale(34), $cardBg);
        imagerectangle($image, $x, $y, $x + $w, $y + $scale(34), $border);
        $drawText($image, $scale(12), $x + $scale(8), $y + $scale(22), $text, $label);
        $x += $w;
    }
    $y += $scale(34);

    foreach ((array) ($reportData['evaluations'] ?? []) as $evaluation) {
        if ($y > $scale(1440)) {
            break;
        }
        $cells = [
            taskforce_evaluation_period_label((string) ($evaluation['award_period'] ?? '')),
            (string) (($evaluation['interview_date'] ?? '') ?: '—'),
            (int) ($evaluation['performance_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['performance_value'] ?? 0)) . ')',
            (int) ($evaluation['behavior_score'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['behavior_value'] ?? 0)) . ')',
            (int) ($evaluation['punctuality_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['punctuality_value'] ?? 0)) . ')',
            (int) ($evaluation['absence_count'] ?? 0) . ' (' . taskforce_money((float) ($evaluation['absence_value'] ?? 0)) . ')',
            taskforce_money((float) ($evaluation['period_total'] ?? 0)),
        ];
        $x = $scale(60);
        foreach ($columns as $idx => $column) {
            [, $w] = $column;
            imagerectangle($image, $x, $y, $x + $w, $y + $scale(30), $border);
            $txt = function_exists('mb_substr') ? mb_substr((string) $cells[$idx], 0, 28) : substr((string) $cells[$idx], 0, 28);
            $drawText($image, $scale(11), $x + $scale(7), $y + $scale(20), $text, $txt);
            $x += $w;
        }
        $y += $scale(30);
    }

    if (!empty($reportData['sent_by'])) {
        $drawText($image, $scale(12), $scale(60), $scale(1690), $muted, 'Enviado por: ' . (string) $reportData['sent_by']);
    }

    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    if ($jpeg === '') {
        return taskforce_generate_basic_pdf($reportData['lines'] ?? []);
    }

    return taskforce_pdf_from_jpeg($jpeg, $width, $height);
}
