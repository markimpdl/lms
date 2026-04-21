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

    // --- SMTP (Hostgator) ----------------------------------------------
    'SMTP_HOST'     => 'smtp.hostgator.com.br',
    'SMTP_PORT'     => 587,
    'SMTP_USER'     => 'naoresponda@exemplo.com',
    'SMTP_PASS'     => 'change-me',
    'SMTP_FROM'     => 'naoresponda@exemplo.com',
    'SMTP_FROM_NAME'=> 'LMS',
    'SMTP_SECURE'   => 'tls',              // tls | ssl

    // --- Judge0 (RapidAPI plano gratuito) ------------------------------
    'JUDGE0_HOST'   => 'judge0-ce.p.rapidapi.com',
    'JUDGE0_KEY'    => 'your-rapidapi-key',
];
