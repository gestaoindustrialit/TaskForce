<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_raffle_prizes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_number INTEGER NOT NULL,
        title TEXT NOT NULL,
        image_path TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        removed_at DATETIME,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_raffle_draws (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        selected_group INTEGER NOT NULL,
        winning_group INTEGER NOT NULL,
        prize_id INTEGER,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(prize_id) REFERENCES hr_raffle_prizes(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$flashSuccess = null;
$flashError = null;
$resultPayload = null;

function raffle_pick_group(array $weights): int
{
    $roll = mt_rand(1, 100);
    $running = 0;
    foreach ([1, 2, 3] as $group) {
        $running += (int) ($weights[$group] ?? 0);
        if ($roll <= $running) {
            return $group;
        }
    }

    return 1;
}

function raffle_upload_image(array $file): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'A imagem excede o limite do servidor (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'A imagem excede o limite permitido pelo formulário.',
            UPLOAD_ERR_PARTIAL => 'O upload foi parcial. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Selecione uma imagem antes de adicionar o prémio.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor não tem diretório temporário para upload.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o ficheiro no disco.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP bloqueou o upload da imagem.',
        ];
        return [
            'ok' => false,
            'path' => null,
            'error' => $uploadErrors[$errorCode] ?? ('Falha no upload da imagem (código ' . $errorCode . ').'),
        ];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '') {
        return [
            'ok' => false,
            'path' => null,
            'error' => 'Não foi possível ler o ficheiro enviado.',
        ];
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = @finfo_file($finfo, $tmpName);
            if (is_string($detected)) {
                $mimeType = trim($detected);
            }
            @finfo_close($finfo);
        }
    }
    if ($mimeType === '' && function_exists('mime_content_type')) {
        $detected = @mime_content_type($tmpName);
        if (is_string($detected)) {
            $mimeType = trim($detected);
        }
    }

    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $allowedExtensions = [
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
    ];

    $normalizedExtension = $allowedExtensions[$extension] ?? '';
    $normalizedFromMime = $allowedMimes[$mimeType] ?? '';
    $normalized = $normalizedFromMime !== '' ? $normalizedFromMime : $normalizedExtension;

    if ($normalized === '') {
        return [
            'ok' => false,
            'path' => null,
            'error' => 'Formato inválido. Use JPG, JPEG, PNG ou WEBP.',
        ];
    }

    $imageInfo = @getimagesize($tmpName);
    if (!is_array($imageInfo) || empty($imageInfo['mime']) || !array_key_exists((string) $imageInfo['mime'], $allowedMimes)) {
        return [
            'ok' => false,
            'path' => null,
            'error' => 'O ficheiro enviado não é uma imagem válida.',
        ];
    }

    $uploadDir = __DIR__ . '/uploads/hr_raffle';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return [
                'ok' => false,
                'path' => null,
                'error' => 'Não foi possível criar a pasta de uploads do sorteio.',
            ];
        }
    }

    $safeName = 'raffle_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $normalized;
    $targetPath = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        return [
            'ok' => false,
            'path' => null,
            'error' => 'Falha ao gravar a imagem no servidor.',
        ];
    }

    return [
        'ok' => true,
        'path' => 'uploads/hr_raffle/' . $safeName,
        'error' => null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort(false);
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_prize') {
        $groupNumber = (int) ($_POST['group_number'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $uploadResult = raffle_upload_image($_FILES['prize_image'] ?? []);

        if (!in_array($groupNumber, [1, 2, 3], true)) {
            $flashError = 'Grupo inválido para o prémio.';
        } elseif ($title === '') {
            $flashError = 'Indique o nome do prémio.';
        } elseif (!(bool) ($uploadResult['ok'] ?? false)) {
            $flashError = (string) ($uploadResult['error'] ?? 'Falha ao enviar a imagem.');
        } else {
            $imagePath = (string) ($uploadResult['path'] ?? '');
            $insertPrizeStmt = $pdo->prepare('INSERT INTO hr_raffle_prizes(group_number, title, image_path, created_by) VALUES (?, ?, ?, ?)');
            $insertPrizeStmt->execute([$groupNumber, $title, $imagePath, $userId]);
            $newPrizeId = (int) $pdo->lastInsertId();
            log_app_event($pdo, $userId, 'hr.raffle.prize.create', 'Novo prémio adicionado ao sorteio RH.', [
                'prize_id' => $newPrizeId,
                'group_number' => $groupNumber,
                'title' => $title,
            ]);
            $flashSuccess = 'Prémio adicionado com sucesso.';
        }
    }

    if ($action === 'remove_prize') {
        $prizeId = (int) ($_POST['prize_id'] ?? 0);
        if ($prizeId <= 0) {
            $flashError = 'Prémio inválido.';
        } else {
            $prizeStmt = $pdo->prepare('SELECT id, image_path, title FROM hr_raffle_prizes WHERE id = ? AND is_active = 1 LIMIT 1');
            $prizeStmt->execute([$prizeId]);
            $prize = $prizeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$prize) {
                $flashError = 'Prémio não encontrado ou já removido.';
            } else {
                $pdo->prepare('UPDATE hr_raffle_prizes SET is_active = 0, removed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$prizeId]);
                log_app_event($pdo, $userId, 'hr.raffle.prize.remove', 'Prémio removido do sorteio RH.', [
                    'prize_id' => $prizeId,
                    'title' => (string) ($prize['title'] ?? ''),
                ]);
                $flashSuccess = 'Prémio removido com sucesso.';
            }
        }
    }

    if ($action === 'run_draw') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $selectedGroup = (int) ($_POST['selected_group'] ?? 0);

        if ($targetUserId <= 0) {
            $flashError = 'Selecione um colaborador para o sorteio.';
        } elseif (!in_array($selectedGroup, [1, 2, 3], true)) {
            $flashError = 'Selecione um grupo válido.';
        } else {
            $targetUserStmt = $pdo->prepare('SELECT id, name, user_number FROM users WHERE id = ? LIMIT 1');
            $targetUserStmt->execute([$targetUserId]);
            $targetUser = $targetUserStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $flashError = 'Colaborador não encontrado.';
            } else {
                $weightsByChoice = [
                    1 => [1 => 88, 2 => 8, 3 => 4],
                    2 => [1 => 10, 2 => 82, 3 => 8],
                    3 => [1 => 7, 2 => 13, 3 => 80],
                ];
                $winningGroup = raffle_pick_group($weightsByChoice[$selectedGroup]);

                $prizesStmt = $pdo->prepare('SELECT id, title, image_path FROM hr_raffle_prizes WHERE is_active = 1 AND group_number = ? ORDER BY RANDOM()');
                $prizesStmt->execute([$winningGroup]);
                $winningPrize = $prizesStmt->fetch(PDO::FETCH_ASSOC);

                if (!$winningPrize) {
                    $flashError = 'Não existem prémios ativos no grupo sorteado. Adicione pelo menos um prémio ao grupo ' . $winningGroup . '.';
                } else {
                    $drawStmt = $pdo->prepare('INSERT INTO hr_raffle_draws(user_id, selected_group, winning_group, prize_id, created_by) VALUES (?, ?, ?, ?, ?)');
                    $drawStmt->execute([$targetUserId, $selectedGroup, $winningGroup, (int) $winningPrize['id'], $userId]);
                    $drawId = (int) $pdo->lastInsertId();

                    $userLabel = trim(((string) ($targetUser['user_number'] ?? '')) . ' · ' . ((string) ($targetUser['name'] ?? '')), ' ·');
                    log_app_event($pdo, $targetUserId, 'hr.raffle.draw', 'Sorteio registado no histórico do colaborador.', [
                        'draw_id' => $drawId,
                        'selected_group' => $selectedGroup,
                        'winning_group' => $winningGroup,
                        'prize_id' => (int) $winningPrize['id'],
                        'prize_title' => (string) ($winningPrize['title'] ?? ''),
                        'run_by' => $userId,
                    ]);
                    log_app_event($pdo, $userId, 'hr.raffle.draw.admin', 'Sorteio executado no painel RH.', [
                        'draw_id' => $drawId,
                        'target_user_id' => $targetUserId,
                        'target_user' => $userLabel,
                        'selected_group' => $selectedGroup,
                        'winning_group' => $winningGroup,
                    ]);

                    $resultPayload = [
                        'user_label' => $userLabel,
                        'selected_group' => $selectedGroup,
                        'winning_group' => $winningGroup,
                        'prize_title' => (string) ($winningPrize['title'] ?? ''),
                        'image_path' => (string) ($winningPrize['image_path'] ?? ''),
                    ];
                    $flashSuccess = 'Sorteio concluído e histórico do colaborador atualizado.';
                }
            }
        }
    }

    if ($action === 'delete_draw_record') {
        $drawId = (int) ($_POST['draw_id'] ?? 0);
        if ($drawId <= 0) {
            $flashError = 'Registo de sorteio inválido.';
        } else {
            $drawStmt = $pdo->prepare('SELECT id, user_id, prize_id FROM hr_raffle_draws WHERE id = ? LIMIT 1');
            $drawStmt->execute([$drawId]);
            $draw = $drawStmt->fetch(PDO::FETCH_ASSOC);

            if (!$draw) {
                $flashError = 'Registo de sorteio não encontrado.';
            } else {
                $pdo->prepare('DELETE FROM hr_raffle_draws WHERE id = ?')->execute([$drawId]);
                log_app_event($pdo, $userId, 'hr.raffle.draw.delete', 'Registo de sorteio apagado manualmente.', [
                    'draw_id' => $drawId,
                    'target_user_id' => (int) ($draw['user_id'] ?? 0),
                    'prize_id' => (int) ($draw['prize_id'] ?? 0),
                ]);
                $flashSuccess = 'Registo de sorteio apagado com sucesso.';
            }
        }
    }
}

$usersStmt = $pdo->query('SELECT id, name, user_number FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC');
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$prizesStmt = $pdo->query('SELECT p.*, u.name AS created_by_name FROM hr_raffle_prizes p LEFT JOIN users u ON u.id = p.created_by WHERE p.is_active = 1 ORDER BY p.group_number ASC, p.created_at DESC');
$activePrizes = $prizesStmt ? $prizesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$recentDrawsStmt = $pdo->query('SELECT d.*, target.name AS target_user_name, target.user_number AS target_user_number, p.title AS prize_title FROM hr_raffle_draws d LEFT JOIN users target ON target.id = d.user_id LEFT JOIN hr_raffle_prizes p ON p.id = d.prize_id ORDER BY d.created_at DESC, d.id DESC LIMIT 20');
$recentDraws = $recentDrawsStmt ? $recentDrawsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$prizesByGroup = [1 => [], 2 => [], 3 => []];
foreach ($activePrizes as $prize) {
    $group = (int) ($prize['group_number'] ?? 0);
    if (isset($prizesByGroup[$group])) {
        $prizesByGroup[$group][] = $prize;
    }
}
$allPrizeImages = [];
foreach ($activePrizes as $prize) {
    $path = trim((string) ($prize['image_path'] ?? ''));
    if ($path !== '') {
        $allPrizeImages[] = $path;
    }
}
$allPrizeImages = array_values(array_unique($allPrizeImages));
$groupPrizeCounts = [
    1 => count($prizesByGroup[1]),
    2 => count($prizesByGroup[2]),
    3 => count($prizesByGroup[3]),
];
$totalActivePrizes = $groupPrizeCounts[1] + $groupPrizeCounts[2] + $groupPrizeCounts[3];

$pageTitle = 'RH · Sorteio de Prémios';
$bodyClass = 'bg-light';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0 mb-2">&larr; Voltar ao painel RH</a>

<style>
    .raffle-prize-item.is-hidden { display: none; }
    .raffle-prize-image {
        max-height: 170px;
        object-fit: cover;
        width: 100%;
    }
    .raffle-roll-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        z-index: 3000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
    }
    .raffle-roll-overlay.is-open { display: flex; }
    .raffle-roll-card {
        width: min(560px, 100%);
        background: #fff;
        border-radius: 18px;
        padding: 18px;
        box-shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
        text-align: center;
    }
    .raffle-roll-image {
        width: 100%;
        max-height: 320px;
        object-fit: contain;
        border-radius: 12px;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        padding: 6px;
    }
    .raffle-roll-winner {
        display: none;
        margin-top: 14px;
        padding: 12px;
        border-radius: 12px;
        border: 1px solid #ffe69c;
        background: linear-gradient(135deg, #fff8db 0%, #fff1b5 100%);
        color: #5c4400;
        font-weight: 700;
        animation: raffle-pulse 1.2s ease-in-out infinite;
    }
    .raffle-roll-winner.is-visible { display: block; }
    .raffle-prize-modal .modal-dialog {
        max-width: min(1280px, 95vw);
    }
    .raffle-prize-modal .modal-body {
        max-height: calc(100vh - 170px);
        overflow-y: auto;
    }
    .raffle-fallback-confetti {
        position: fixed;
        width: 10px;
        height: 14px;
        top: -20px;
        opacity: 0.95;
        z-index: 3500;
        pointer-events: none;
        animation: raffle-confetti-drop 1300ms linear forwards;
    }
    @keyframes raffle-confetti-drop {
        0% { transform: translateY(0) rotate(0deg); opacity: 1; }
        100% { transform: translateY(95vh) rotate(540deg); opacity: 0; }
    }
    @keyframes raffle-pulse {
        0% { transform: scale(1); box-shadow: 0 0 0 rgba(255, 193, 7, 0); }
        50% { transform: scale(1.02); box-shadow: 0 6px 18px rgba(255, 193, 7, 0.35); }
        100% { transform: scale(1); box-shadow: 0 0 0 rgba(255, 193, 7, 0); }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

<section class="d-flex flex-column gap-3">
    <div class="soft-card p-3 p-lg-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div>
                <h1 class="h3 mb-1">Sorteio de prémios (RH)</h1>
                <p class="text-muted mb-0">Executa sorteios com probabilidades por grupo e gere imagens de prémios.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rafflePrizeModal">
                    Adicionar e gerir prémios
                </button>
            </div>
        </div>

        <?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="border rounded-3 p-2 bg-white">
                    <div class="small text-muted">Grupo 1</div>
                    <div class="h5 mb-0"><?= $groupPrizeCounts[1] ?> prémios</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded-3 p-2 bg-white">
                    <div class="small text-muted">Grupo 2</div>
                    <div class="h5 mb-0"><?= $groupPrizeCounts[2] ?> prémios</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded-3 p-2 bg-white">
                    <div class="small text-muted">Grupo 3</div>
                    <div class="h5 mb-0"><?= $groupPrizeCounts[3] ?> prémios</div>
                </div>
            </div>
            <div class="col-12">
                <div class="small text-muted">Total de prémios ativos: <strong><?= $totalActivePrizes ?></strong></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-5">
                <article class="border rounded-3 p-3 h-100 bg-white">
                    <h2 class="h5">Correr sorteio</h2>
                    <form id="runDrawForm" method="post" class="row g-2">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="run_draw">
                        <div class="col-12">
                            <label class="form-label">Pesquisar colaborador</label>
                            <input type="search" id="employeeSearchInput" class="form-control" placeholder="Escreve nome ou número...">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Colaborador</label>
                            <select id="targetUserSelect" name="target_user_id" class="form-select" required>
                                <option value="">Selecionar...</option>
                                <?php foreach ($users as $employee): ?>
                                    <?php $employeeLabel = trim(((string) ($employee['user_number'] ?? '')) . ' · ' . ((string) ($employee['name'] ?? '')), ' ·'); ?>
                                    <option value="<?= (int) ($employee['id'] ?? 0) ?>"><?= h($employeeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Grupo a privilegiar</label>
                            <select name="selected_group" class="form-select" required>
                                <option value="1">1 a 4 anos (88% no grupo 1)</option>
                                <option value="2">5 a 9 anos (82% no grupo 2)</option>
                                <option value="3">+10 anos (80% no grupo 3)</option>
                            </select>
                        </div>
                        <div class="col-12 d-grid mt-2">
                            <button type="submit" class="btn btn-warning fw-semibold">Sinto-me com sorte!</button>
                        </div>
                    </form>
                </article>
            </div>

            <div class="col-xl-7">
                <article class="border rounded-3 p-3 h-100 bg-white">
                    <h2 class="h5">Prémio da sessão atual</h2>
                    <div class="border rounded-3 p-3 bg-light-subtle" id="raffleResult">
                        <?php if ($resultPayload): ?>
                            <div id="raffleAnimatedResult"
                                data-final-image="<?= h((string) ($resultPayload['image_path'] ?? '')) ?>"
                                data-final-title="<?= h((string) ($resultPayload['prize_title'] ?? '')) ?>"
                                data-user-label="<?= h((string) ($resultPayload['user_label'] ?? '')) ?>"
                                data-selected-group="<?= (int) ($resultPayload['selected_group'] ?? 0) ?>"
                                data-winning-group="<?= (int) ($resultPayload['winning_group'] ?? 0) ?>">
                                <div class="fw-semibold mb-2">Resultado para <?= h((string) ($resultPayload['user_label'] ?? '')) ?></div>
                                <div class="small text-muted mb-2">Grupo escolhido: <?= (int) ($resultPayload['selected_group'] ?? 0) ?> · Grupo vencedor: <?= (int) ($resultPayload['winning_group'] ?? 0) ?></div>
                                <div id="raffleResultBadge" class="badge text-bg-dark mb-2"><?= h((string) ($resultPayload['prize_title'] ?? '')) ?></div>
                                <div><img id="raffleRollingImage" src="<?= h((string) ($resultPayload['image_path'] ?? '')) ?>" alt="Prémio sorteado" class="img-fluid rounded" style="max-height: 320px;"></div>
                            </div>
                        <?php else: ?>
                            <div class="text-muted">Sem sorteio executado nesta sessão.</div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </div>
    </div>

    <div class="soft-card p-3 p-lg-4">
        <h2 class="h5 mb-3">Últimos sorteios</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Colaborador</th>
                        <th>Grupo selecionado</th>
                        <th>Grupo vencedor</th>
                        <th>Prémio</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recentDraws): ?>
                        <tr><td colspan="6" class="text-muted">Sem sorteios registados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentDraws as $draw): ?>
                            <?php $drawUserLabel = trim(((string) ($draw['target_user_number'] ?? '')) . ' · ' . ((string) ($draw['target_user_name'] ?? '')), ' ·'); ?>
                            <tr>
                                <td><?= h((string) ($draw['created_at'] ?? '')) ?></td>
                                <td><?= h($drawUserLabel !== '' ? $drawUserLabel : '—') ?></td>
                                <td><?= (int) ($draw['selected_group'] ?? 0) ?></td>
                                <td><?= (int) ($draw['winning_group'] ?? 0) ?></td>
                                <td><?= h((string) ($draw['prize_title'] ?? '—')) ?></td>
                                <td class="text-end">
                                    <form method="post" onsubmit="return confirm('Apagar este registo de sorteio?');" class="d-inline">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="delete_draw_record">
                                        <input type="hidden" name="draw_id" value="<?= (int) ($draw['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-dark">Apagar registo</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade raffle-prize-modal" id="rafflePrizeModal" tabindex="-1" aria-labelledby="rafflePrizeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5 mb-0" id="rafflePrizeModalLabel">Adicionar e gerir prémios</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add_prize">
                    <div class="col-md-3">
                        <label class="form-label">Grupo</label>
                        <select name="group_number" class="form-select" required>
                            <option value="1">Grupo 1</option>
                            <option value="2">Grupo 2</option>
                            <option value="3">Grupo 3</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Nome do prémio</label>
                        <input type="text" name="title" class="form-control" maxlength="150" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Imagem do prémio</label>
                        <input type="file" name="prize_image" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <div class="col-12 d-grid d-md-flex justify-content-md-end mt-2">
                        <button type="submit" class="btn btn-primary">Adicionar prémio</button>
                    </div>
                </form>

                <hr>

                <div class="row g-3">
                    <?php foreach ([1, 2, 3] as $group): ?>
                        <?php $groupCount = count($prizesByGroup[$group]); ?>
                        <div class="col-lg-4">
                            <div class="border rounded-3 p-2 h-100 raffle-group-carousel" data-group="<?= $group ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h3 class="h6 mb-0">Grupo <?= $group ?></h3>
                                    <div class="d-flex align-items-center gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 raffle-prev" <?= $groupCount <= 1 ? 'disabled' : '' ?>>&larr;</button>
                                        <span class="small text-muted raffle-counter">0 / 0</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 raffle-next" <?= $groupCount <= 1 ? 'disabled' : '' ?>>&rarr;</button>
                                    </div>
                                </div>
                                <?php if (!$prizesByGroup[$group]): ?>
                                    <p class="text-muted small mb-0">Sem prémios ativos.</p>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-2 raffle-items">
                                        <?php foreach ($prizesByGroup[$group] as $index => $prize): ?>
                                            <div class="border rounded-2 p-2 raffle-prize-item<?= $index === 0 ? '' : ' is-hidden' ?>" data-index="<?= $index ?>">
                                                <img src="<?= h((string) ($prize['image_path'] ?? '')) ?>" alt="<?= h((string) ($prize['title'] ?? '')) ?>" class="img-fluid rounded mb-2 raffle-prize-image">
                                                <div class="small fw-semibold mb-2"><?= h((string) ($prize['title'] ?? '')) ?></div>
                                                <form method="post" class="d-grid">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="action" value="remove_prize">
                                                    <input type="hidden" name="prize_id" value="<?= (int) ($prize['id'] ?? 0) ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Remover</button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="raffleRollOverlay" class="raffle-roll-overlay" aria-hidden="true">
    <div class="raffle-roll-card">
        <h3 id="raffleRollHeading" class="h5 mb-2">A sortear prémio...</h3>
        <p id="raffleRollText" class="text-muted small mb-3">A percorrer prémios...</p>
        <img id="raffleRollImage" class="raffle-roll-image" alt="Pré-visualização do sorteio">
        <div id="raffleWinnerText" class="raffle-roll-winner" aria-live="polite"></div>
    </div>
</div>

<script>
document.querySelectorAll('.raffle-group-carousel').forEach(function (carousel) {
    const items = Array.from(carousel.querySelectorAll('.raffle-prize-item'));
    const prevButton = carousel.querySelector('.raffle-prev');
    const nextButton = carousel.querySelector('.raffle-next');
    const counter = carousel.querySelector('.raffle-counter');

    if (!items.length || !counter) {
        if (counter) {
            counter.textContent = '0 / 0';
        }
        return;
    }

    let currentIndex = 0;

    function render() {
        items.forEach(function (item, index) {
            item.classList.toggle('is-hidden', index !== currentIndex);
        });

        counter.textContent = (currentIndex + 1) + ' / ' + items.length;
    }

    if (prevButton) {
        prevButton.addEventListener('click', function () {
            currentIndex = (currentIndex - 1 + items.length) % items.length;
            render();
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', function () {
            currentIndex = (currentIndex + 1) % items.length;
            render();
        });
    }

    render();
});

const employeeSearchInput = document.getElementById('employeeSearchInput');
const targetUserSelect = document.getElementById('targetUserSelect');
if (employeeSearchInput && targetUserSelect) {
    employeeSearchInput.addEventListener('input', function () {
        const query = employeeSearchInput.value.trim().toLowerCase();
        Array.from(targetUserSelect.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            const matches = query === '' || option.text.toLowerCase().includes(query);
            option.hidden = !matches;
        });
    });
}

const animatedResult = document.getElementById('raffleAnimatedResult');
const allImages = <?= json_encode($allPrizeImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const runDrawForm = document.getElementById('runDrawForm');
const rollOverlay = document.getElementById('raffleRollOverlay');
const rollImage = document.getElementById('raffleRollImage');
const rollText = document.getElementById('raffleRollText');
const rollHeading = document.getElementById('raffleRollHeading');
const winnerText = document.getElementById('raffleWinnerText');

function fireCelebration() {
    if (typeof confetti === 'function') {
        confetti({
            particleCount: 160,
            spread: 75,
            origin: { y: 0.60 }
        });

        setTimeout(function () {
            confetti({
                particleCount: 80,
                spread: 100,
                origin: { y: 0.50 }
            });
        }, 250);
        return;
    }

    const colors = ['#ff4d6d', '#ffd166', '#06d6a0', '#118ab2', '#8338ec'];
    for (let i = 0; i < 70; i += 1) {
        const piece = document.createElement('span');
        piece.className = 'raffle-fallback-confetti';
        piece.style.left = Math.random() * 100 + 'vw';
        piece.style.background = colors[Math.floor(Math.random() * colors.length)];
        piece.style.animationDelay = (Math.random() * 220) + 'ms';
        document.body.appendChild(piece);
        window.setTimeout(function () { piece.remove(); }, 1600);
    }
}

if (runDrawForm && rollOverlay && rollImage) {
    runDrawForm.addEventListener('submit', function (event) {
        if (runDrawForm.dataset.skipAnimation === '1') {
            return;
        }
        if (!Array.isArray(allImages) || allImages.length === 0) {
            return;
        }

        event.preventDefault();
        runDrawForm.dataset.skipAnimation = '0';
        rollOverlay.classList.add('is-open');
        rollOverlay.setAttribute('aria-hidden', 'false');
        if (rollHeading) {
            rollHeading.textContent = 'A sortear prémio...';
        }
        if (winnerText) {
            winnerText.textContent = '';
            winnerText.classList.remove('is-visible');
        }

        let step = 0;
        const totalSteps = Math.max(8, allImages.length * 2);
        const spin = function () {
            const imageIndex = step % allImages.length;
            rollImage.src = allImages[imageIndex];

            step += 1;
            if (step < totalSteps) {
                const delay = Math.min(180, 90 + (step * 8));
                window.setTimeout(spin, delay);
                return;
            }
            if (rollText) {
                rollText.textContent = 'A validar resultado...';
            }
            window.setTimeout(function () {
                runDrawForm.dataset.skipAnimation = '1';
                runDrawForm.submit();
            }, 320);
        };

        spin();
    });
}

if (animatedResult) {
    const finalImage = animatedResult.dataset.finalImage || '';
    const finalTitle = animatedResult.dataset.finalTitle || '';
    const userLabel = animatedResult.dataset.userLabel || '';
    const finalWinningGroup = animatedResult.dataset.winningGroup || '';
    const rollingImage = document.getElementById('raffleRollingImage');
    const resultBadge = document.getElementById('raffleResultBadge');

    if (rollingImage) {
        rollingImage.src = finalImage;
    }
    if (resultBadge) {
        resultBadge.textContent = finalTitle + ' (Grupo ' + finalWinningGroup + ')';
    }

    if (rollOverlay && rollImage) {
        rollOverlay.classList.add('is-open');
        rollOverlay.setAttribute('aria-hidden', 'false');
        rollImage.src = finalImage;
        if (rollHeading) {
            rollHeading.textContent = '🎉 Resultado do sorteio';
        }
        if (rollText) {
            rollText.textContent = 'Sorteio concluído com sucesso.';
        }
        if (winnerText) {
            winnerText.textContent = 'Parabéns ' + userLabel + ', ganhou ' + finalTitle + '.';
            winnerText.classList.add('is-visible');
        }
    }

    fireCelebration();
    window.setTimeout(function () {
        if (!rollOverlay) {
            return;
        }
        rollOverlay.classList.remove('is-open');
        rollOverlay.setAttribute('aria-hidden', 'true');
    }, 4200);
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
