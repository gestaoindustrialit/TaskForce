<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hr_evaluation_helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$flashSuccess = null;
$flashError = null;
$profiles = taskforce_evaluation_profiles();
$departments = $pdo->query('SELECT id, name, group_id FROM hr_departments ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$groups = $pdo->query('SELECT id, name FROM hr_department_groups ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$currentYear = (int) date('Y');

$form = [
    'id' => 0, 'name' => '', 'code' => '', 'award_year' => $currentYear, 'profile_key' => 'operador', 'department_group_id' => 0, 'department_id' => 0,
    'priority' => 100, 'is_active' => 1, 'notes' => '',
    'performance_0' => 0, 'performance_1' => 12.5, 'performance_2' => 25, 'performance_3' => 0,
    'behavior_0' => 0, 'behavior_1' => 12.5, 'behavior_2' => 25, 'behavior_3' => 0,
    'punctuality_zero_value' => 25, 'punctuality_penalty_per_unit' => -2.5,
    'absence_zero_value' => 50, 'absence_penalty_per_unit' => -50,
    'final_bonus_0' => 250, 'final_bonus_1' => 125, 'final_bonus_2' => 62.5, 'final_bonus_3_plus' => 0,
    'max_period' => 125, 'max_year' => 625,
];

if (isset($_GET['edit_id'])) {
    $editId = (int) $_GET['edit_id'];
    $stmt = $pdo->prepare('SELECT * FROM hr_evaluation_rule_sets WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rule) {
        $config = json_decode((string) ($rule['config_json'] ?? ''), true);
        $config = taskforce_normalize_rule_config(is_array($config) ? $config : [], (string) $rule['profile_key']);
        $form = array_merge($form, [
            'id' => (int) $rule['id'], 'name' => (string) $rule['name'], 'code' => (string) ($rule['code'] ?? ''), 'award_year' => (int) $rule['award_year'],
            'profile_key' => (string) $rule['profile_key'], 'department_group_id' => (int) ($rule['department_group_id'] ?? 0), 'department_id' => (int) ($rule['department_id'] ?? 0),
            'priority' => (int) ($rule['priority'] ?? 100), 'is_active' => (int) ($rule['is_active'] ?? 1), 'notes' => (string) ($rule['notes'] ?? ''),
            'performance_0' => (float) $config['scores']['performance']['0'], 'performance_1' => (float) $config['scores']['performance']['1'],
            'performance_2' => (float) $config['scores']['performance']['2'], 'performance_3' => (float) $config['scores']['performance']['3'],
            'behavior_0' => (float) $config['scores']['behavior']['0'], 'behavior_1' => (float) $config['scores']['behavior']['1'],
            'behavior_2' => (float) $config['scores']['behavior']['2'], 'behavior_3' => (float) $config['scores']['behavior']['3'],
            'punctuality_zero_value' => (float) $config['counts']['punctuality']['zero_value'], 'punctuality_penalty_per_unit' => (float) $config['counts']['punctuality']['penalty_per_unit'],
            'absence_zero_value' => (float) $config['counts']['absence']['zero_value'], 'absence_penalty_per_unit' => (float) $config['counts']['absence']['penalty_per_unit'],
            'final_bonus_0' => (float) $config['counts']['final_absence_bonus']['0'], 'final_bonus_1' => (float) $config['counts']['final_absence_bonus']['1'],
            'final_bonus_2' => (float) $config['counts']['final_absence_bonus']['2'], 'final_bonus_3_plus' => (float) $config['counts']['final_absence_bonus']['3_plus'],
            'max_period' => (float) $config['maximums']['period_total'], 'max_year' => (float) $config['maximums']['year_total'],
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'toggle_rule') {
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        $pdo->prepare('UPDATE hr_evaluation_rule_sets SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$ruleId]);
        $flashSuccess = 'Estado da regra atualizado.';
    }

    if ($action === 'duplicate_rule') {
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        $targetYear = (int) ($_POST['target_year'] ?? $currentYear);
        $stmt = $pdo->prepare('SELECT * FROM hr_evaluation_rule_sets WHERE id = ? LIMIT 1');
        $stmt->execute([$ruleId]);
        $src = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($src && $targetYear >= 2024 && $targetYear <= 2100) {
            $insert = $pdo->prepare('INSERT INTO hr_evaluation_rule_sets(name, code, award_year, profile_key, department_group_id, department_id, is_active, priority, notes, config_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                (string) $src['name'] . ' (' . $targetYear . ')', $src['code'], $targetYear, $src['profile_key'], $src['department_group_id'], $src['department_id'],
                $src['is_active'], $src['priority'], $src['notes'], $src['config_json'], $userId,
            ]);
            $flashSuccess = 'Regra duplicada para ' . $targetYear . '.';
        } else {
            $flashError = 'Não foi possível duplicar regra.';
        }
    }

    if ($action === 'save_rule') {
        $form = array_merge($form, $_POST);
        $ruleId = (int) ($form['id'] ?? 0);
        $profileKey = (string) ($form['profile_key'] ?? 'operador');
        if (!array_key_exists($profileKey, $profiles)) {
            $profileKey = 'operador';
        }

        $config = taskforce_normalize_rule_config([
            'scores' => [
                'performance' => ['0' => (float) ($form['performance_0'] ?? 0), '1' => (float) ($form['performance_1'] ?? 0), '2' => (float) ($form['performance_2'] ?? 0), '3' => (float) ($form['performance_3'] ?? 0)],
                'behavior' => ['0' => (float) ($form['behavior_0'] ?? 0), '1' => (float) ($form['behavior_1'] ?? 0), '2' => (float) ($form['behavior_2'] ?? 0), '3' => (float) ($form['behavior_3'] ?? 0)],
            ],
            'counts' => [
                'punctuality' => ['zero_value' => (float) ($form['punctuality_zero_value'] ?? 0), 'penalty_per_unit' => (float) ($form['punctuality_penalty_per_unit'] ?? 0)],
                'absence' => ['zero_value' => (float) ($form['absence_zero_value'] ?? 0), 'penalty_per_unit' => (float) ($form['absence_penalty_per_unit'] ?? 0)],
                'final_absence_bonus' => ['0' => (float) ($form['final_bonus_0'] ?? 0), '1' => (float) ($form['final_bonus_1'] ?? 0), '2' => (float) ($form['final_bonus_2'] ?? 0), '3_plus' => (float) ($form['final_bonus_3_plus'] ?? 0)],
            ],
            'maximums' => ['period_total' => (float) ($form['max_period'] ?? 0), 'year_total' => (float) ($form['max_year'] ?? 0)],
        ], $profileKey);

        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $departmentId = (int) ($form['department_id'] ?? 0);
        $groupId = (int) ($form['department_group_id'] ?? 0);

        if ($ruleId > 0) {
            $stmt = $pdo->prepare('UPDATE hr_evaluation_rule_sets SET name = ?, code = ?, award_year = ?, profile_key = ?, department_group_id = ?, department_id = ?, is_active = ?, priority = ?, notes = ?, config_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([
                trim((string) ($form['name'] ?? '')), trim((string) ($form['code'] ?? '')) ?: null, (int) ($form['award_year'] ?? $currentYear), $profileKey,
                $groupId > 0 ? $groupId : null, $departmentId > 0 ? $departmentId : null, (int) ($form['is_active'] ?? 0) === 1 ? 1 : 0,
                (int) ($form['priority'] ?? 100), trim((string) ($form['notes'] ?? '')) ?: null, $configJson, $ruleId,
            ]);
            $flashSuccess = 'Regra atualizada.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO hr_evaluation_rule_sets(name, code, award_year, profile_key, department_group_id, department_id, is_active, priority, notes, config_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                trim((string) ($form['name'] ?? '')), trim((string) ($form['code'] ?? '')) ?: null, (int) ($form['award_year'] ?? $currentYear), $profileKey,
                $groupId > 0 ? $groupId : null, $departmentId > 0 ? $departmentId : null, (int) ($form['is_active'] ?? 0) === 1 ? 1 : 0,
                (int) ($form['priority'] ?? 100), trim((string) ($form['notes'] ?? '')) ?: null, $configJson, $userId,
            ]);
            $flashSuccess = 'Regra criada.';
        }
    }
}

$filterYear = (int) ($_GET['year'] ?? $currentYear);
$filterProfile = trim((string) ($_GET['profile'] ?? ''));
$filterDepartment = (int) ($_GET['department_id'] ?? 0);
$filterGroup = (int) ($_GET['group_id'] ?? 0);

$sql = 'SELECT r.*, d.name AS department_name, g.name AS group_name FROM hr_evaluation_rule_sets r LEFT JOIN hr_departments d ON d.id = r.department_id LEFT JOIN hr_department_groups g ON g.id = r.department_group_id WHERE 1=1';
$params = [];
if ($filterYear > 0) { $sql .= ' AND r.award_year = :year'; $params[':year'] = $filterYear; }
if ($filterProfile !== '' && array_key_exists($filterProfile, $profiles)) { $sql .= ' AND r.profile_key = :profile'; $params[':profile'] = $filterProfile; }
if ($filterDepartment > 0) { $sql .= ' AND r.department_id = :dep'; $params[':dep'] = $filterDepartment; }
if ($filterGroup > 0) { $sql .= ' AND r.department_group_id = :grp'; $params[':grp'] = $filterGroup; }
$sql .= ' ORDER BY r.award_year DESC, r.profile_key ASC, r.priority ASC, r.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Regras de avaliações';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Regras de avaliações</h1>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="soft-card p-3 mb-3">
<form method="get" class="row g-2 align-items-end">
<div class="col-md-2"><label class="form-label">Ano</label><input class="form-control" type="number" name="year" value="<?= (int) $filterYear ?>"></div>
<div class="col-md-2"><label class="form-label">Perfil</label><select class="form-select" name="profile"><option value="">Todos</option><?php foreach ($profiles as $k => $l): ?><option value="<?= h($k) ?>" <?= $filterProfile === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Departamento</label><select class="form-select" name="department_id"><option value="0">Todos</option><?php foreach ($departments as $dep): ?><option value="<?= (int) $dep['id'] ?>" <?= $filterDepartment === (int) $dep['id'] ? 'selected' : '' ?>><?= h($dep['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Grupo</label><select class="form-select" name="group_id"><option value="0">Todos</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" <?= $filterGroup === (int) $group['id'] ? 'selected' : '' ?>><?= h($group['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2 d-grid"><button class="btn btn-dark">Filtrar</button></div>
</form>
</div>

<div class="soft-card p-3 mb-4 table-responsive">
<table class="table table-sm align-middle">
<thead><tr><th>ID</th><th>Nome</th><th>Ano</th><th>Perfil</th><th>Departamento</th><th>Grupo</th><th>Prioridade</th><th>Ativa</th><th>Aplicação</th><th>Ações</th></tr></thead>
<tbody>
<?php if (!$rules): ?><tr><td colspan="10" class="text-muted">Sem regras.</td></tr><?php endif; ?>
<?php foreach ($rules as $rule): ?>
<tr>
<td><?= (int) $rule['id'] ?></td><td><?= h((string) $rule['name']) ?></td><td><?= (int) $rule['award_year'] ?></td><td><?= h($profiles[(string) $rule['profile_key']] ?? (string) $rule['profile_key']) ?></td>
<td><?= h((string) ($rule['department_name'] ?? '—')) ?></td><td><?= h((string) ($rule['group_name'] ?? '—')) ?></td><td><?= (int) $rule['priority'] ?></td>
<td><?= (int) $rule['is_active'] === 1 ? 'Sim' : 'Não' ?></td>
<td><small><?php if (!empty($rule['department_id'])): ?>Departamento<?php elseif (!empty($rule['department_group_id'])): ?>Grupo<?php else: ?>Perfil/Ano<?php endif; ?></small></td>
<td><div class="d-flex gap-1"><a class="btn btn-sm btn-outline-secondary" href="hr_evaluation_rules.php?edit_id=<?= (int) $rule['id'] ?>">Editar</a><form method="post"><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>"><button class="btn btn-sm btn-outline-dark">Ativar/Desativar</button></form><form method="post" class="d-flex gap-1"><input type="hidden" name="action" value="duplicate_rule"><input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>"><input class="form-control form-control-sm" style="width:90px" type="number" name="target_year" value="<?= (int) $currentYear + 1 ?>"><button class="btn btn-sm btn-outline-primary">Duplicar</button></form></div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="soft-card p-3">
<h2 class="h5 mb-3"><?= (int) $form['id'] > 0 ? 'Editar regra' : 'Nova regra' ?></h2>
<form method="post" class="row g-2" id="ruleForm">
<input type="hidden" name="action" value="save_rule"><input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
<div class="col-md-4"><label class="form-label">Nome</label><input class="form-control" name="name" value="<?= h((string) $form['name']) ?>" required></div>
<div class="col-md-2"><label class="form-label">Código</label><input class="form-control" name="code" value="<?= h((string) $form['code']) ?>"></div>
<div class="col-md-2"><label class="form-label">Ano</label><input class="form-control" type="number" min="2024" max="2100" name="award_year" value="<?= (int) $form['award_year'] ?>" required></div>
<div class="col-md-2"><label class="form-label">Perfil</label><select class="form-select" name="profile_key"><?php foreach ($profiles as $k => $l): ?><option value="<?= h($k) ?>" <?= (string) $form['profile_key'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
<div class="col-md-1"><label class="form-label">Prioridade</label><input class="form-control" type="number" name="priority" value="<?= (int) $form['priority'] ?>"></div>
<div class="col-md-1"><label class="form-label">Ativa</label><select class="form-select" name="is_active"><option value="1" <?= (int) $form['is_active'] === 1 ? 'selected' : '' ?>>Sim</option><option value="0" <?= (int) $form['is_active'] === 0 ? 'selected' : '' ?>>Não</option></select></div>
<div class="col-md-3"><label class="form-label">Grupo de departamento</label><select class="form-select" name="department_group_id"><option value="0">Sem grupo</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" <?= (int) $form['department_group_id'] === (int) $group['id'] ? 'selected' : '' ?>><?= h($group['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Departamento</label><select class="form-select" name="department_id"><option value="0">Sem departamento</option><?php foreach ($departments as $dep): ?><option value="<?= (int) $dep['id'] ?>" <?= (int) $form['department_id'] === (int) $dep['id'] ? 'selected' : '' ?>><?= h($dep['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Notas</label><input class="form-control" name="notes" value="<?= h((string) $form['notes']) ?>"></div>

<div class="col-12"><h3 class="h6 mt-2">Scores</h3></div>
<?php foreach (['performance' => 'Performance', 'behavior' => 'Comportamento'] as $base => $label): ?>
<div class="col-md-3"><label class="form-label"><?= h($label) ?> (0)</label><input type="number" step="0.01" class="form-control js-json" name="<?= h($base) ?>_0" value="<?= h((string) $form[$base . '_0']) ?>"></div>
<div class="col-md-3"><label class="form-label"><?= h($label) ?> (1)</label><input type="number" step="0.01" class="form-control js-json" name="<?= h($base) ?>_1" value="<?= h((string) $form[$base . '_1']) ?>"></div>
<div class="col-md-3"><label class="form-label"><?= h($label) ?> (2)</label><input type="number" step="0.01" class="form-control js-json" name="<?= h($base) ?>_2" value="<?= h((string) $form[$base . '_2']) ?>"></div>
<div class="col-md-3"><label class="form-label"><?= h($label) ?> (3)</label><input type="number" step="0.01" class="form-control js-json" name="<?= h($base) ?>_3" value="<?= h((string) $form[$base . '_3']) ?>"></div>
<?php endforeach; ?>

<div class="col-12"><h3 class="h6 mt-2">Contagens e bónus</h3></div>
<div class="col-md-3"><label class="form-label">Pontualidade (zero)</label><input type="number" step="0.01" class="form-control js-json" name="punctuality_zero_value" value="<?= h((string) $form['punctuality_zero_value']) ?>"></div>
<div class="col-md-3"><label class="form-label">Pontualidade (penalização)</label><input type="number" step="0.01" class="form-control js-json" name="punctuality_penalty_per_unit" value="<?= h((string) $form['punctuality_penalty_per_unit']) ?>"></div>
<div class="col-md-3"><label class="form-label">Absentismo (zero)</label><input type="number" step="0.01" class="form-control js-json" name="absence_zero_value" value="<?= h((string) $form['absence_zero_value']) ?>"></div>
<div class="col-md-3"><label class="form-label">Absentismo (penalização)</label><input type="number" step="0.01" class="form-control js-json" name="absence_penalty_per_unit" value="<?= h((string) $form['absence_penalty_per_unit']) ?>"></div>
<div class="col-md-3"><label class="form-label">Bónus final 0</label><input type="number" step="0.01" class="form-control js-json" name="final_bonus_0" value="<?= h((string) $form['final_bonus_0']) ?>"></div>
<div class="col-md-3"><label class="form-label">Bónus final 1</label><input type="number" step="0.01" class="form-control js-json" name="final_bonus_1" value="<?= h((string) $form['final_bonus_1']) ?>"></div>
<div class="col-md-3"><label class="form-label">Bónus final 2</label><input type="number" step="0.01" class="form-control js-json" name="final_bonus_2" value="<?= h((string) $form['final_bonus_2']) ?>"></div>
<div class="col-md-3"><label class="form-label">Bónus final 3+</label><input type="number" step="0.01" class="form-control js-json" name="final_bonus_3_plus" value="<?= h((string) $form['final_bonus_3_plus']) ?>"></div>
<div class="col-md-3"><label class="form-label">Máximo período</label><input type="number" step="0.01" class="form-control js-json" name="max_period" value="<?= h((string) $form['max_period']) ?>"></div>
<div class="col-md-3"><label class="form-label">Máximo ano</label><input type="number" step="0.01" class="form-control js-json" name="max_year" value="<?= h((string) $form['max_year']) ?>"></div>
<div class="col-12 d-flex gap-2"><button class="btn btn-dark">Guardar regra</button><a class="btn btn-outline-secondary" href="hr_evaluation_rules.php">Limpar</a></div>
</form>

<div class="mt-3">
    <label class="form-label">Pré-visualização JSON normalizado</label>
    <pre class="small bg-light border rounded p-3" id="jsonPreview"></pre>
</div>
</div>

<script>
(() => {
    const preview = document.getElementById('jsonPreview');
    const form = document.getElementById('ruleForm');
    if (!form || !preview) return;

    function n(name) {
        const node = form.querySelector('[name="' + name + '"]');
        return Number(node ? node.value : 0);
    }

    function updatePreview() {
        const data = {
            profile_key: (form.querySelector('[name="profile_key"]') || {}).value || 'operador',
            periods: { jan_abr: 'Janeiro - Abril', mai_ago: 'Maio - Agosto', set_dez: 'Setembro - Dezembro' },
            scores: {
                performance: { '0': n('performance_0'), '1': n('performance_1'), '2': n('performance_2'), '3': n('performance_3') },
                behavior: { '0': n('behavior_0'), '1': n('behavior_1'), '2': n('behavior_2'), '3': n('behavior_3') }
            },
            counts: {
                punctuality: { zero_value: n('punctuality_zero_value'), penalty_per_unit: n('punctuality_penalty_per_unit') },
                absence: { zero_value: n('absence_zero_value'), penalty_per_unit: n('absence_penalty_per_unit') },
                final_absence_bonus: { '0': n('final_bonus_0'), '1': n('final_bonus_1'), '2': n('final_bonus_2'), '3_plus': n('final_bonus_3_plus') }
            },
            maximums: { period_total: n('max_period'), year_total: n('max_year') }
        };
        preview.textContent = JSON.stringify(data, null, 2);
    }

    form.querySelectorAll('.js-json, [name="profile_key"]').forEach((input) => {
        input.addEventListener('input', updatePreview);
        input.addEventListener('change', updatePreview);
    });

    updatePreview();
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
