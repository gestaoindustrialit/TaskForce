<?php
require_once __DIR__ . '/../helpers.php';
$user = current_user($pdo);
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
        <a class="navbar-brand" href="dashboard.php">TaskForce</a>
        <?php if ($user): ?>
            <?php
            $navForms = $pdo->query('SELECT id, title FROM team_forms WHERE is_active = 1 ORDER BY created_at DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="navbar-nav me-auto ms-4">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="requests.php">Pedidos &agrave;s equipas</a>
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Formul&aacute;rios</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="requests.php">Ver todos</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach ($navForms as $form): ?>
                            <li><a class="dropdown-item" href="requests.php#form-<?= (int) $form['id'] ?>"><?= h($form['title']) ?></a></li>
                        <?php endforeach; ?>
                        <?php if (!$navForms): ?><li><span class="dropdown-item-text text-muted">Sem formul&aacute;rios ativos</span></li><?php endif; ?>
                    </ul>
                </div>
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
