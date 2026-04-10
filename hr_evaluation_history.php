<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hr_evaluation_helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$targetUserId = (int) ($_GET['user_id'] ?? 0);
$year = (int) ($_GET['year'] ?? date('Y'));

$employee = $targetUserId > 0 ? taskforce_fetch_evaluation_employee($pdo, $targetUserId) : null;
$evaluations = $employee ? taskforce_fetch_year_evaluations($pdo, $targetUserId, $year) : [];

$closureStmt = $pdo->prepare('SELECT * FROM hr_evaluation_year_closures WHERE user_id = ? AND award_year = ? LIMIT 1');
$closureStmt->execute([$targetUserId, $year]);
$closure = $closureStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$profiles = taskforce_evaluation_profiles();
$metrics = [
    'count' => count($evaluations),
    'sum_period_total' => 0.0,
    'avg_performance' => 0.0,
    'avg_behavior' => 0.0,
    'sum_punctuality_count' => 0,
    'sum_absence_count' => 0,
];

foreach ($evaluations as $evaluation) {
    $metrics['sum_period_total'] += (float) ($evaluation['period_total'] ?? 0);
    $metrics['avg_performance'] += (int) ($evaluation['performance_score'] ?? 0);
    $metrics['avg_behavior'] += (int) ($evaluation['behavior_score'] ?? 0);
    $metrics['sum_punctuality_count'] += (int) ($evaluation['punctuality_count'] ?? 0);
    $metrics['sum_absence_count'] += (int) ($evaluation['absence_count'] ?? 0);
}
if ($metrics['count'] > 0) {
    $metrics['avg_performance'] = $metrics['avg_performance'] / $metrics['count'];
    $metrics['avg_behavior'] = $metrics['avg_behavior'] / $metrics['count'];
}

$predominantRule = '—';
if ($evaluations) {
    $ruleCount = [];
    foreach ($evaluations as $evaluation) {
        $ruleName = trim((string) ($evaluation['rule_set_name'] ?? '')) ?: 'Default helper';
        $ruleCount[$ruleName] = ($ruleCount[$ruleName] ?? 0) + 1;
    }
    arsort($ruleCount);
    $predominantRule = (string) array_key_first($ruleCount);
}

$pageTitle = 'Histórico de Avaliações';
require __DIR__ . '/partials/header.php';
?>
<a href="hr_evaluations.php" class="btn btn-link px-0">&larr; Voltar às avaliações</a>
<h1 class="h3 mb-3">Histórico de avaliações</h1>

<?php if (!$employee): ?>
<div class="alert alert-warning">Colaborador não encontrado.</div>
<?php else: ?>
<div class="soft-card p-3 mb-3">
    <div class="row g-2">
        <div class="col-md-6"><strong><?= h(format_user_picker_label($employee)) ?></strong></div>
        <div class="col-md-3">Departamento: <?= h((string) ($employee['department_name'] ?? '—')) ?></div>
        <div class="col-md-3">Perfil: <?= h($profiles[(string) ($employee['award_profile'] ?? '')] ?? (string) ($employee['award_profile'] ?? 'operador')) ?></div>
        <div class="col-md-3">Ano: <?= (int) $year ?></div>
        <div class="col-md-9">Regra predominante: <?= h($predominantRule) ?></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Nº avaliações</small><div class="h4 mb-0"><?= (int) $metrics['count'] ?></div></div></div>
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Soma prémios período</small><div class="h4 mb-0"><?= h(taskforce_money((float) $metrics['sum_period_total'])) ?></div></div></div>
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Bónus final</small><div class="h4 mb-0"><?= h(taskforce_money((float) ($closure['final_bonus_value'] ?? 0))) ?></div></div></div>
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Total anual</small><div class="h4 mb-0"><?= h(taskforce_money((float) ($closure['year_total_with_bonus'] ?? $metrics['sum_period_total']))) ?></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Média performance</small><div class="h4 mb-0"><?= number_format((float) $metrics['avg_performance'], 2, ',', ' ') ?></div></div></div>
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Média comportamento</small><div class="h4 mb-0"><?= number_format((float) $metrics['avg_behavior'], 2, ',', ' ') ?></div></div></div>
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Total pontualidade</small><div class="h4 mb-0"><?= (int) $metrics['sum_punctuality_count'] ?></div></div></div>
    <div class="col-lg-3"><div class="soft-card p-3 h-100"><small class="text-muted">Total absentismo</small><div class="h4 mb-0"><?= (int) $metrics['sum_absence_count'] ?></div></div></div>
</div>

<div class="soft-card p-3 mb-3 table-responsive">
    <h2 class="h5">Avaliações do ano</h2>
    <table class="table table-sm align-middle">
        <thead><tr><th>Período</th><th>Data avaliação</th><th>Performance</th><th>Comportamento</th><th>Pontualidade</th><th>Absentismo</th><th>Total período</th><th>Observações RH</th></tr></thead>
        <tbody>
        <?php if (!$evaluations): ?><tr><td colspan="8" class="text-muted">Sem avaliações neste ano.</td></tr><?php endif; ?>
        <?php foreach ($evaluations as $evaluation): ?>
            <?php
            $evaluationDateRaw = trim((string) ($evaluation['interview_date'] ?? ''));
            if ($evaluationDateRaw === '') {
                $evaluationDateRaw = (string) ($evaluation['created_at'] ?? '');
            }
            $evaluationDate = '—';
            if ($evaluationDateRaw !== '') {
                $ts = strtotime($evaluationDateRaw);
                if ($ts !== false) {
                    $evaluationDate = date('d/m/Y', $ts);
                }
            }

            $performanceNotes = trim((string) ($evaluation['performance_notes'] ?? ''));
            $behaviorNotes = trim((string) ($evaluation['behavior_notes'] ?? ''));
            $punctualityNotes = trim((string) ($evaluation['punctuality_notes'] ?? ''));
            $absenceNotes = trim((string) ($evaluation['absence_notes'] ?? ''));
            ?>
            <tr>
                <td><?= h(taskforce_evaluation_period_label((string) $evaluation['award_period'])) ?></td>
                <td><?= h($evaluationDate) ?></td>
                <td>
                    <?= (int) $evaluation['performance_score'] ?> (<?= h(taskforce_money((float) $evaluation['performance_value'])) ?>)
                    <div class="small text-muted"><?= h($performanceNotes !== '' ? $performanceNotes : 'Sem notas') ?></div>
                </td>
                <td>
                    <?= (int) $evaluation['behavior_score'] ?> (<?= h(taskforce_money((float) $evaluation['behavior_value'])) ?>)
                    <div class="small text-muted"><?= h($behaviorNotes !== '' ? $behaviorNotes : 'Sem notas') ?></div>
                </td>
                <td>
                    <?= (int) $evaluation['punctuality_count'] ?> (<?= h(taskforce_money((float) $evaluation['punctuality_value'])) ?>)
                    <div class="small text-muted"><?= h($punctualityNotes !== '' ? $punctualityNotes : 'Sem notas') ?></div>
                </td>
                <td>
                    <?= (int) $evaluation['absence_count'] ?> (<?= h(taskforce_money((float) $evaluation['absence_value'])) ?>)
                    <div class="small text-muted"><?= h($absenceNotes !== '' ? $absenceNotes : 'Sem notas') ?></div>
                </td>
                <td><strong><?= h(taskforce_money((float) $evaluation['period_total'])) ?></strong></td>
                <td><?= h((string) ($evaluation['general_notes'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="soft-card p-3">
    <h2 class="h5">Fecho anual</h2>
    <?php if (!$closure): ?>
        <p class="text-muted mb-0">Ainda sem fecho anual registado para este colaborador.</p>
    <?php else: ?>
        <p class="mb-1">Absentismo final: <strong><?= (int) $closure['final_absence_count'] ?></strong></p>
        <p class="mb-1">Bónus final: <strong><?= h(taskforce_money((float) $closure['final_bonus_value'])) ?></strong></p>
        <p class="mb-1">Total anual c/ bónus: <strong><?= h(taskforce_money((float) $closure['year_total_with_bonus'])) ?></strong></p>
        <p class="mb-0">Notas: <?= h((string) ($closure['final_notes'] ?? '')) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
