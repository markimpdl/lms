<?php
declare(strict_types=1);

/**
 * Raiz `/` — front door do LMS (E23-03 + E23-03 atualizado pra
 * redirect-only).
 *
 * Decisão consolidada (2026-04-26): zero homepage; raiz é apenas
 * roteador. Logado → dashboard do papel. Deslogado → /login.
 */

$user = current_user();
if ($user !== null) {
    header('Location: ' . AuthController::dashboardFor((string) $user['role']), true, 303);
    exit;
}

header('Location: /login', true, 303);
exit;
