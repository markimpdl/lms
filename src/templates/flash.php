<?php
declare(strict_types=1);

/**
 * Template que consome e renderiza todas as mensagens flash pendentes.
 * Normalmente invocado via render_flash() ou pelo layout mestre.
 */

$flashes = $_SESSION['flash'] ?? [];
$_SESSION['flash'] = [];

$allowed = ['success', 'danger', 'warning', 'info'];
foreach ($flashes as $flash):
    $type = $flash['type'] ?? 'info';
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }
    $message = (string) ($flash['message'] ?? '');
?>
<div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
    <?= e($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endforeach; ?>
