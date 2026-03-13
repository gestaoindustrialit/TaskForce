<?php
require_once __DIR__ . '/../helpers.php';
$user = current_user($pdo);
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$showHrMenu = $user && ((int) ($user['is_admin'] ?? 0) === 1 || (string) ($user['access_profile'] ?? '') === 'RH');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>TaskForce</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
</head>
<body class="<?= isset($bodyClass) ? h($bodyClass) : 'bg-light' ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <?php if ($navbarLogo): ?>
                <img src="<?= h($navbarLogo) ?>" alt="Logo empresa" class="brand-logo">
            <?php endif; ?>
            <span>TaskForce</span>
        </a>
        <?php if ($user): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Alternar navega&ccedil;&atilde;o">
                <span class="navbar-toggler-icon"></span>
            </button>
        <?php endif; ?>
        <?php if ($user): ?>
            <?php
            $navTeamsStmt = $pdo->prepare(
                'SELECT t.id, t.name
                 FROM teams t
                 INNER JOIN team_members tm ON tm.team_id = t.id
                 WHERE tm.user_id = ?
                 ORDER BY t.name COLLATE NOCASE ASC'
            );
            $navTeamsStmt->execute([(int) $user['id']]);
            $navTeams = $navTeamsStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="collapse navbar-collapse" id="mainNavbar">
                <div class="navbar-nav me-auto ms-lg-4">
                    <a class="nav-link" href="dashboard.php">Vis&atilde;o geral</a>
                    <?php if ($showHrMenu): ?>
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">RH</a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="hr.php">M&oacute;dulo RH</a></li>
                                <li><a class="dropdown-item" href="hr_departments.php">Departamentos</a></li>
                                <li><a class="dropdown-item" href="hr_schedules.php">Hor&aacute;rios</a></li>
                                <li><a class="dropdown-item" href="hr_vacations.php">Calend&aacute;rio F&eacute;rias</a></li>
                                <li><a class="dropdown-item" href="hr_alerts.php">Alertas RH</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Opera&ccedil;&otilde;es</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="requests.php">Gerar formul&aacute;rios</a></li>
                            <li><a class="dropdown-item" href="daily_report.php">Relat&oacute;rio di&aacute;rio</a></li>
                            <li><a class="dropdown-item" href="checklists.php">Checklists</a></li>
                        </ul>
                    </div>
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Equipas</a>
                        <ul class="dropdown-menu">
                            <?php if ($navTeams): ?>
                                <?php foreach ($navTeams as $navTeam): ?>
                                    <li><a class="dropdown-item" href="team.php?id=<?= (int) $navTeam['id'] ?>"><?= h($navTeam['name']) ?></a></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li><span class="dropdown-item-text text-muted">Sem equipas dispon&iacute;veis</span></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php if ((int) $user['is_admin'] === 1): ?>
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administra&ccedil;&atilde;o</a>
                            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-lg-start">
                                <li><a class="dropdown-item" href="company_profile.php">Empresa &amp; Branding</a></li>
                                <li><a class="dropdown-item" href="users.php">Utilizadores</a></li>
                                <li><a class="dropdown-item" href="hr.php">Módulo RH</a></li>
                                <li><a class="dropdown-item" href="hr_departments.php">Departamentos</a></li>
                                <li><a class="dropdown-item" href="hr_schedules.php">Horários</a></li>
                                <li><a class="dropdown-item" href="hr_vacations.php">Calendário Férias</a></li>
                                <li><a class="dropdown-item" href="hr_alerts.php">Alertas RH</a></li>
                                <li><a class="dropdown-item" href="app_logs.php">Logs da aplica&ccedil;&atilde;o</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="navbar-user mt-3 mt-lg-0 ms-lg-auto d-flex flex-column flex-lg-row align-items-start align-items-lg-center gap-2 gap-lg-3 text-white">
                    <span class="small">Ol&aacute;, <?= h($user['name']) ?><?= (int) $user['is_admin'] === 1 ? ' &middot; Admin' : '' ?></span>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">Sair</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</nav>
<main class="container py-4">
