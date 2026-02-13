<?php
require_once __DIR__ . '/../helpers.php';
$user = current_user($pdo);
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>TaskForce</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <?php if ($navbarLogo): ?>
                <img src="<?= h($navbarLogo) ?>" alt="Logo empresa" class="brand-logo">
            <?php endif; ?>
            <span>TaskForce</span>
        </a>
        <?php if ($user): ?>
            <div class="navbar-nav me-auto ms-4">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="requests.php">Pedidos &agrave;s equipas</a>
                <a class="nav-link" href="daily_report.php">Relat&oacute;rio di&aacute;rio</a>
            </div>
        <?php endif; ?>
        <div class="ms-auto d-flex align-items-center gap-3 text-white">
            <?php if ($user): ?>
                <span class="small">Ol&aacute;, <?= h($user['name']) ?><?= (int) $user['is_admin'] === 1 ? ' &middot; Admin' : '' ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Sair</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container py-4">
