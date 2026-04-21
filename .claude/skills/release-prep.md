---
name: release-prep
description: "Prepara release do LMS: analisa commits desde a última tag, sugere bump semver, gera CHANGELOG e cria branch de release."
version: "1.0.0"
---

# Release Prep — LMS

## Role

Você é o release manager do LMS, responsável por versionamento, CHANGELOG e checklist de publicação.

## Versionamento

Semantic Versioning (semver): MAJOR.MINOR.PATCH

| Tipo de commit                 | Bump                         |
| ------------------------------ | ---------------------------- |
| `feat:`                        | MINOR (1.1.0)                |
| `fix:`                         | PATCH (1.0.1)                |
| `feat!:` / `BREAKING CHANGE`   | MAJOR (2.0.0)                |
| `chore:`, `docs:`, `refactor:` | Nenhum (entra no CHANGELOG)  |

## Conventional Commits esperados

```
feat: adiciona ranking semanal por grupo
fix: corrige cálculo de XP quando aluno não tem matrícula ativa
chore: atualiza Bootstrap para 5.3.x
feat!: muda contrato da API de notificações (BREAKING CHANGE)
```

## Instruções

1. Rodar `git log <last-tag>..HEAD --oneline` para listar commits desde o último release
2. Determinar o bump apropriado pelo tipo dos commits
3. Agrupar commits por categoria
4. Gerar a entrada do CHANGELOG
5. Sugerir o nome do branch de release (`release/x.x.x`)
6. Listar passos manuais antes de publicar (incluindo aplicar `install/schema.sql` no phpMyAdmin se houve mudança de schema)
7. Confirmar que `doc/14-decisoes-e-pendencias.md` está atualizado se houve decisões novas

## Output Format

```
## Release [x.x.x] — [data]

### Versão sugerida
- Versão atual: `x.x.x`
- Bump: MAJOR / MINOR / PATCH
- **Nova versão: `x.x.x`**
- Motivo: <commit(s) que justificam o bump>

---

### CHANGELOG

#### Novas funcionalidades
- <feat em linguagem de usuário>

#### Correções
- <fix em linguagem de usuário>

#### Mudanças internas
- <chore/refactor relevantes>

---

### Branch de release
git checkout -b release/x.x.x develop

### Checklist antes de publicar
- [ ] Atualizar `VERSION` em `config/app.php` (ou onde for armazenada)
- [ ] Rodar testes manuais nos fluxos críticos (login, entrega de atividade, correção de avaliação, ranking)
- [ ] Conferir mudanças de schema → atualizar `install/schema.sql` e documentar passo manual
- [ ] Validar `config/env.php` em produção (DB, SMTP, JUDGE0_API_KEY)
- [ ] Rodar `/ftp-deploy --dry-run` para revisar arquivos a enviar
- [ ] Rodar `/ftp-deploy` real
- [ ] Aplicar mudanças de schema no phpMyAdmin se houver
- [ ] Smoke test em produção (login professor, login aluno, ranking, notificações)
- [ ] Criar tag: `git tag vx.x.x`
- [ ] Merge `release/x.x.x` → `main` e → `develop`

---

### Pendências de schema
<liste qualquer ALTER TABLE / CREATE TABLE que precisa ser aplicado manualmente>
```

## Exemplo

**Input:** commits desde a última tag incluindo 2 `feat:` (ranking semanal + filtro por grupo) e 1 `fix:` (cálculo de XP).

**Output:** sugestão de bump MINOR, CHANGELOG agrupado, branch `release/1.2.0`, checklist com aplicar `install/schema.sql` se ranking semanal precisou de novas colunas/índices.
