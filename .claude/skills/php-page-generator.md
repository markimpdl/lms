---
name: php-page-generator
description: "Gera páginas PHP do LMS (PHP 8 sem framework) com auth, CSRF, multi-tenant, Bootstrap 5, i18n e mobile-first."
version: "1.0.0"
---

# LMS — Page Generator

## Role

Você é desenvolvedor PHP gerando páginas server-rendered do LMS. PHP 8.2+, Bootstrap 5, sessão nativa, multi-tenant, mobile-first, i18n PT/EN.

## Stack

- PHP 8.2+ (sem framework)
- HTML + Bootstrap 5 (via CDN)
- Sessão PHP nativa
- CSRF: token em sessão
- MySQL via `MySQL::pdo()` com prepared statements
- i18n via `__t('chave')` lendo `lang/pt.php` ou `lang/en.php`
- Helpers: `e()`, `csrf_field()`, `csrf_verify()`, `require_auth()`, `require_role()`, `current_tenant_id()`, `current_user_id()`, `flash()`

## Instruções

1. Toda página verifica auth no topo:
   - Páginas do professor: `require_role('teacher')`
   - Páginas do aluno: `require_role('student')`
   - Páginas do super-admin: `require_role('super_admin')`
2. Toda query do professor usa `current_tenant_id()` para filtrar
3. Toda query do aluno valida acesso (matrícula em curso, etc.)
4. Todo `<form method="POST">` tem `csrf_field()` e o handler chama `csrf_verify()`
5. Todo SQL via prepared statement
6. Toda string visível ao usuário usa `__t('chave')`
7. Toda saída dinâmica via `e($valor)`
8. Mensagens de sucesso/erro via Bootstrap alerts ou helper `flash()`
9. Layout responsivo (Bootstrap grid, breakpoint mobile-first)

## Estrutura

```
public/
└── index.php          ← front controller / router
src/
├── pages/
│   └── <page>.php     ← página gerada por esta skill
├── controllers/
├── services/
├── lib/
│   ├── Auth.php       ← session guards + CSRF + roles
│   ├── MySQL.php
│   └── Mailer.php
├── templates/
│   ├── layout.php     ← header + sidebar/navbar + footer
│   └── partials/
└── helpers.php        ← e(), __t(), flash(), csrf_*, etc.
lang/
├── pt.php
└── en.php
```

## Template — página do professor

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../models/Course.php';

require_role('teacher');

$tenantId = current_tenant_id();
$courseModel = new Course();

$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name = trim($_POST['name'] ?? '');
    $year = (int) ($_POST['year'] ?? 0);

    if ($name === '') {
        $errors[] = __t('err.course.name_required');
    }
    if ($year < 2000 || $year > 2100) {
        $errors[] = __t('err.course.year_invalid');
    }

    if (!$errors) {
        try {
            $courseModel->create($tenantId, [
                'name'        => $name,
                'description' => trim($_POST['description'] ?? ''),
                'year'        => $year,
                'language'    => in_array($_POST['language'] ?? '', ['pt', 'en'], true)
                    ? $_POST['language'] : 'pt',
            ]);
            flash('success', __t('flash.course.created'));
            header('Location: /courses.php');
            exit;
        } catch (Throwable $e) {
            error_log('Course create failed: ' . $e->getMessage());
            $errors[] = __t('err.generic');
        }
    }
}

$courses = $courseModel->listForTenant($tenantId, ['only_active' => true]);

$pageTitle = __t('course.list_title');
require __DIR__ . '/../templates/layout.php';
```

## Template — view (slot dentro do layout)

```php
<div class="container-fluid py-3 py-md-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <a href="/courses.php?action=new" class="btn btn-primary">
            <?= e(__t('action.new')) ?>
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$courses): ?>
        <div class="text-center py-5">
            <p class="text-muted"><?= e(__t('course.empty')) ?></p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($courses as $course): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><?= e($course['name']) ?></h5>
                            <p class="card-subtitle text-muted small mb-2"><?= e((string) $course['year']) ?></p>
                            <p class="card-text"><?= e($course['description'] ?? '') ?></p>
                            <a href="/courses.php?id=<?= (int) $course['id'] ?>" class="btn btn-outline-primary btn-sm">
                                <?= e(__t('action.open')) ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
```

## Template — página do aluno

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../models/StudentContent.php';

require_role('student');

$studentId = current_user_id();
$courseId  = (int) ($_GET['course'] ?? 0);

if ($courseId <= 0) {
    http_response_code(404);
    require __DIR__ . '/../templates/404.php';
    exit;
}

$content = new StudentContent();
$units   = $content->listForStudent($studentId, $courseId);

if (!$units) {
    // Sem matrícula ou curso inexistente — não diferenciar para evitar enumeração
    http_response_code(404);
    require __DIR__ . '/../templates/404.php';
    exit;
}

$pageTitle = $units[0]['course_name'];
require __DIR__ . '/../templates/layout.php';
```

## Form com upload (atividade/avaliação)

```html
<form method="POST" enctype="multipart/form-data" class="card p-3 p-md-4">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="file" class="form-label"><?= e(__t('submission.file_label')) ?></label>
        <input type="file" class="form-control" id="file" name="file"
               accept=".pdf,.zip,.txt" required>
        <div class="form-text"><?= e(__t('submission.file_hint')) ?></div>
    </div>

    <button type="submit" class="btn btn-primary">
        <?= e(__t('action.submit')) ?>
    </button>
</form>
```

Handler do upload (resumo):

```php
csrf_verify();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = __t('err.upload.failed');
} elseif ($_FILES['file']['size'] > 3 * 1024 * 1024) {
    $errors[] = __t('err.upload.too_large');
} else {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['file']['tmp_name']);
    $allowedMimes = ['application/pdf', 'application/zip', 'text/plain'];
    if (!in_array($mime, $allowedMimes, true)) {
        $errors[] = __t('err.upload.bad_type');
    } else {
        $ext  = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
        $dir  = __DIR__ . '/../../storage/uploads/tenant_' . $tenantId . '/activity/' . $activityId;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $name);
        // ...persistir registro com filename original + stored_path
    }
}
```

## Checklist (sempre)

- [ ] `declare(strict_types=1);` no topo
- [ ] `require_role('teacher'|'student'|'super_admin')` antes de qualquer lógica
- [ ] CSRF no form + `csrf_verify()` no handler
- [ ] `current_tenant_id()` ou validação de matrícula em toda query
- [ ] `e()` em toda saída dinâmica
- [ ] `__t()` em toda string visível
- [ ] Bootstrap responsivo (`col-12 col-md-*`)
- [ ] Estados loading/erro/vazio cobertos
- [ ] Não expõe `Throwable::getMessage()` ao usuário
- [ ] Uploads validados por mime real, tamanho 3 MB, extensão na allowlist

## Exemplo

**Input:** "Preciso da página `groups.php` para o professor cadastrar grupos."

**Output:**
- `src/pages/groups.php` com listagem + form de criação (CSRF, validação, i18n) usando `Group::listForTenant()` e `Group::create()`
- Chaves `group.list_title`, `group.empty`, `group.name_label`, `flash.group.created`, `err.group.name_required` adicionadas em `lang/pt.php` e `lang/en.php`
