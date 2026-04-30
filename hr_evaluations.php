<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hr_evaluation_helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}
$currentUser = current_user($pdo);
$canDeleteEvaluation = $currentUser && (((int) ($currentUser['is_admin'] ?? 0) === 1) || ((string) ($currentUser['access_profile'] ?? '') === 'RH'));
$currentUserName = trim((string) ($currentUser['name'] ?? ''));

$flashSuccess = null;
$flashError = null;
$profiles = taskforce_evaluation_profiles();
$periods = taskforce_evaluation_periods();
$currentYear = (int) date('Y');

$employees = $pdo->query('SELECT u.id, u.user_number, u.name, u.department_id, u.award_profile, d.name AS department_name FROM users u LEFT JOIN hr_departments d ON d.id = u.department_id WHERE u.is_active = 1 AND u.pin_only_login = 0 ORDER BY u.name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query('SELECT id, name FROM hr_departments ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$employeeMap = [];
foreach ($employees as $emp) { $employeeMap[(int) $emp['id']] = $emp; }

$formData = [
    'evaluation_id' => 0,
    'user_id' => 0,
    'award_year' => $currentYear,
    'award_period' => 'jan_abr',
    'interview_date' => '',
    'award_profile' => 'operador',
    'performance_score' => 0,
    'performance_notes' => '',
    'behavior_score' => 0,
    'behavior_notes' => '',
    'punctuality_count' => 0,
    'punctuality_notes' => '',
    'absence_count' => 0,
    'absence_notes' => '',
    'general_notes' => '',
    'final_absence_count' => 0,
    'final_notes' => '',
];

if (isset($_GET['edit_id'])) {
    $editId = (int) $_GET['edit_id'];
    if ($editId > 0) {
        $editStmt = $pdo->prepare('SELECT * FROM hr_evaluations WHERE id = ? LIMIT 1');
        $editStmt->execute([$editId]);
        $edit = $editStmt->fetch(PDO::FETCH_ASSOC);
        if ($edit) {
            $formData = array_merge($formData, [
                'evaluation_id' => (int) $edit['id'], 'user_id' => (int) $edit['user_id'], 'award_year' => (int) $edit['award_year'],
                'award_period' => (string) $edit['award_period'], 'interview_date' => (string) ($edit['interview_date'] ?? ''), 'award_profile' => (string) $edit['award_profile'],
                'performance_score' => (int) $edit['performance_score'], 'performance_notes' => (string) ($edit['performance_notes'] ?? ''),
                'behavior_score' => (int) $edit['behavior_score'], 'behavior_notes' => (string) ($edit['behavior_notes'] ?? ''),
                'punctuality_count' => (int) $edit['punctuality_count'], 'punctuality_notes' => (string) ($edit['punctuality_notes'] ?? ''),
                'absence_count' => (int) $edit['absence_count'], 'absence_notes' => (string) ($edit['absence_notes'] ?? ''),
                'general_notes' => (string) ($edit['general_notes'] ?? ''),
            ]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_evaluation') {
        $sendAfterSave = isset($_POST['save_and_send']);
        $savedEvaluationId = 0;
        $formData = array_merge($formData, [
            'evaluation_id' => (int) ($_POST['evaluation_id'] ?? 0), 'user_id' => (int) ($_POST['user_id'] ?? 0), 'award_year' => (int) ($_POST['award_year'] ?? $currentYear),
            'award_period' => (string) ($_POST['award_period'] ?? ''), 'interview_date' => trim((string) ($_POST['interview_date'] ?? '')), 'award_profile' => trim((string) ($_POST['award_profile'] ?? '')),
            'performance_score' => (int) ($_POST['performance_score'] ?? 0), 'performance_notes' => trim((string) ($_POST['performance_notes'] ?? '')),
            'behavior_score' => (int) ($_POST['behavior_score'] ?? 0), 'behavior_notes' => trim((string) ($_POST['behavior_notes'] ?? '')),
            'punctuality_count' => (int) ($_POST['punctuality_count'] ?? 0), 'punctuality_notes' => trim((string) ($_POST['punctuality_notes'] ?? '')),
            'absence_count' => (int) ($_POST['absence_count'] ?? 0), 'absence_notes' => trim((string) ($_POST['absence_notes'] ?? '')),
            'general_notes' => trim((string) ($_POST['general_notes'] ?? '')),
        ]);

        $errors = [];
        if (!isset($employeeMap[$formData['user_id']])) { $errors[] = 'Colaborador inválido.'; }
        if ($formData['award_year'] < 2024 || $formData['award_year'] > 2100) { $errors[] = 'Ano inválido.'; }
        if (!array_key_exists($formData['award_period'], $periods)) { $errors[] = 'Período inválido.'; }
        if ($formData['interview_date'] === '') {
            $errors[] = 'Data da avaliação é obrigatória.';
        } elseif (strtotime($formData['interview_date']) === false) {
            $errors[] = 'Data da avaliação inválida.';
        }
        if ($formData['performance_score'] < 0 || $formData['performance_score'] > 3) { $errors[] = 'Score performance inválido.'; }
        if ($formData['behavior_score'] < 0 || $formData['behavior_score'] > 3) { $errors[] = 'Score comportamento inválido.'; }
        if ($formData['punctuality_count'] < 0 || $formData['absence_count'] < 0) { $errors[] = 'Pontualidade/Absentismo inválidos.'; }

        if (!$errors) {
            $employee = taskforce_fetch_evaluation_employee($pdo, (int) $formData['user_id']);
            if (!$employee) {
                $errors[] = 'Colaborador não encontrado.';
            } else {
                $profileKey = array_key_exists($formData['award_profile'], $profiles)
                    ? $formData['award_profile']
                    : ((string) ($employee['award_profile'] ?? 'operador'));
                if (!array_key_exists($profileKey, $profiles)) {
                    $profileKey = 'operador';
                }
                $rule = taskforce_resolve_evaluation_rule($pdo, (int) $formData['award_year'], $profileKey, isset($employee['department_id']) ? (int) $employee['department_id'] : null);
                $periodValues = taskforce_calculate_period_values($rule['config'], $formData);

                try {
                    if ((int) $formData['evaluation_id'] > 0) {
                        $stmt = $pdo->prepare('UPDATE hr_evaluations SET user_id = ?, user_number = ?, user_name = ?, department_id = ?, department_name = ?, award_profile = ?, rule_set_id = ?, rule_set_name = ?, award_year = ?, award_period = ?, interview_date = ?, performance_score = ?, performance_value = ?, performance_notes = ?, behavior_score = ?, behavior_value = ?, behavior_notes = ?, punctuality_count = ?, punctuality_value = ?, punctuality_notes = ?, absence_count = ?, absence_value = ?, absence_notes = ?, period_total = ?, max_period_total = ?, period_gap = ?, general_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                        $stmt->execute([
                            $employee['id'], $employee['user_number'], $employee['name'], $employee['department_id'], $employee['department_name'], $profileKey,
                            $rule['id'] ?: null, $rule['name'], $formData['award_year'], $formData['award_period'], $formData['interview_date'] ?: null,
                            $formData['performance_score'], $periodValues['performance_value'], $formData['performance_notes'],
                            $formData['behavior_score'], $periodValues['behavior_value'], $formData['behavior_notes'],
                            $formData['punctuality_count'], $periodValues['punctuality_value'], $formData['punctuality_notes'],
                            $formData['absence_count'], $periodValues['absence_value'], $formData['absence_notes'],
                            $periodValues['period_total'], $periodValues['max_period_total'], $periodValues['period_gap'], $formData['general_notes'] ?: null,
                            $formData['evaluation_id'],
                        ]);
                        $savedEvaluationId = (int) $formData['evaluation_id'];
                        $flashSuccess = 'Avaliação atualizada com sucesso.';
                    } else {
                        $dupStmt = $pdo->prepare('SELECT id FROM hr_evaluations WHERE user_id = ? AND award_year = ? AND award_period = ? LIMIT 1');
                        $dupStmt->execute([$employee['id'], $formData['award_year'], $formData['award_period']]);
                        if ($dupStmt->fetchColumn()) {
                            $errors[] = 'Já existe avaliação deste colaborador para o mesmo ano/período.';
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO hr_evaluations (user_id, user_number, user_name, department_id, department_name, award_profile, rule_set_id, rule_set_name, award_year, award_period, interview_date, performance_score, performance_value, performance_notes, behavior_score, behavior_value, behavior_notes, punctuality_count, punctuality_value, punctuality_notes, absence_count, absence_value, absence_notes, period_total, max_period_total, period_gap, general_notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            $stmt->execute([
                                $employee['id'], $employee['user_number'], $employee['name'], $employee['department_id'], $employee['department_name'], $profileKey,
                                $rule['id'] ?: null, $rule['name'], $formData['award_year'], $formData['award_period'], $formData['interview_date'] ?: null,
                                $formData['performance_score'], $periodValues['performance_value'], $formData['performance_notes'],
                                $formData['behavior_score'], $periodValues['behavior_value'], $formData['behavior_notes'],
                                $formData['punctuality_count'], $periodValues['punctuality_value'], $formData['punctuality_notes'],
                                $formData['absence_count'], $periodValues['absence_value'], $formData['absence_notes'],
                                $periodValues['period_total'], $periodValues['max_period_total'], $periodValues['period_gap'], $formData['general_notes'] ?: null,
                                $userId,
                            ]);
                            $savedEvaluationId = (int) $pdo->lastInsertId();
                            $flashSuccess = 'Avaliação criada com sucesso.';
                            $formData['evaluation_id'] = 0;
                        }
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'Não foi possível gravar a avaliação.';
                }
            }
        }
        if (!$errors && $sendAfterSave && $savedEvaluationId > 0) {
            $evaluationStmt = $pdo->prepare('SELECT * FROM hr_evaluations WHERE id = ? LIMIT 1');
            $evaluationStmt->execute([$savedEvaluationId]);
            $savedEvaluation = $evaluationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $employee = $savedEvaluation ? taskforce_fetch_evaluation_employee($pdo, (int) ($savedEvaluation['user_id'] ?? 0)) : null;

            if (!$savedEvaluation || !$employee) {
                $flashError = 'Avaliação guardada, mas não foi possível preparar o envio do PDF.';
            } else {
                $closureStmt = $pdo->prepare('SELECT * FROM hr_evaluation_year_closures WHERE user_id = ? AND award_year = ? LIMIT 1');
                $closureStmt->execute([(int) $savedEvaluation['user_id'], (int) $savedEvaluation['award_year']]);
                $closure = $closureStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $sendResult = taskforce_send_evaluation_pdf(
                    $pdo,
                    $employee,
                    $savedEvaluation,
                    $closure,
                    (int) $savedEvaluation['award_year'],
                    taskforce_evaluation_period_label((string) $savedEvaluation['award_period']),
                    $currentUserName !== '' ? $currentUserName : null
                );

                if ($sendResult['ok']) {
                    $flashSuccess = 'Avaliação guardada e enviada por email com sucesso.';
                } else {
                    $flashError = 'Avaliação guardada, mas o envio por email falhou: ' . (string) $sendResult['message'];
                }
            }
        }
        if ($errors) { $flashError = implode(' ', $errors); }
    }

    if ($action === 'save_year_closure') {
        $closureUserId = (int) ($_POST['closure_user_id'] ?? 0);
        $closureYear = (int) ($_POST['closure_award_year'] ?? $currentYear);
        $finalAbsenceCount = (int) ($_POST['final_absence_count'] ?? 0);
        $finalNotes = trim((string) ($_POST['final_notes'] ?? ''));

        $employee = taskforce_fetch_evaluation_employee($pdo, $closureUserId);
        if (!$employee || $closureYear < 2024 || $closureYear > 2100 || $finalAbsenceCount < 0) {
            $flashError = 'Dados inválidos para fecho anual.';
        } else {
            $profileKey = (string) ($employee['award_profile'] ?? 'operador');
            if (!array_key_exists($profileKey, $profiles)) { $profileKey = 'operador'; }
            $rule = taskforce_resolve_evaluation_rule($pdo, $closureYear, $profileKey, isset($employee['department_id']) ? (int) $employee['department_id'] : null);
            $yearSummary = taskforce_calculate_year_summary($pdo, $closureUserId, $closureYear, $rule['config'], $finalAbsenceCount);

            $existsStmt = $pdo->prepare('SELECT id FROM hr_evaluation_year_closures WHERE user_id = ? AND award_year = ? LIMIT 1');
            $existsStmt->execute([$closureUserId, $closureYear]);
            $existingClosureId = (int) $existsStmt->fetchColumn();

            if ($existingClosureId > 0) {
                $stmt = $pdo->prepare('UPDATE hr_evaluation_year_closures SET user_number = ?, user_name = ?, department_id = ?, department_name = ?, award_profile = ?, rule_set_id = ?, rule_set_name = ?, final_absence_count = ?, final_bonus_value = ?, year_periods_total = ?, year_total_with_bonus = ?, max_year_total = ?, year_gap = ?, final_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([
                    $employee['user_number'], $employee['name'], $employee['department_id'], $employee['department_name'], $profileKey, $rule['id'] ?: null, $rule['name'],
                    $finalAbsenceCount, $yearSummary['final_bonus_value'], $yearSummary['year_periods_total'], $yearSummary['year_total_with_bonus'],
                    $yearSummary['max_year_total'], $yearSummary['year_gap'], $finalNotes ?: null, $existingClosureId,
                ]);
                $flashSuccess = 'Fecho anual atualizado.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO hr_evaluation_year_closures (user_id, user_number, user_name, department_id, department_name, award_profile, rule_set_id, rule_set_name, award_year, final_absence_count, final_bonus_value, year_periods_total, year_total_with_bonus, max_year_total, year_gap, final_notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $closureUserId, $employee['user_number'], $employee['name'], $employee['department_id'], $employee['department_name'], $profileKey, $rule['id'] ?: null, $rule['name'],
                    $closureYear, $finalAbsenceCount, $yearSummary['final_bonus_value'], $yearSummary['year_periods_total'], $yearSummary['year_total_with_bonus'],
                    $yearSummary['max_year_total'], $yearSummary['year_gap'], $finalNotes ?: null, $userId,
                ]);
                $flashSuccess = 'Fecho anual registado.';
            }
        }
    }

    if ($action === 'delete_evaluation') {
        $deleteId = (int) ($_POST['evaluation_id'] ?? 0);
        if (!$canDeleteEvaluation) {
            $flashError = 'Sem permissão para eliminar avaliações.';
        } elseif ($deleteId <= 0) {
            $flashError = 'Avaliação inválida para eliminação.';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM hr_evaluations WHERE id = ?');
            $deleteStmt->execute([$deleteId]);
            $flashSuccess = 'Avaliação eliminada com sucesso.';
            if ((int) $formData['evaluation_id'] === $deleteId) {
                $formData['evaluation_id'] = 0;
            }
        }
    }

    if ($action === 'send_evaluation_pdf') {
        $evaluationId = (int) ($_POST['evaluation_id'] ?? 0);
        if ($evaluationId <= 0) {
            $flashError = 'Avaliação inválida para envio de PDF.';
        } else {
            $evaluationStmt = $pdo->prepare('SELECT * FROM hr_evaluations WHERE id = ? LIMIT 1');
            $evaluationStmt->execute([$evaluationId]);
            $evaluation = $evaluationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $employee = $evaluation ? taskforce_fetch_evaluation_employee($pdo, (int) ($evaluation['user_id'] ?? 0)) : null;
            if (!$evaluation || !$employee) {
                $flashError = 'Não foi possível encontrar a avaliação para envio.';
            } else {
                $closureStmt = $pdo->prepare('SELECT * FROM hr_evaluation_year_closures WHERE user_id = ? AND award_year = ? LIMIT 1');
                $closureStmt->execute([(int) $evaluation['user_id'], (int) $evaluation['award_year']]);
                $closure = $closureStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $sendResult = taskforce_send_evaluation_pdf(
                    $pdo,
                    $employee,
                    $evaluation,
                    $closure,
                    (int) $evaluation['award_year'],
                    taskforce_evaluation_period_label((string) $evaluation['award_period']),
                    $currentUserName !== '' ? $currentUserName : null
                );
                if ($sendResult['ok']) {
                    $flashSuccess = $sendResult['message'];
                } else {
                    $flashError = $sendResult['message'];
                }
            }
        }
    }
}

$filterYear = (int) ($_GET['year'] ?? $currentYear);
$filterPeriod = trim((string) ($_GET['period'] ?? ''));
$filterDepartment = (int) ($_GET['department_id'] ?? 0);
$filterProfile = trim((string) ($_GET['profile'] ?? ''));
$filterSearch = trim((string) ($_GET['search'] ?? ''));

$sql = 'SELECT e.*, yt.year_periods_total, COALESCE(c.final_bonus_value, 0) AS final_bonus_value, (COALESCE(yt.year_periods_total, 0) + COALESCE(c.final_bonus_value, 0)) AS year_total_with_bonus FROM hr_evaluations e LEFT JOIN (SELECT user_id, award_year, SUM(period_total) AS year_periods_total FROM hr_evaluations GROUP BY user_id, award_year) yt ON yt.user_id = e.user_id AND yt.award_year = e.award_year LEFT JOIN hr_evaluation_year_closures c ON c.user_id = e.user_id AND c.award_year = e.award_year WHERE e.award_year = :year';
$params = [':year' => $filterYear];
if ($filterPeriod !== '' && array_key_exists($filterPeriod, $periods)) { $sql .= ' AND e.award_period = :period'; $params[':period'] = $filterPeriod; }
if ($filterDepartment > 0) { $sql .= ' AND e.department_id = :dep'; $params[':dep'] = $filterDepartment; }
if ($filterProfile !== '' && array_key_exists($filterProfile, $profiles)) { $sql .= ' AND e.award_profile = :profile'; $params[':profile'] = $filterProfile; }
if ($filterSearch !== '') { $sql .= ' AND (LOWER(e.user_name) LIKE :search OR LOWER(COALESCE(e.user_number, "")) LIKE :search)'; $params[':search'] = '%' . mb_strtolower($filterSearch) . '%'; }
$sql .= ' ORDER BY e.user_name COLLATE NOCASE ASC, e.award_year DESC';
$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
usort($rows, static fn(array $a, array $b): int => taskforce_evaluation_period_sort((string) $a['award_period']) <=> taskforce_evaluation_period_sort((string) $b['award_period']));

$closureFormData = [
    'final_absence_count' => max(0, (int) ($formData['final_absence_count'] ?? 0)),
    'final_notes' => (string) ($formData['final_notes'] ?? ''),
];
if ((int) ($formData['user_id'] ?? 0) > 0 && (int) ($formData['award_year'] ?? 0) >= 2024) {
    $prefillClosureStmt = $pdo->prepare('SELECT final_absence_count, final_notes FROM hr_evaluation_year_closures WHERE user_id = ? AND award_year = ? LIMIT 1');
    $prefillClosureStmt->execute([(int) $formData['user_id'], (int) $formData['award_year']]);
    $prefillClosure = $prefillClosureStmt->fetch(PDO::FETCH_ASSOC);
    if ($prefillClosure) {
        $closureFormData = [
            'final_absence_count' => max(0, (int) ($prefillClosure['final_absence_count'] ?? 0)),
            'final_notes' => (string) ($prefillClosure['final_notes'] ?? ''),
        ];
    }
}

$pageTitle = 'Avaliações';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Avaliações</h1>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
<style>
    .evaluation-compact {
        font-size: .92rem;
    }

    .evaluation-compact .form-label,
    .evaluation-compact .btn,
    .evaluation-compact .table {
        font-size: .88rem;
    }

    .evaluation-compact .form-control,
    .evaluation-compact .form-select {
        min-height: calc(1.5em + .45rem + 2px);
        padding: .2rem .45rem;
    }

    .evaluation-compact .soft-card h2,
    .evaluation-compact .soft-card h3 {
        font-size: 1.05rem;
    }
</style>

<div class="soft-card p-3 mb-3 evaluation-compact">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Ano</label><input type="number" class="form-control" name="year" min="2024" max="2100" value="<?= (int) $filterYear ?>"></div>
        <div class="col-md-2"><label class="form-label">Período</label><select class="form-select" name="period"><option value="">Todos</option><?php foreach ($periods as $k => $l): ?><option value="<?= h($k) ?>" <?= $filterPeriod === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Departamento</label><select class="form-select" name="department_id"><option value="0">Todos</option><?php foreach ($departments as $dep): ?><option value="<?= (int) $dep['id'] ?>" <?= $filterDepartment === (int) $dep['id'] ? 'selected' : '' ?>><?= h($dep['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Perfil</label><select class="form-select" name="profile"><option value="">Todos</option><?php foreach ($profiles as $k => $l): ?><option value="<?= h($k) ?>" <?= $filterProfile === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Pesquisa</label><input class="form-control" name="search" value="<?= h($filterSearch) ?>" placeholder="Nº ou nome"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-dark">Filtrar</button></div>
    </form>
</div>

<div class="table-responsive soft-card p-3 mb-4 evaluation-compact">
<table class="table table-sm align-middle">
<thead><tr><th>Nº</th><th>Nome</th><th>Departamento</th><th>Perfil</th><th>Ano</th><th>Período</th><th>Total período</th><th>Acumulado ano</th><th>Bónus</th><th>Total anual</th><th>Regra</th><th>Ações</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="12" class="text-muted">Sem avaliações para os filtros selecionados.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h((string) ($row['user_number'] ?: $row['user_id'])) ?></td><td><?= h($row['user_name']) ?></td><td><?= h((string) ($row['department_name'] ?: '—')) ?></td>
<td><span class="badge text-bg-light border"><?= h($profiles[$row['award_profile']] ?? $row['award_profile']) ?></span></td>
<td><?= (int) $row['award_year'] ?></td><td><?= h(taskforce_evaluation_period_label((string) $row['award_period'])) ?></td>
<td><?= h(taskforce_money((float) $row['period_total'])) ?></td><td><?= h(taskforce_money((float) ($row['year_periods_total'] ?? 0))) ?></td>
<td><?= h(taskforce_money((float) ($row['final_bonus_value'] ?? 0))) ?></td><td><?= h(taskforce_money((float) ($row['year_total_with_bonus'] ?? 0))) ?></td>
<td><?= h((string) ($row['rule_set_name'] ?: 'Default helper')) ?></td>
<td>
    <div class="d-flex gap-1">
        <a class="btn btn-sm btn-outline-secondary" href="hr_evaluations.php?edit_id=<?= (int) $row['id'] ?>">Editar</a>
        <a class="btn btn-sm btn-outline-dark" href="hr_evaluation_history.php?user_id=<?= (int) $row['user_id'] ?>&year=<?= (int) $row['award_year'] ?>">Histórico</a>
        <form method="post">
            <input type="hidden" name="action" value="send_evaluation_pdf">
            <input type="hidden" name="evaluation_id" value="<?= (int) $row['id'] ?>">
            <button class="btn btn-sm btn-outline-primary" type="submit">Enviar PDF</button>
        </form>
        <?php if ($canDeleteEvaluation): ?>
            <form method="post" onsubmit="return confirm('Eliminar esta avaliação? Esta ação não pode ser anulada.');">
                <input type="hidden" name="action" value="delete_evaluation">
                <input type="hidden" name="evaluation_id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
            </form>
        <?php endif; ?>
    </div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="row g-3 evaluation-compact">
<div class="col-lg-8">
<div class="soft-card p-3">
<h2 class="h5 mb-3"><?= (int) $formData['evaluation_id'] > 0 ? 'Editar avaliação' : 'Nova avaliação' ?></h2>
<form method="post" id="evaluationForm" class="row g-2">
<input type="hidden" name="action" value="save_evaluation">
<input type="hidden" name="evaluation_id" id="evaluation_id" value="<?= (int) $formData['evaluation_id'] ?>">
<div class="col-md-4">
    <label class="form-label">Colaborador</label>
    <input type="search" class="form-control form-control-sm js-user-picker" id="user_id_search" list="user_id_options" placeholder="Pesquisar nº ou nome" autocomplete="off" required>
    <datalist id="user_id_options"></datalist>
    <select class="form-select form-select-sm js-calc-field d-none" name="user_id" id="user_id" required>
        <option value="0">Selecione</option>
        <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= (int) $formData['user_id'] === (int) $emp['id'] ? 'selected' : '' ?> data-number="<?= h((string) ($emp['user_number'] ?? '')) ?>" data-name="<?= h((string) ($emp['name'] ?? '')) ?>" data-department="<?= h((string) ($emp['department_name'] ?? '')) ?>" data-profile="<?= h((string) ($emp['award_profile'] ?? 'operador')) ?>"><?= h(format_user_picker_label($emp)) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-md-2"><label class="form-label">Ano</label><input type="number" class="form-control form-control-sm js-calc-field" name="award_year" id="award_year" min="2024" max="2100" value="<?= (int) $formData['award_year'] ?>" required></div>
<div class="col-md-3"><label class="form-label">Período</label><select class="form-select form-select-sm js-calc-field" name="award_period" id="award_period" required><?php foreach ($periods as $k => $l): ?><option value="<?= h($k) ?>" <?= $formData['award_period'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Data avaliação</label><input type="date" class="form-control form-control-sm" name="interview_date" value="<?= h($formData['interview_date']) ?>" required></div>

<div class="col-md-3"><label class="form-label">Nº colaborador</label><input class="form-control form-control-sm" id="user_number_readonly" readonly></div>
<div class="col-md-4"><label class="form-label">Nome</label><input class="form-control form-control-sm" id="user_name_readonly" readonly></div>
<div class="col-md-3"><label class="form-label">Departamento</label><input class="form-control form-control-sm" id="department_readonly" readonly></div>
<div class="col-md-2"><label class="form-label">Perfil</label><select class="form-select form-select-sm js-calc-field" name="award_profile" id="award_profile"><?php foreach ($profiles as $k => $l): ?><option value="<?= h($k) ?>" <?= $formData['award_profile'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>

<div class="col-md-3"><label class="form-label">Performance</label><select class="form-select form-select-sm js-calc-field" name="performance_score" id="performance_score"><?php for ($i = 0; $i <= 3; $i++): ?><option value="<?= $i ?>" <?= (int) $formData['performance_score'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
<div class="col-md-9"><label class="form-label">Notas performance</label><input class="form-control form-control-sm" name="performance_notes" value="<?= h($formData['performance_notes']) ?>"></div>

<div class="col-md-3"><label class="form-label">Comportamento</label><select class="form-select form-select-sm js-calc-field" name="behavior_score" id="behavior_score"><?php for ($i = 0; $i <= 3; $i++): ?><option value="<?= $i ?>" <?= (int) $formData['behavior_score'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
<div class="col-md-9"><label class="form-label">Notas comportamento</label><input class="form-control form-control-sm" name="behavior_notes" value="<?= h($formData['behavior_notes']) ?>"></div>

<div class="col-md-3"><label class="form-label">Pontualidade (ocorr.)</label><input type="number" min="0" class="form-control form-control-sm js-calc-field" name="punctuality_count" id="punctuality_count" value="<?= (int) $formData['punctuality_count'] ?>"></div>
<div class="col-md-9"><label class="form-label">Notas pontualidade</label><input class="form-control form-control-sm" name="punctuality_notes" value="<?= h($formData['punctuality_notes']) ?>"></div>

<div class="col-md-3"><label class="form-label">Absentismo (ocorr.)</label><input type="number" min="0" class="form-control form-control-sm js-calc-field" name="absence_count" id="absence_count" value="<?= (int) $formData['absence_count'] ?>"></div>
<div class="col-md-9"><label class="form-label">Notas absentismo</label><input class="form-control form-control-sm" name="absence_notes" value="<?= h($formData['absence_notes']) ?>"></div>

<div class="col-12"><label class="form-label">Notas gerais</label><textarea class="form-control form-control-sm" name="general_notes" rows="2"><?= h($formData['general_notes']) ?></textarea></div>
<div class="col-12 d-flex gap-2 flex-wrap">
    <button class="btn btn-dark">Guardar avaliação</button>
    <button class="btn btn-outline-primary" name="save_and_send" value="1">Guardar e enviar avaliação</button>
    <a class="btn btn-outline-secondary" href="hr_evaluations.php">Limpar</a>
</div>
</form>
</div>
</div>
<div class="col-lg-4">
<div class="soft-card p-3 mb-3" id="previewCard">
<div class="d-flex justify-content-between align-items-center"><h2 class="h6 mb-0">Resumo do prémio</h2><span id="previewSpinner" class="spinner-border spinner-border-sm d-none"></span></div>
<div id="previewError" class="small text-danger mt-2 d-none">Não foi possível atualizar o preview.</div>
<ul class="list-unstyled small mt-3 mb-0" id="previewValues">
<li>Performance: <strong data-field="performance_value">0,00 €</strong></li>
<li>Comportamento: <strong data-field="behavior_value">0,00 €</strong></li>
<li>Pontualidade: <strong data-field="punctuality_value">0,00 €</strong></li>
<li>Absentismo: <strong data-field="absence_value">0,00 €</strong></li>
<li class="mt-2">Total período: <strong data-field="period_total">0,00 €</strong></li>
<li>Máximo período: <strong data-field="max_period_total">0,00 €</strong></li>
<li>Diferença período: <strong data-field="period_gap">0,00 €</strong></li>
<li class="mt-2">Acumulado ano: <strong data-field="year_periods_total">0,00 €</strong></li>
<li>Bónus final: <strong data-field="final_bonus_value">0,00 €</strong></li>
<li>Total anual: <strong data-field="year_total_with_bonus">0,00 €</strong></li>
<li>Máximo anual: <strong data-field="max_year_total">0,00 €</strong></li>
<li>Diferença anual: <strong data-field="year_gap">0,00 €</strong></li>
<li class="mt-2">Regra: <strong data-field="rule_name">—</strong></li>
</ul>
</div>

<div class="soft-card p-3">
<h2 class="h6">Fecho anual</h2>
<form method="post" class="row g-2" id="closureForm">
<input type="hidden" name="action" value="save_year_closure">
<div class="col-12">
    <label class="form-label">Colaborador</label>
    <input type="search" class="form-control form-control-sm js-user-picker" id="closure_user_id_search" list="closure_user_id_options" placeholder="Pesquisar nº ou nome" autocomplete="off">
    <datalist id="closure_user_id_options"></datalist>
    <select class="form-select form-select-sm js-calc-field d-none" name="closure_user_id" id="closure_user_id"><?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>" <?= (int) $formData['user_id'] === (int) $emp['id'] ? 'selected' : '' ?>><?= h(format_user_picker_label($emp)) ?></option><?php endforeach; ?></select>
</div>
<div class="col-6"><label class="form-label">Ano</label><input class="form-control form-control-sm js-calc-field" type="number" name="closure_award_year" id="closure_award_year" min="2024" max="2100" value="<?= (int) $formData['award_year'] ?>"></div>
<div class="col-6"><label class="form-label">Absentismo final</label><input class="form-control form-control-sm js-calc-field" type="number" min="0" name="final_absence_count" id="final_absence_count" value="<?= (int) $closureFormData['final_absence_count'] ?>"></div>
<div class="col-12"><label class="form-label">Notas RH</label><textarea class="form-control form-control-sm" name="final_notes" rows="2"><?= h($closureFormData['final_notes']) ?></textarea></div>
<div class="col-12 d-grid"><button class="btn btn-outline-dark">Guardar fecho anual</button></div>
</form>
</div>
</div>
</div>

<script>
(() => {
    const form = document.getElementById('evaluationForm');
    if (!form) return;
    const closureUser = document.getElementById('closure_user_id');
    const closureYear = document.getElementById('closure_award_year');
    const fmt = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const spinner = document.getElementById('previewSpinner');
    const errorNode = document.getElementById('previewError');

    function fillReadonly() {
        const selected = document.querySelector('#user_id option:checked');
        document.getElementById('user_number_readonly').value = selected ? (selected.dataset.number || '') : '';
        document.getElementById('user_name_readonly').value = selected ? (selected.dataset.name || '') : '';
        document.getElementById('department_readonly').value = selected ? (selected.dataset.department || '') : '';
        const profile = document.getElementById('award_profile');
        const isEditing = Number(document.getElementById('evaluation_id').value || 0) > 0;
        if (selected && selected.dataset.profile && profile && !profile.dataset.touched && !isEditing) {
            profile.value = selected.dataset.profile;
        }
    }

    function setMoney(field, value) {
        const node = document.querySelector('[data-field="' + field + '"]');
        if (node) node.textContent = fmt.format(Number(value || 0)) + ' €';
    }

    function applySuggestedCounts(suggested) {
        if (!suggested || typeof suggested !== 'object') return;
        const punctualityInput = document.getElementById('punctuality_count');
        const absenceInput = document.getElementById('absence_count');
        const pairs = [
            [punctualityInput, Number(suggested.punctuality_count || 0)],
            [absenceInput, Number(suggested.absence_count || 0)],
        ];

        pairs.forEach(([input, value]) => {
            if (!input || input.dataset.manual === '1') return;
            input.value = String(Math.max(0, Math.trunc(value)));
        });
    }

    async function calculatePreview() {
        const payload = {
            user_id: Number(document.getElementById('user_id').value || 0),
            award_year: Number(document.getElementById('award_year').value || 0),
            award_period: document.getElementById('award_period').value,
            award_profile: document.getElementById('award_profile').value,
            performance_score: Number(document.getElementById('performance_score').value || 0),
            behavior_score: Number(document.getElementById('behavior_score').value || 0),
            punctuality_count: Number(document.getElementById('punctuality_count').value || 0),
            absence_count: Number(document.getElementById('absence_count').value || 0),
            final_absence_count: Number(document.getElementById('final_absence_count').value || 0),
            evaluation_id: Number(document.getElementById('evaluation_id').value || 0),
        };
        if (closureUser) closureUser.value = String(payload.user_id || 0);
        if (closureYear) closureYear.value = String(payload.award_year || 0);
        if (!payload.user_id) return;

        spinner.classList.remove('d-none');
        errorNode.classList.add('d-none');

        try {
            const response = await fetch('hr_evaluation_calculate.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error('preview_error');
            applySuggestedCounts(data.suggested_counts || null);
            const values = data.values || {};
            ['performance_value','behavior_value','punctuality_value','absence_value','period_total','max_period_total','period_gap','year_periods_total','final_bonus_value','year_total_with_bonus','max_year_total','year_gap'].forEach((key) => setMoney(key, values[key]));
            const ruleNode = document.querySelector('[data-field="rule_name"]');
            if (ruleNode) ruleNode.textContent = data.rule && data.rule.name ? data.rule.name : '—';
        } catch (e) {
            errorNode.classList.remove('d-none');
        } finally {
            spinner.classList.add('d-none');
        }
    }

    let timer = null;
    function debounceCalc() {
        clearTimeout(timer);
        timer = setTimeout(calculatePreview, 250);
    }

    document.querySelectorAll('.js-calc-field').forEach((field) => {
        field.addEventListener('input', debounceCalc);
        field.addEventListener('change', debounceCalc);
    });

    ['punctuality_count', 'absence_count'].forEach((id) => {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('input', () => { input.dataset.manual = '1'; });
    });

    ['user_id', 'award_year', 'award_period'].forEach((id) => {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('change', () => {
            const punctualityInput = document.getElementById('punctuality_count');
            const absenceInput = document.getElementById('absence_count');
            if (punctualityInput) delete punctualityInput.dataset.manual;
            if (absenceInput) delete absenceInput.dataset.manual;
        });
    });

    const profile = document.getElementById('award_profile');
    if (profile) {
        profile.addEventListener('change', () => profile.dataset.touched = '1');
    }

    function attachUserPicker(inputId, selectId, datalistId) {
        const input = document.getElementById(inputId);
        const select = document.getElementById(selectId);
        const datalist = document.getElementById(datalistId);
        if (!input || !select || !datalist) return;

        const options = Array.from(select.options)
            .filter((option) => option.value !== '0')
            .map((option) => ({ value: option.value, label: option.textContent || '' }));

        datalist.innerHTML = '';
        options.forEach((item) => {
            const option = document.createElement('option');
            option.value = item.label;
            datalist.appendChild(option);
        });

        function syncInputFromSelect() {
            const selected = select.options[select.selectedIndex];
            input.value = selected && selected.value !== '0' ? (selected.textContent || '') : '';
        }

        function syncSelectFromInput() {
            const term = (input.value || '').trim().toLowerCase();
            const found = options.find((item) => item.label.toLowerCase() === term);
            if (found) {
                select.value = found.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        input.addEventListener('change', syncSelectFromInput);
        input.addEventListener('blur', syncSelectFromInput);
        select.addEventListener('change', syncInputFromSelect);
        syncInputFromSelect();
    }

    attachUserPicker('user_id_search', 'user_id', 'user_id_options');
    attachUserPicker('closure_user_id_search', 'closure_user_id', 'closure_user_id_options');

    fillReadonly();
    document.getElementById('user_id').addEventListener('change', fillReadonly);
    calculatePreview();
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
