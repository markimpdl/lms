<?php
declare(strict_types=1);

/**
 * /teacher/settings — configurações globais do tenant do professor (E17-04).
 *
 * MVP: apenas estilo do avatar default (Árabe vs Ocidental). Mais opções
 * adicionadas futuramente (idioma padrão do tenant, branding, etc.).
 *
 * Auth + role pelo front controller. Tenant via `current_tenant_id()`.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$tenant = Tenant::findById($tenantId);
if ($tenant === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$errors       = [];
$style        = (string) $tenant['avatar_style'];
$isActvet     = (int) ($tenant['is_actvet'] ?? 0) === 1;
$platformName = (string) ($tenant['platform_name'] ?? '');
$logoBasename = (string) ($tenant['logo_path'] ?? '');
$whatsappRaw  = (string) ($tenant['whatsapp_number'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/settings');
        exit;
    }

    $form = (string) ($_POST['form'] ?? 'avatar');

    if ($form === 'avatar') {
        $newStyle = (string) ($_POST['avatar_style'] ?? '');
        if (!in_array($newStyle, ['arabe', 'ocidental'], true)) {
            $errors['avatar_style'] = 'settings.err.invalid_avatar_style';
        } else {
            Tenant::updateAvatarStyle($tenantId, $newStyle);
            flash('success', __t('settings.success'));
            header('Location: /teacher/settings', true, 303);
            exit;
        }
    } elseif ($form === 'branding') {
        // Nome: opcional, máx 60 chars (matches schema VARCHAR(60)).
        $newName = trim((string) ($_POST['platform_name'] ?? ''));
        if ($newName !== '' && mb_strlen($newName) > 60) {
            $errors['platform_name'] = 'teacher.settings.platform_name_too_long';
        }

        // WhatsApp: opcional. Aceita qualquer formatacao (+, espaco, hifen,
        // parenteses) e normaliza pra digits-only com country code. Range
        // E.164 minimo/maximo: 7-15 digitos. Vazio = remove.
        $whatsappInput  = (string) ($_POST['whatsapp_number'] ?? '');
        $whatsappDigits = preg_replace('/\D+/', '', $whatsappInput) ?? '';
        if ($whatsappDigits !== '') {
            $len = strlen($whatsappDigits);
            if ($len < 7 || $len > 15) {
                $errors['whatsapp_number'] = 'teacher.settings.whatsapp_invalid';
            }
        }

        // Logo: aceita só pra não-Actvet. Pra Actvet, força ignorar mesmo se
        // o user enviou via POST manual (DevTools) — defesa server-side.
        $newLogoBasename = $logoBasename;
        $logoFile        = $_FILES['logo'] ?? null;
        $hasUpload       = is_array($logoFile) && (int) ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasUpload && $errors === []) {
            if ($isActvet) {
                // Silenciosamente ignora — UI não exibe o campo, mas alguém
                // pode forçar via curl/POST manipulado. Não erra, só ignora.
                $hasUpload = false;
            } else {
                $result = LogoStorage::store($logoFile, $tenantId, $logoBasename === '' ? null : $logoBasename);
                if ($result['status'] === 'ok') {
                    $newLogoBasename = (string) $result['basename'];
                } else {
                    $errors['logo'] = (string) ($result['error_key'] ?? 'teacher.settings.logo_generic');
                }
            }
        }

        if ($errors === []) {
            Tenant::updateBranding(
                $tenantId,
                $newName === '' ? null : $newName,
                $newLogoBasename === '' ? null : $newLogoBasename
            );
            Tenant::updateWhatsapp($tenantId, $whatsappDigits === '' ? null : $whatsappDigits);
            flash('success', __t('settings.success'));
            header('Location: /teacher/settings', true, 303);
            exit;
        }

        // Em caso de erro, preserva o que o user digitou pra re-render.
        $platformName = $newName;
        $whatsappRaw  = $whatsappInput;
    } elseif ($form === 'delete_logo') {
        // Remove logo customizada (volta pro fallback do sistema). Defesa
        // server-side: Actvet nao tem logo custom (UI nao mostra o botao,
        // mas via DevTools alguem poderia forcar) — ignora silenciosamente.
        if (!$isActvet && $logoBasename !== '') {
            LogoStorage::deleteByBasename($logoBasename);
            Tenant::updateBranding($tenantId, $platformName === '' ? null : $platformName, null);
            flash('success', __t('teacher.settings.logo_deleted'));
        }
        header('Location: /teacher/settings', true, 303);
        exit;
    }
}

$page_title = __t('settings.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
        <header class="lms-dashboard-header">
            <div>
                <span class="lms-dashboard-eyebrow"><?= e(__t('teacher.settings.eyebrow')) ?></span>
                <h1 class="lms-dashboard-title"><?= e(__t('settings.title')) ?></h1>
            </div>
        </header>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="/teacher/settings/notifications" class="btn btn-outline-primary btn-sm">
                <?= e(__t('notifications.settings.link')) ?>
            </a>
        </div>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $errKey): ?>
                    <div><?= e(__t($errKey)) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/settings" class="card card-body shadow-sm mb-3" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="avatar">

            <h2 class="h6 mb-2"><?= e(__t('settings.avatar.title')) ?></h2>
            <p class="text-muted small mb-3"><?= e(__t('settings.avatar.help')) ?></p>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <label class="card h-100 p-3 d-flex flex-row align-items-center gap-3 <?= $style === 'arabe' ? 'border-primary' : '' ?>"
                           style="cursor: pointer;">
                        <input type="radio" name="avatar_style" value="arabe"
                               <?= $style === 'arabe' ? 'checked' : '' ?>
                               class="form-check-input mt-0">
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= e(__t('settings.avatar.style.arabe')) ?></div>
                            <div class="d-flex gap-2 mt-2">
                                <img src="/assets/avatars/arabe-male.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #EEF2FF;">
                                <img src="/assets/avatars/arabe-female.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #FCE7F3;">
                            </div>
                        </div>
                    </label>
                </div>

                <div class="col-12 col-md-6">
                    <label class="card h-100 p-3 d-flex flex-row align-items-center gap-3 <?= $style === 'ocidental' ? 'border-primary' : '' ?>"
                           style="cursor: pointer;">
                        <input type="radio" name="avatar_style" value="ocidental"
                               <?= $style === 'ocidental' ? 'checked' : '' ?>
                               class="form-check-input mt-0">
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= e(__t('settings.avatar.style.ocidental')) ?></div>
                            <div class="d-flex gap-2 mt-2">
                                <img src="/assets/avatars/ocidental-male.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #E0F2FE;">
                                <img src="/assets/avatars/ocidental-female.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #FFE4E6;">
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
                <a href="/teacher" class="btn btn-outline-secondary"><?= e(__t('common.cancel')) ?></a>
            </div>
        </form>

        <?php if (!$isActvet && $logoBasename !== ''): ?>
            <!-- Form oculto para o botão "Excluir logo" no preview abaixo
                 (HTML não permite forms aninhados; o botão usa form=... attr). -->
            <form id="logo-delete-form" method="POST" action="/teacher/settings" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="delete_logo">
            </form>
        <?php endif; ?>

        <form method="POST" action="/teacher/settings" enctype="multipart/form-data"
              class="card card-body shadow-sm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="branding">

            <h2 class="h6 mb-2"><?= e(__t('teacher.settings.branding_section')) ?></h2>
            <p class="text-muted small mb-3"><?= e(__t('teacher.settings.branding_help')) ?></p>

            <div class="mb-3">
                <label for="f-platform-name" class="form-label">
                    <?= e(__t('teacher.settings.platform_name_label')) ?>
                </label>
                <input type="text" name="platform_name" id="f-platform-name"
                       class="form-control<?= isset($errors['platform_name']) ? ' is-invalid' : '' ?>"
                       value="<?= e($platformName) ?>"
                       maxlength="60"
                       placeholder="<?= e(__t('teacher.settings.platform_name_placeholder')) ?>">
                <?php if (isset($errors['platform_name'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['platform_name'])) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($isActvet): ?>
                <div class="mb-3">
                    <label class="form-label"><?= e(__t('teacher.settings.logo_label')) ?></label>
                    <div class="d-flex align-items-center gap-3 p-2 border rounded bg-light">
                        <img src="/assets/logos/actvet.png" alt="Actvet"
                             style="height: 48px; width: auto;">
                        <span class="small text-muted"><?= e(__t('teacher.settings.logo_hint_actvet')) ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <label for="f-logo" class="form-label">
                        <?= e(__t('teacher.settings.logo_label')) ?>
                    </label>
                    <?php if ($logoBasename !== ''): ?>
                        <div class="mb-2 d-flex align-items-center gap-2 flex-wrap">
                            <div class="p-2 border rounded bg-light d-inline-block">
                                <img src="/uploads/logos/<?= e($logoBasename) ?>?v=<?= e((string) time()) ?>"
                                     alt="" style="height: 48px; width: auto;">
                            </div>
                            <button type="submit" form="logo-delete-form"
                                    class="btn btn-sm btn-outline-danger js-confirm"
                                    data-confirm="<?= e(__t('teacher.settings.logo_delete_confirm')) ?>">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                                <?= e(__t('teacher.settings.logo_delete_button')) ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="logo" id="f-logo"
                           accept="image/png,image/jpeg,image/svg+xml"
                           class="form-control<?= isset($errors['logo']) ? ' is-invalid' : '' ?>">
                    <div class="form-text"><?= e(__t('teacher.settings.logo_hint')) ?></div>
                    <?php if (isset($errors['logo'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['logo'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label for="f-whatsapp" class="form-label">
                    <?= e(__t('teacher.settings.whatsapp_label')) ?>
                </label>
                <input type="tel" name="whatsapp_number" id="f-whatsapp"
                       class="form-control<?= isset($errors['whatsapp_number']) ? ' is-invalid' : '' ?>"
                       value="<?= e($whatsappRaw) ?>"
                       maxlength="32"
                       inputmode="tel"
                       autocomplete="tel"
                       placeholder="<?= e(__t('teacher.settings.whatsapp_placeholder')) ?>">
                <div class="form-text"><?= e(__t('teacher.settings.whatsapp_hint')) ?></div>
                <?php if (isset($errors['whatsapp_number'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['whatsapp_number'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
                <a href="/teacher" class="btn btn-outline-secondary"><?= e(__t('common.cancel')) ?></a>
            </div>
        </form>
    </div>
</div>

<script>
// Confirm em ações destrutivas (excluir logo customizada).
document.querySelectorAll('button.js-confirm[data-confirm]').forEach(function (btn) {
    btn.addEventListener('click', function (event) {
        if (!window.confirm(btn.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
