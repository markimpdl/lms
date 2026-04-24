<?php
declare(strict_types=1);

/**
 * Template de configuração do LMS.
 *
 * Copie este arquivo para `config/env.php` e preencha com os valores reais.
 * `config/env.php` é gitignored — nunca deve ser versionado.
 */

return [
    // --- Aplicação ------------------------------------------------------
    'APP_ENV'       => 'local',            // local | production
    'APP_DEBUG'     => true,               // false em produção
    'APP_HTTPS'     => false,              // true em produção (cookies Secure)
    'APP_BASE_URL'  => 'http://localhost:8000',
    'APP_TIMEZONE'  => 'Asia/Dubai',

    // --- Banco de dados (MySQL 8) --------------------------------------
    'DB_HOST'       => '127.0.0.1',
    'DB_PORT'       => 3306,
    'DB_NAME'       => 'lms',
    'DB_USER'       => 'lms_user',
    'DB_PASS'       => 'change-me',

    // --- SMTP ----------------------------------------------------------
    // Deixe SMTP_HOST vazio para cair no fallback de log (storage/logs/mail-debug.log).
    // Port 465 → SMTP_SECURE='ssl' (SMTPS, SSL implícito).
    // Port 587 → SMTP_SECURE='tls' (STARTTLS).
    'SMTP_HOST'     => '',
    'SMTP_PORT'     => 465,
    'SMTP_USER'     => '',
    'SMTP_PASS'     => '',
    'SMTP_FROM'     => 'naoresponda@exemplo.com',
    'SMTP_FROM_NAME'=> 'LMS',
    'SMTP_SECURE'   => 'ssl',              // ssl | tls

    // --- Judge0 (RapidAPI plano gratuito) ------------------------------
    'JUDGE0_HOST'   => 'judge0-ce.p.rapidapi.com',
    'JUDGE0_KEY'    => 'your-rapidapi-key',

    // --- Uploads -------------------------------------------------------
    // Teto em MB do PDF de enunciado da avaliação (ADR-028). Demais uploads
    // permanecem com teto fixo de 3 MB no storage service correspondente.
    'UPLOAD_MAX_MB_PDF_BRIEF' => 10,
];
