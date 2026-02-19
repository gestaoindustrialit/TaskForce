<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!is_admin($pdo, $userId)) {
    redirect('dashboard.php');
}

$search = trim((string) ($_GET['search'] ?? ''));
$eventType = trim((string) ($_GET['event_type'] ?? ''));
$targetUserId = (int) ($_GET['user_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$filters = [];
$params = [];

if ($search !== '') {
    $filters[] = '(al.description LIKE ? OR al.context_json LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($eventType !== '') {
    $filters[] = 'al.event_type = ?';
    $params[] = $eventType;
}

if ($targetUserId > 0) {
    $filters[] = 'al.user_id = ?';
    $params[] = $targetUserId;
}

$whereSql = count($filters) > 0 ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM app_logs al
     LEFT JOIN users u ON u.id = al.user_id
     $whereSql"
);
$countStmt->execute($params);
$totalLogs = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLogs / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listStmt = $pdo->prepare(
    "SELECT al.*, u.name AS user_name, u.email AS user_email
     FROM app_logs al
     LEFT JOIN users u ON u.id = al.user_id
     $whereSql
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$logs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$eventTypes = $pdo->query('SELECT DISTINCT event_type FROM app_logs ORDER BY event_type ASC')->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query('SELECT id, name, email FROM users ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

$queryBase = [];
if ($search !== '') {
    $queryBase['search'] = $search;
}
if ($eventType !== '') {
    $queryBase['event_type'] = $eventType;
}
if ($targetUserId > 0) {
    $queryBase['user_id'] = (string) $targetUserId;
}

$pageTitle = 'Logs da aplicação';
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1">Logs da aplicação</h1>
        <p class="text-muted mb-0">Histórico de ações e acontecimentos registados no sistema.</p>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Pesquisar</label>
                <input type="text" name="search" class="form-control" value="<?= h($search) ?>" placeholder="Descrição, contexto, nome ou email">
            </div>
            <div class="col-md-3">
                <label class="form-label">Evento</label>
                <select name="event_type" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($eventTypes as $type): ?>
                        <option value="<?= h((string) $type) ?>" <?= $eventType === (string) $type ? 'selected' : '' ?>><?= h((string) $type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Utilizador</label>
                <select name="user_id" class="form-select">
                    <option value="0">Todos</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $targetUserId === (int) $u['id'] ? 'selected' : '' ?>>
                            <?= h($u['name']) ?> (<?= h($u['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                <tr>
                    <th style="min-width: 170px;">Data</th>
                    <th style="min-width: 180px;">Evento</th>
                    <th style="min-width: 230px;">Utilizador</th>
                    <th>Descrição</th>
                    <th style="min-width: 280px;">Contexto</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Sem logs para os filtros selecionados.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= h((string) $log['created_at']) ?></td>
                            <td><code><?= h((string) $log['event_type']) ?></code></td>
                            <td>
                                <?php if (!empty($log['user_name'])): ?>
                                    <strong><?= h((string) $log['user_name']) ?></strong><br>
                                    <span class="small text-muted"><?= h((string) $log['user_email']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Sistema/Anónimo</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h((string) $log['description']) ?></td>
                            <td><pre class="small mb-0 text-wrap"><?= h((string) ($log['context_json'] ?? '')) ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php $query = http_build_query(array_merge($queryBase, ['page' => $p])); ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= h($query) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
