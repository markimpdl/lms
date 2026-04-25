<?php
declare(strict_types=1);

/**
 * /teacher/settings/notifications — config de notificações por tenant
 * (E21-03). Matriz 8 eventos × 2 canais = 16 toggles. Default ON quando
 * tenant não tem rows.
 *
 * Auth + role pelo front controller. Tenant via `current_tenant_id()`.
 * Submit POST persiste 16 rows via REPLACE INTO (NotificationSetting::saveBulk).
 *
 * Render único com CSS Grid: desktop usa 3 colunas (label / sino / email);
 * mobile (<768px) empilha em 1 coluna. NUNCA renderizar 2 sets de inputs
 * com `name` igual — checkbox oculto por `display:none` ainda submete o
 * estado inicial e ignora a interação do usuário no set visível.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/settings/notifications', true, 303);
        exit;
    }

    // Form com checkboxes: chave ausente = unchecked. Itera sobre a
    // whitelist pra construir payload completo (16 entries).
    $raw     = $_POST['notifications'] ?? [];
    $payload = [];
    foreach (NotificationService::EVENTS as $event) {
        $payload[$event] = [
            'bell'  => is_array($raw) && !empty($raw[$event]['bell']),
            'email' => is_array($raw) && !empty($raw[$event]['email']),
        ];
    }

    NotificationSetting::saveBulk($tenantId, $payload);
    flash('success', __t('notifications.settings.saved'));
    header('Location: /teacher/settings/notifications', true, 303);
    exit;
}

$settings = NotificationSetting::getAllForTenant($tenantId);

$page_title = __t('notifications.settings.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
        <h1 class="h4 mb-1"><?= e(__t('notifications.settings.title')) ?></h1>
        <p class="text-muted small mb-3"><?= e(__t('notifications.settings.subtitle')) ?></p>

        <form method="POST" action="/teacher/settings/notifications" class="card card-body shadow-sm" novalidate>
            <?= csrf_field() ?>

            <div class="lms-notif-matrix">
                <div class="lms-notif-matrix__header">
                    <span class="lms-notif-matrix__col-event">
                        <?= e(__t('notifications.settings.event_col')) ?>
                    </span>
                    <span class="lms-notif-matrix__col-channel">
                        <?= e(__t('notification.channel.bell')) ?>
                    </span>
                    <span class="lms-notif-matrix__col-channel">
                        <?= e(__t('notification.channel.email')) ?>
                    </span>
                </div>

                <?php foreach (NotificationService::EVENTS as $event): ?>
                    <?php
                        $bellChecked  = $settings[$event]['bell'];
                        $emailChecked = $settings[$event]['email'];
                        $bellId       = 'notif_' . $event . '_bell';
                        $emailId      = 'notif_' . $event . '_email';
                        $eventLabel   = __t('notification.event.' . $event);
                    ?>
                    <div class="lms-notif-matrix__row">
                        <div class="lms-notif-matrix__label"><?= e($eventLabel) ?></div>

                        <label class="lms-notif-matrix__cell" for="<?= e($bellId) ?>">
                            <span class="lms-notif-matrix__cell-label">
                                <?= e(__t('notification.channel.bell')) ?>
                            </span>
                            <input type="checkbox"
                                   id="<?= e($bellId) ?>"
                                   name="notifications[<?= e($event) ?>][bell]"
                                   value="1"
                                   class="form-check-input"
                                   <?= $bellChecked ? 'checked' : '' ?>>
                        </label>

                        <label class="lms-notif-matrix__cell" for="<?= e($emailId) ?>">
                            <span class="lms-notif-matrix__cell-label">
                                <?= e(__t('notification.channel.email')) ?>
                            </span>
                            <input type="checkbox"
                                   id="<?= e($emailId) ?>"
                                   name="notifications[<?= e($event) ?>][email]"
                                   value="1"
                                   class="form-check-input"
                                   <?= $emailChecked ? 'checked' : '' ?>>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
                <a href="/teacher/settings" class="btn btn-outline-secondary">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
