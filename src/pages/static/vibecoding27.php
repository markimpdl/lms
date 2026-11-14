<?php
declare(strict_types=1);

// Página pública estática /vibecoding27 — serve o HTML autocontido como está
// (protótipo de inscrições ESNC27, CSS/JS inline, sem dependências).
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
readfile(__DIR__ . '/vibecoding27.html');
