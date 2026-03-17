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
<a href="dashboard.php" class="btn btn-link px-0 mb-2">&larr; Voltar à dashboard</a>

<section class="hr-shell mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h1 class="h2 mb-1">Painel RH</h1>
            <p class="text-muted mb-0">Vista central do módulo RH com atalhos para as páginas existentes de gestão.</p>
        </div>
        <div class="hr-quick-tabs" role="navigation" aria-label="Navegação RH">
            <a class="btn btn-dark" href="hr.php"><i class="bi bi-speedometer2 me-1"></i>Painel RH</a>
            <a class="btn btn-outline-secondary" href="hr_departments.php">Departamentos</a>
            <a class="btn btn-outline-secondary" href="hr_schedules.php">Horários</a>
            <a class="btn btn-outline-secondary" href="hr_calendar.php">Calendário</a>
            <a class="btn btn-outline-secondary" href="hr_bank.php">Banco de horas</a>
            <a class="btn btn-outline-secondary" href="hr_absences.php">Ausências</a>
            <a class="btn btn-outline-secondary" href="hr_alerts.php">Alertas</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <article class="soft-card h-100 p-3 hr-stat-card">
                <p class="text-muted mb-1 small">Utilizadores</p>
                <p class="display-6 mb-0"><?= $stats['users'] ?></p>
            </article>
        </div>
        <div class="col-6 col-lg-3">
            <article class="soft-card h-100 p-3 hr-stat-card">
                <p class="text-muted mb-1 small">Departamentos</p>
                <p class="display-6 mb-0"><?= $stats['departments'] ?></p>
            </article>
        </div>
        <div class="col-6 col-lg-3">
            <article class="soft-card h-100 p-3 hr-stat-card">
                <p class="text-muted mb-1 small">Horários</p>
                <p class="display-6 mb-0"><?= $stats['schedules'] ?></p>
            </article>
        </div>
        <div class="col-6 col-lg-3">
            <article class="soft-card h-100 p-3 hr-stat-card hr-stat-card-accent">
                <p class="text-muted mb-1 small">Alertas ativos</p>
                <p class="display-6 mb-0"><?= $stats['alerts'] ?></p>
            </article>
        </div>
    </div>

    <div class="soft-card p-3 p-lg-4 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
            <h2 class="h4 mb-0">Checklist rápido do módulo</h2>
            <small class="text-muted">Configuração base para operação RH diária</small>
        </div>
        <div class="row g-2">
            <div class="col-md-6 col-xl-4"><div class="hr-check-item"><i class="bi bi-check-circle-fill text-success"></i>Departamentos agrupados por área</div></div>
            <div class="col-md-6 col-xl-4"><div class="hr-check-item"><i class="bi bi-check-circle-fill text-success"></i>Horários com dias da semana definidos</div></div>
            <div class="col-md-6 col-xl-4"><div class="hr-check-item"><i class="bi bi-check-circle-fill text-success"></i>Alertas automáticos ativos</div></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <a class="soft-card h-100 p-3 p-lg-4 d-block text-decoration-none hr-link-card" href="hr_departments.php">
                <h3 class="h5 mb-2">Departamentos</h3>
                <p class="text-muted mb-3">Criação e organização de grupos/departamentos já existentes no sistema.</p>
                <span class="btn btn-sm btn-outline-dark">Abrir departamentos</span>
            </a>
        </div>
        <div class="col-lg-6">
            <a class="soft-card h-100 p-3 p-lg-4 d-block text-decoration-none hr-link-card" href="hr_schedules.php">
                <h3 class="h5 mb-2">Horários</h3>
                <p class="text-muted mb-3">Definição de turnos, horas e dias para equipas e colaboradores.</p>
                <span class="btn btn-sm btn-outline-dark">Abrir horários</span>
            </a>
        </div>
        <div class="col-lg-6">
            <a class="soft-card h-100 p-3 p-lg-4 d-block text-decoration-none hr-link-card" href="hr_alerts.php">
                <h3 class="h5 mb-2">Alertas RH</h3>
                <p class="text-muted mb-3">Notificações automáticas por e-mail para ausências e rotinas operacionais.</p>
                <span class="btn btn-sm btn-outline-dark">Abrir alertas</span>
            </a>
        </div>
        <div class="col-lg-6">
            <a class="soft-card h-100 p-3 p-lg-4 d-block text-decoration-none hr-link-card" href="hr_vacations.php">
                <h3 class="h5 mb-2">Férias</h3>
                <p class="text-muted mb-3">Registos atuais de férias e ausências programadas para a equipa.</p>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="btn btn-sm btn-outline-dark">Abrir calendário</span>
                    <span class="badge text-bg-warning-subtle border"><?= $stats['vacations'] ?> registos</span>
                </div>
            </a>
        </div>
        <div class="col-lg-6">
            <a class="soft-card h-100 p-3 p-lg-4 d-block text-decoration-none hr-link-card" href="hr_calendar.php">
                <h3 class="h5 mb-2">Calendário</h3>
                <p class="text-muted mb-3">Calendário anual com importação de feriados, pontes e marcações.</p>
                <span class="btn btn-sm btn-outline-dark">Abrir calendário anual</span>
            </a>
        </div>
        <div class="col-lg-6">
            <a class="soft-card h-100 p-3 p-lg-4 d-block text-decoration-none hr-link-card" href="hr_bank.php">
                <h3 class="h5 mb-2">Banco de horas</h3>
                <p class="text-muted mb-3">Ajustes manuais de saldo com histórico administrativo por colaborador.</p>
                <span class="btn btn-sm btn-outline-dark">Abrir banco de horas</span>
            </a>
        </div>
        <div class="col-lg-6">
            <a class="soft-card h-100 p-3 p-lg-4 d-block text-decoration-none hr-link-card" href="hr_absences.php">
                <h3 class="h5 mb-2">Ausências</h3>
                <p class="text-muted mb-3">Workflow com duas aprovações (N1 e RH) e gestão central de pedidos.</p>
                <span class="btn btn-sm btn-outline-dark">Abrir ausências</span>
            </a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
