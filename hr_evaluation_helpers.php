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
        'SELECT u.id, u.user_number, u.name, u.department_id, u.award_profile, u.award_eligible,
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
