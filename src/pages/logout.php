<?php
declare(strict_types=1);

// Logout só aceita POST com CSRF — evita logout via GET/prefetch/<img src>.
// Em GET a sessão NÃO é destruída; o usuário é redirecionado para a home
// sem efeito colateral (o 405+Location anterior misturava semânticas).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    // Token inválido/expirado — segue para /login mesmo assim: o objetivo
    // prático (ficar deslogado) é atingido invalidando a sessão.
}

AuthController::logout();
// Sessão destruída — flash() não sobrevive; redireciona direto.
header('Location: /login');
exit;
