# LMS — Multi-Tenant Learning Management System

A lightweight, multi-tenant Learning Management System built in PHP 8.3 + MySQL 8 to support in-person programming courses with digital deliverables, structured feedback, progress tracking, and gamified rankings.

Originally built for programming courses in the United Arab Emirates, the platform is designed as a **mini-SaaS**: each teacher operates an isolated workspace (tenant), and a super-admin manages teacher accounts.

> **Status:** v0.30.0 — production deployment running on Hostinger shared hosting (`lms.rumo.info`).

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Architecture](#architecture)
- [Repository Layout](#repository-layout)
- [Local Development](#local-development)
- [Production Deployment](#production-deployment)
- [Configuration Reference](#configuration-reference)
- [Cron Jobs](#cron-jobs)
- [Documentation](#documentation)
- [License](#license)

---

## Features

### For students
- Mobile-first responsive UI (Bootstrap 5).
- Submit activities (quiz, research, form, project, code) and evaluations (graded 0–10).
- Receive structured feedback per submission, with optional retake when allowed.
- Live XP and ranking (overall, last 7 days, last 30 days; by group and calendar year).
- "Online now" presence indicator on the leaderboard.
- In-app notifications (bell) plus email digests.
- Optional online code execution via Judge0 (Python, C#, JavaScript).

### For teachers
- Isolated workspace per teacher (multi-tenant by column — `tenant_id`).
- Course → Core Competence → Competence Unit hierarchy, with rich content (TinyMCE 6) and embedded YouTube/Vimeo.
- Activities and per-CU evaluation with PDF brief upload.
- Student/group management with bulk operations.
- Manual grading workflow with reusable feedback snippets.
- Reports (PDF via mPDF) and per-student progress dashboards.
- Customizable platform name, logo, and avatar style per tenant.

### For the super-admin
- Manage teacher accounts and tenants.
- Toggle public registration globally.
- View aggregated platform metrics.

### Platform features
- Bilingual UI (Portuguese / English) via `__t('dot.notation')` lookups.
- CSRF protection on every form, prepared statements everywhere, HTML Purifier on rich content, bcrypt password hashing (cost 12).
- HttpOnly + Secure + SameSite=Lax session cookies.
- Audit-friendly login history and stale session cleanup.
- Idempotent SQL installer — safe to run twice.

---

## Tech Stack

| Layer        | Technology                                                                 |
| ------------ | -------------------------------------------------------------------------- |
| Backend      | PHP **8.3** (no framework), PDO with prepared statements                   |
| Database     | MySQL 8 / MariaDB 10.6+ (InnoDB + utf8mb4)                                 |
| Frontend     | HTML5, Bootstrap 5, Alpine.js                                              |
| Rich editor  | TinyMCE 6 (Community)                                                      |
| Code editor  | CodeMirror 6                                                               |
| Code runner  | Judge0 (via RapidAPI)                                                      |
| Mail         | PHPMailer over SMTP                                                        |
| PDF          | mPDF 8.3                                                                   |
| HTML sanitiz.| ezyang/htmlpurifier                                                        |
| Hosting      | Hostinger reseller plan (cPanel / hPanel) — Apache/LiteSpeed               |
| Deploy       | Incremental FTPS via `basic-ftp` (Node.js script)                          |

---

## Architecture

Classic server-rendered PHP application — no SPA, no build step on the server.

```
Tenant (Teacher)
└── Course
    └── Core Competence
        └── Competence Unit (subject)
            ├── Content (HTML page)
            ├── Activity (0..N)
            └── Evaluation (0..1)
```

- **Multi-tenancy by column.** Every tenant-scoped table carries `tenant_id`. Teacher queries always filter by `current_tenant_id()`.
- **Students belong to a single tenant** but may enroll in courses from other teachers via independent enrollments (ADR-026).
- **Sessions** use native PHP sessions; uploads live under `storage/uploads/` (outside the web root where possible) and are served through a PHP proxy.
- **No queues, no Redis, no Docker.** Background work runs as cPanel cron jobs.

---

## Repository Layout

```
lms/
├── public/                   web document root
│   ├── index.php             front controller
│   ├── assets/               CSS / JS / images
│   └── uploads/              created in production (gitignored)
├── src/
│   ├── bootstrap.php         loads env, autoloads, sessions
│   ├── routes.php            URL routing
│   ├── pages/                page handlers
│   ├── controllers/
│   ├── models/               one file per table
│   ├── services/             business logic
│   ├── lib/                  Database, Auth, Mailer, Judge0, ...
│   ├── templates/            layouts and partials
│   └── helpers.php           __t(), e(), csrf_*, require_auth()
├── lang/                     pt.php / en.php translation maps
├── config/
│   ├── env.example.php       template — copy to env.php
│   └── env.php               (gitignored — local secrets)
├── install/
│   ├── schema.sql            full schema + seeds (run once in phpMyAdmin)
│   └── seed-admin.php        rotates the super-admin password
├── scripts/
│   ├── cron/                 cPanel cron entry points
│   └── deploy/               FTPS deploy (Node.js)
├── storage/                  logs, cache, generated reports (gitignored)
├── doc/                      design docs, ADRs, roadmap
├── composer.json             PHP dependencies
└── package.json              Node tooling for deploy
```

---

## Local Development

### Prerequisites

- PHP **8.3+** with extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `intl`, `gd`, `curl`.
- MySQL 8 (or MariaDB 10.6+).
- Composer 2.
- Node.js 18+ (only required to run the deploy script).

XAMPP, Laragon, or WAMP all work on Windows. macOS/Linux: Homebrew/apt + a local MySQL.

### 1. Clone and install dependencies

```bash
git clone https://github.com/markimpdl/lms.git
cd lms
composer install
npm install      # only needed for the deploy script
```

### 2. Create the database

In phpMyAdmin (or any MySQL client):

```sql
CREATE DATABASE lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lms_user'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON lms.* TO 'lms_user'@'localhost';
FLUSH PRIVILEGES;
```

Then run the installer SQL — open `install/schema.sql` and execute it against the new database. The script is idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`).

### 3. Configure the application

```bash
cp config/env.example.php config/env.php
```

Edit `config/env.php` and fill in:

- `APP_BASE_URL` — e.g. `http://localhost:8000`
- `DB_*` — credentials from step 2
- `SMTP_*` — leave `SMTP_HOST` empty to log mail to `storage/logs/mail-debug.log`
- `JUDGE0_KEY` — your RapidAPI key (optional; only required for code execution)

### 4. Seed the super-admin

```bash
php install/seed-admin.php
```

The script prints a freshly generated password for `admin@lms.local`. **Save it** — re-running rotates the password.

### 5. Serve the app

Point your web server at `public/` as the document root, or use PHP's built-in server:

```bash
php -S localhost:8000 -t public
```

Open `http://localhost:8000` and log in as `admin@lms.local`.

---

## Production Deployment

The project targets **Hostinger** (shared hosting) but works on any LAMP-style host with PHP 8.3 and MySQL 8.

### One-time setup on the host

1. **Create the database** in cPanel/hPanel and note the credentials.
2. **Run `install/schema.sql`** in phpMyAdmin against that database.
3. **Upload `config/env.php`** with production values (set `APP_ENV=production`, `APP_DEBUG=false`, `APP_HTTPS=true`, real DB / SMTP / Judge0 credentials). The deploy helper has a dedicated command for this:
   ```bash
   npm run upload-env
   ```
4. **Point the domain** at the `public/` folder (Hostinger's hPanel lets you set the document root per domain).
5. **Run the super-admin seed** once via SSH or a one-shot `php install/seed-admin.php` execution, then capture the password.

### Incremental FTPS deploy

The repo ships with a Node script that uploads only changed files since the last successful deploy.

1. Copy the deploy credentials template:
   ```bash
   cp scripts/deploy/.env.deploy.example .env.deploy
   ```
2. Fill `.env.deploy`:
   ```
   FTP_HOST=ftp.your-domain.com
   FTP_USER=your-ftp-user
   FTP_PASSWORD=your-ftp-password
   FTP_SECURE=true
   FTP_REMOTE_ROOT=/        # or /public_html on classic cPanel
   ```
3. Deploy:
   ```bash
   npm run deploy           # incremental upload
   npm run deploy:dry       # preview without uploading
   npm run deploy:force     # ignore the local state file and re-upload everything
   ```

The script keeps state in `scripts/deploy/.ftp-state.json` (gitignored). The `vendor/` folder ships with the deploy.

### Hostinger-specific notes

- PHP version is selected per domain in hPanel (set to **8.3**).
- MySQL is actually **MariaDB 10.11** on Hostinger — fully compatible with the schema.
- No SSH on most plans — install steps run via phpMyAdmin and one-shot web scripts (placed under `public/_diag_*.php`, gitignored).
- mPDF's `vendor/` directory is large (~94 MB); the deploy script excludes generated caches but keeps the library.

---

## Configuration Reference

All runtime configuration lives in `config/env.php` (see `config/env.example.php` for the full template).

| Key                       | Purpose                                                    |
| ------------------------- | ---------------------------------------------------------- |
| `APP_ENV`                 | `local` or `production` — toggles error display            |
| `APP_DEBUG`               | `false` in production                                      |
| `APP_HTTPS`               | `true` in production (sets `Secure` cookies)               |
| `APP_BASE_URL`            | Public base URL (no trailing slash)                        |
| `APP_TIMEZONE`            | IANA timezone, defaults to `Asia/Dubai`                    |
| `DB_*`                    | MySQL host / port / name / user / pass                     |
| `SMTP_*`                  | Outbound mail; empty `SMTP_HOST` falls back to file logging|
| `JUDGE0_HOST` / `KEY`     | RapidAPI host and key for online code execution            |
| `UPLOAD_MAX_MB_PDF_BRIEF` | PDF brief size limit (default 12 MB)                       |

Other upload limits are hardcoded in `src/services/*Storage.php` — student submissions: 10 MB; TinyMCE attachments: 12 MB.

**Never commit** `config/env.php`, `.env.deploy`, or `.mcp.json` — all three are listed in `.gitignore`.

---

## Cron Jobs

Schedule these in cPanel → Advanced → Cron Jobs, pointing at the host's PHP CLI:

| Script                                    | Frequency        | Purpose                                                          |
| ----------------------------------------- | ---------------- | ---------------------------------------------------------------- |
| `scripts/cron/purge-old-logins.php`       | Daily 03:00 UTC  | Deletes `user_logins` rows older than 180 days.                  |
| `scripts/cron/close-stale-sessions.php`   | Every 1 minute   | Closes student sessions idle for more than 3 minutes.            |

Example cron entry:

```
php /home/USER/public_html/scripts/cron/close-stale-sessions.php >> /home/USER/public_html/storage/logs/cron-close-sessions.log 2>&1
```

Each script is idempotent and self-contained (loads `src/bootstrap.php`).

---

## Documentation

The `doc/` folder contains the complete design specification, ADRs (architectural decision records), and roadmap. Start with:

- [`doc/README.md`](doc/README.md) — index
- [`doc/00-visao-geral.md`](doc/00-visao-geral.md) — vision and personas
- [`doc/03-dominio-e-hierarquia.md`](doc/03-dominio-e-hierarquia.md) — domain model
- [`doc/12-modelo-de-dados.md`](doc/12-modelo-de-dados.md) — MySQL schema reference
- [`doc/14-decisoes-e-pendencias.md`](doc/14-decisoes-e-pendencias.md) — ADRs

The full version history lives in [`CHANGELOG.md`](CHANGELOG.md).

> Documentation is written in Portuguese (the primary working language of the project). Code, identifiers, and user-facing strings are bilingual via `lang/pt.php` and `lang/en.php`.

---

## License

Proprietary. All rights reserved.

This repository is published for transparency and portfolio purposes; no license to use, copy, modify, or distribute the code is granted unless explicitly agreed in writing with the author.
