<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $flashError = 'Apenas administradores podem editar os dados da empresa.';
    } elseif (($_POST['action'] ?? '') === 'save_company_profile') {
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $companyAddress = trim((string) ($_POST['company_address'] ?? ''));
        $companyEmail = trim((string) ($_POST['company_email'] ?? ''));
        $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));

        if ($companyEmail !== '' && filter_var($companyEmail, FILTER_VALIDATE_EMAIL) === false) {
            $flashError = 'Indique um email válido para a empresa.';
        } else {
            set_app_setting($pdo, 'company_name', $companyName);
            set_app_setting($pdo, 'company_address', $companyAddress);
            set_app_setting($pdo, 'company_email', $companyEmail);
            set_app_setting($pdo, 'company_phone', $companyPhone);

            $savedLogos = 0;
            $lightPath = save_brand_logo($_FILES['logo_navbar_light'] ?? [], 'navbar_light');
            if ($lightPath) {
                set_app_setting($pdo, 'logo_navbar_light', $lightPath);
                $savedLogos++;
            }

            $darkPath = save_brand_logo($_FILES['logo_report_dark'] ?? [], 'report_dark');
            if ($darkPath) {
                set_app_setting($pdo, 'logo_report_dark', $darkPath);
                $savedLogos++;
            }

            $flashSuccess = $savedLogos > 0
                ? 'Dados da empresa e logotipos atualizados com sucesso.'
                : 'Dados da empresa atualizados com sucesso.';
        }
    }
}

$companyName = app_setting($pdo, 'company_name', '');
$companyAddress = app_setting($pdo, 'company_address', '');
$companyEmail = app_setting($pdo, 'company_email', '');
$companyPhone = app_setting($pdo, 'company_phone', '');
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$reportLogo = app_setting($pdo, 'logo_report_dark');

$pageTitle = 'Empresa e Branding';
require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-3">Empresa e Branding</h1>
<p class="text-muted">Configure os dados corporativos para reutilizar nos relatórios e e-mails.</p>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card shadow-sm soft-card">
    <input type="hidden" name="action" value="save_company_profile">
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Nome da empresa</label>
                <input class="form-control" name="company_name" value="<?= h($companyName) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="company_email" value="<?= h($companyEmail) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Telefone</label>
                <input class="form-control" name="company_phone" value="<?= h($companyPhone) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Morada</label>
                <input class="form-control" name="company_address" value="<?= h($companyAddress) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>

            <div class="col-md-6">
                <label class="form-label mb-0">Logo claro (navbar)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="logo_navbar_light" accept="image/png,image/jpeg,image/svg+xml,image/webp" <?= !$isAdmin ? 'disabled' : '' ?>>
                <?php if ($navbarLogo): ?><img src="<?= h($navbarLogo) ?>" alt="Logo navbar" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label mb-0">Logo escuro (report)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="logo_report_dark" accept="image/png,image/jpeg,image/svg+xml,image/webp" <?= !$isAdmin ? 'disabled' : '' ?>>
                <?php if ($reportLogo): ?><img src="<?= h($reportLogo) ?>" alt="Logo report" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <button class="btn btn-primary mt-3">Guardar dados da empresa</button>
        <?php endif; ?>
    </div>
</form>
<?php require __DIR__ . '/partials/footer.php'; ?>
