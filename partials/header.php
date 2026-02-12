<?php
require_once __DIR__ . '/../helpers.php';
$user = current_user($pdo);
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
        <a class="navbar-brand" href="dashboard.php">TaskForce</a>
        <?php if ($user): ?>
            <div class="navbar-nav me-auto ms-4">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="requests.php">Pedidos às equipas</a>
            </div>
        <?php endif; ?>
        <div class="ms-auto d-flex align-items-center gap-3 text-white">
            <?php if ($user): ?>
                <span class="small">Olá, <?= h($user['name']) ?><?= (int) $user['is_admin'] === 1 ? ' · Admin' : '' ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Sair</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container py-4">
