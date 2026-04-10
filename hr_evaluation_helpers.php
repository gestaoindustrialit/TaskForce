<?php

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

function taskforce_send_evaluation_pdf(PDO $pdo, array $employee, array $evaluation, ?array $closure, int $year, string $periodLabel, ?string $sentBy = null): array
{
    $recipientEmail = trim((string) ($employee['email'] ?? ''));
    if ($recipientEmail === '') {
        return ['ok' => false, 'message' => 'O colaborador avaliado não tem email configurado.'];
    }

    $employeeLabel = trim((string) ($employee['user_number'] ?? '')) !== ''
        ? (string) $employee['user_number'] . ' - ' . (string) ($employee['name'] ?? '')
        : (string) ($employee['name'] ?? 'Colaborador');

    $lines = [
        'TaskForce RH - Avaliação de desempenho',
        'Colaborador: ' . $employeeLabel,
        'Ano: ' . $year . ' | Período: ' . $periodLabel,
        'Departamento: ' . (string) ($evaluation['department_name'] ?? $employee['department_name'] ?? '—'),
        'Perfil: ' . (string) ($evaluation['award_profile'] ?? $employee['award_profile'] ?? '—'),
        'Data entrevista: ' . (string) (($evaluation['interview_date'] ?? '') ?: '—'),
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

    $notes = trim((string) ($evaluation['general_notes'] ?? ''));
    if ($notes !== '') {
        $lines[] = '';
        $lines[] = 'Observações RH: ' . $notes;
    }

    if ($sentBy) {
        $lines[] = '';
        $lines[] = 'Enviado por: ' . $sentBy;
    }

    $pdfContent = taskforce_generate_basic_pdf($lines);
    $safeName = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string) ($employee['name'] ?? 'colaborador')));
    $fileName = 'avaliacao-' . trim((string) $safeName, '-') . '-' . $year . '-' . (string) ($evaluation['award_period'] ?? 'periodo') . '.pdf';

    $subject = '[TaskForce RH] Avaliação ' . $periodLabel . ' - ' . (string) ($employee['name'] ?? 'Colaborador');
    $body = "Olá " . (string) ($employee['name'] ?? '') . ",\n\nSegue em anexo o PDF da sua avaliação (" . $periodLabel . ' de ' . $year . ").\n\nCumprimentos,\nEquipa RH";

    $sent = deliver_report($recipientEmail, $subject, $body, null, [[
        'name' => $fileName,
        'mime' => 'application/pdf',
        'content' => $pdfContent,
    ]]);

    return $sent
        ? ['ok' => true, 'message' => 'PDF enviado por email para o colaborador avaliado.']
        : ['ok' => false, 'message' => 'Não foi possível enviar o email com o PDF da avaliação.'];
}

function taskforce_send_evaluation_history_pdf(PDO $pdo, array $employee, int $year, array $evaluations, array $metrics, string $predominantRule, ?array $closure, ?string $sentBy = null): array
{
    $recipientEmail = trim((string) ($employee['email'] ?? ''));
    if ($recipientEmail === '') {
        return ['ok' => false, 'message' => 'O colaborador avaliado não tem email configurado.'];
    }

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

    $html = '<!doctype html><html lang="pt"><head><meta charset="utf-8"><style>'
        . 'body{font-family:Arial,sans-serif;color:#1f2937;font-size:12px;padding:18px;}'
        . 'h1{font-size:24px;margin:0 0 12px;} h2{font-size:18px;margin:0 0 10px;}'
        . '.card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:12px;}'
        . '.grid{width:100%;border-collapse:separate;border-spacing:8px;} .grid td{border:1px solid #e5e7eb;border-radius:10px;padding:10px;vertical-align:top;}'
        . '.label{display:block;color:#6b7280;font-size:11px;margin-bottom:2px;} .value{font-size:24px;font-weight:700;}'
        . 'table{width:100%;border-collapse:collapse;} th,td{border-bottom:1px solid #e5e7eb;padding:7px;text-align:left;font-size:11px;}'
        . 'th{font-size:12px;} .muted{color:#6b7280;}'
        . '</style></head><body>'
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
        . '</body></html>';

    $pdfContent = taskforce_generate_pdf_from_html($html);
    if (!is_string($pdfContent) || $pdfContent === '') {
        $pdfContent = taskforce_generate_evaluation_history_layout_pdf([
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
                'Colaborador: ' . $employeeLabel,
                'Ano: ' . $year,
                'Nº avaliações: ' . (int) ($metrics['count'] ?? 0),
                'Soma prémios período: ' . taskforce_money((float) ($metrics['sum_period_total'] ?? 0)),
                'Regra predominante: ' . $predominantRule,
            ],
        ]);
    }

    $safeName = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string) ($employee['name'] ?? 'colaborador')));
    $fileName = 'historico-avaliacoes-' . trim((string) $safeName, '-') . '-' . $year . '.pdf';
    $subject = '[TaskForce RH] Histórico de avaliações ' . $year . ' - ' . (string) ($employee['name'] ?? 'Colaborador');
    $body = "Olá " . (string) ($employee['name'] ?? '') . ",\n\nSegue em anexo o PDF do seu histórico de avaliações de " . $year . ".\n\nCumprimentos,\nEquipa RH";

    $sent = deliver_report($recipientEmail, $subject, $body, null, [[
        'name' => $fileName,
        'mime' => 'application/pdf',
        'content' => $pdfContent,
    ]]);

    return $sent
        ? ['ok' => true, 'message' => 'PDF do histórico enviado por email para o colaborador avaliado.']
        : ['ok' => false, 'message' => 'Não foi possível enviar o email com o PDF do histórico.'];
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
