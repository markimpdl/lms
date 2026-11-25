# Escola e trainer no aluno, com filtro no ranking — design

Data: 2026-11-25 · Tamanho: mudança pequena · Status: aprovado pelo PO

## Intenção

O professor quer registrar a **escola** de cada aluno e marcar se ele é **trainer**,
para poder filtrar o ranking por essas duas informações.

## Objetivos

- Campo "Escola" no cadastro do aluno: texto livre que sugere as escolas já
  usadas pelo professor (aprende com o uso).
- Marcação "É trainer" (sim/não) no cadastro do aluno.
- Ranking mostra a escola e o selo "Trainer" e permite filtrar por ambos.

## Fora do escopo

- Tela para mesclar escolas duplicadas ("Al Noor" × "Al-Noor").
- Importação em massa de escola/trainer.
- Ranking por curso (`RankingService::computeForCourse`).

## Abordagens consideradas

1. **Reaproveitar grupos** (um grupo por escola + grupo "Trainers"). Zero schema,
   mas não garante uma escola por aluno e mistura trainer com os demais grupos.
   Descartada.
2. **Colunas próprias em `users`** (escolhida). Uma escola por aluno, trainer
   como booleano, filtros dedicados.
3. Tabela `schools` com FK. Evita duplicata, mas o PO quer texto livre que
   aprende; peso desnecessário agora. Descartada.

## Design

### Banco (via `/mysql-schema`, schema idempotente)

- `users.school VARCHAR(150) NULL DEFAULT NULL`
- `users.is_trainer TINYINT(1) NOT NULL DEFAULT 0`

### Cadastro do aluno (`src/pages/teacher/students/new.php` e `show.php`)

- Input texto "Escola" com `<datalist>` alimentado pelas escolas distintas do
  tenant (`SELECT DISTINCT school ... WHERE tenant_id = :tid AND role='student'`).
- Ao salvar: `trim` + colapsa espaços internos; vazio vira `NULL`.
- Checkbox "É trainer".
- Auditar SELECTs de aluno que alimentam esses formulários (coluna nova).

### Ranking (`RankingService::compute` e `myPosition`, telas professor e aluno)

- Novos filtros normalizados: `school` (string, comparação case-insensitive —
  a collation `utf8mb4_unicode_ci` já garante) e `trainer` (`all|only|exclude`).
- Query string: `?school=...&trainer=only|exclude`. Escola só é aceita se
  estiver na lista de escolas do tenant (mesmo padrão do filtro de grupo).
- Linha do ranking ganha `school` e `is_trainer`; tela mostra selo "Trainer"
  ao lado do nome e coluna "Escola" (oculta no mobile, como "Grupos").
- Links de paginação/janela preservam os novos filtros.

### Visibilidade

Decisão do Claude (PO pode reverter): aluno também vê selo, coluna de escola e
filtros, porque já vê grupos e filtro de grupo.

### i18n

Chaves novas em `lang/pt.php` e `lang/en.php` (form, filtros, coluna, selo),
sincronizadas com `/i18n-sync`.

## Deixado ao implementador

Posição exata dos campos no form, estilo do selo (seguir componentes existentes
do ranking), nomes das chaves i18n.
