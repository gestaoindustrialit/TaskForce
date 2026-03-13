<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$stats = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'departments' => (int) $pdo->query('SELECT COUNT(*) FROM hr_departments')->fetchColumn(),
    'schedules' => (int) $pdo->query('SELECT COUNT(*) FROM hr_schedules')->fetchColumn(),
    'vacations' => (int) $pdo->query('SELECT COUNT(*) FROM hr_vacation_events')->fetchColumn(),
    'alerts' => (int) $pdo->query('SELECT COUNT(*) FROM hr_alerts WHERE is_active = 1')->fetchColumn(),
];

$pageTitle = 'Módulo RH';
require __DIR__ . '/partials/header.php';
?>
<a href="dashboard.php" class="btn btn-link px-0">&larr; Voltar à dashboard</a>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Módulo RH</h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Utilizadores</div><div class="h4 mb-0"><?= $stats['users'] ?></div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Departamentos</div><div class="h4 mb-0"><?= $stats['departments'] ?></div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Horários</div><div class="h4 mb-0"><?= $stats['schedules'] ?></div></div></div></div>
    <div class="col-md-6"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Férias registadas</div><div class="h4 mb-0"><?= $stats['vacations'] ?></div></div></div></div>
    <div class="col-md-6"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Alertas ativos</div><div class="h4 mb-0"><?= $stats['alerts'] ?></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-md-6 col-lg-4"><a class="card h-100 text-decoration-none" href="users.php"><div class="card-body"><h2 class="h6">Gestão de utilizadores</h2><p class="text-muted mb-0">Ficha de utilizador com departamento e horário.</p></div></a></div>
    <div class="col-md-6 col-lg-4"><a class="card h-100 text-decoration-none" href="hr_departments.php"><div class="card-body"><h2 class="h6">Departamentos</h2><p class="text-muted mb-0">Criação de departamentos e grupos (produção, controlo, administrativos).</p></div></a></div>
    <div class="col-md-6 col-lg-4"><a class="card h-100 text-decoration-none" href="hr_schedules.php"><div class="card-body"><h2 class="h6">Horários</h2><p class="text-muted mb-0">Definição de horários e dias da semana.</p></div></a></div>
    <div class="col-md-6 col-lg-6"><a class="card h-100 text-decoration-none" href="hr_vacations.php"><div class="card-body"><h2 class="h6">Calendário de férias</h2><p class="text-muted mb-0">Planeamento das ausências e férias.</p></div></a></div>
    <div class="col-md-6 col-lg-6"><a class="card h-100 text-decoration-none" href="hr_alerts.php"><div class="card-body"><h2 class="h6">Alertas</h2><p class="text-muted mb-0">Alertas automáticos por e-mail (ex.: ausências às 08:00).</p></div></a></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
