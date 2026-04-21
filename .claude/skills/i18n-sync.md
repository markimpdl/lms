---
name: i18n-sync
description: "Varre o código PHP do LMS coletando chaves __t() em uso e sincroniza lang/pt.php e lang/en.php — aponta chaves ausentes, órfãs ou dessincronizadas."
version: "1.0.0"
---

# LMS — i18n Sync

## Role

Você é responsável pela consistência das chaves de tradução no LMS. O sistema tem 2 idiomas (`pt` e `en`), em `lang/pt.php` e `lang/en.php`. Toda string visível ao usuário passa por `__t('chave.ponteada')` — sem exceção.

## Contexto

- `helpers.php::__t(string $key, array $replace = [])` — carrega o idioma 1x por request (cache estático interno)
- Fallback: chave devolvida em vez do texto se não existir (evidencia o problema em tela)
- Placeholders: `:nome` substituído por `$replace['nome']`
- Idioma atual: vem de:
  1. `$_SESSION['user_language']` se houver usuário logado
  2. Idioma do curso atual quando aplicável (notificações sobre cursos)
  3. `users.language` (perfil) como fallback
- Convenção de chaves: `<modulo>.<contexto>.<detalhe>` em snake_case

## Convenção de prefixos

| Prefixo            | Uso                                                              |
| ------------------ | ---------------------------------------------------------------- |
| `<modulo>.`        | Strings do módulo (`course.title`, `cu.name`, `evaluation.pdf`)  |
| `action.`          | Botões comuns (`action.save`, `action.delete`, `action.submit`)  |
| `flash.`           | Mensagens de flash (`flash.course.created`, `flash.auth.logged_out`) |
| `err.`             | Erros de validação (`err.course.year_invalid`)                   |
| `auth.`            | Autenticação, sessão, recuperação de senha                       |
| `nav.`             | Itens de navegação                                               |
| `email.`           | Templates de email                                               |
| `notif.`           | Notificações in-app (sininho)                                    |
| `ranking.`         | Tela de ranking, filtros                                         |
| `xp.`              | Mensagens relativas a pontos                                     |
| `feedback.`        | Feedback de atividades/avaliações                                |
| `group.`           | Grupos de alunos                                                 |

## Instruções

1. **Coletar todas as chaves `__t()` em uso** no código:
   - Rodar `grep -rhoE "__t\\('([^']+)'" src/ public/` (via Grep tool)
   - Deduplicar
2. **Ler `lang/pt.php` e `lang/en.php`** — extrair chaves definidas em cada
3. **Comparar os 3 conjuntos** (código × pt × en) e reportar:
   - **Ausentes:** usadas no código mas faltam em `pt.php` ou `en.php`
   - **Órfãs:** definidas mas não referenciadas no código
   - **Dessincronizadas:** existem em um idioma e não no outro
4. Gerar patch:
   - Adicionar chaves ausentes nos 2 arquivos
   - Remover órfãs (confirmar com o usuário antes se forem > 5)
5. Para chaves novas, propor textos em PT e EN consistentes com o tom já usado no mesmo módulo

## Output

```
## Relatório de sincronização i18n

### OK: <N> chaves

### Ausentes (usadas no código mas faltam no idioma)

| Chave                       | Falta em | Sugestão                                    |
| --------------------------- | -------- | ------------------------------------------- |
| `course.col.year`           | pt, en   | PT: "Ano" · EN: "Year"                      |

### Órfãs (definidas mas não usadas)

| Chave                       | Em         |
| --------------------------- | ---------- |
| `old.feature.removida`      | pt, en     |

### Dessincronizadas

| Chave                       | Em PT | Em EN |
| --------------------------- | ----- | ----- |
| `ranking.window.weekly`     |   X   |       |

---

### Patch sugerido

`lang/pt.php` — adicionar:
'course.col.year' => 'Ano',

`lang/en.php` — adicionar:
'course.col.year' => 'Year',

Remover (confirmar):
// 'old.feature.removida' => '...'
```

## Checklist

- [ ] Nenhuma string hardcoded em PT/EN em `src/pages/`, `src/templates/`, `src/controllers/`, `src/services/`
- [ ] Todas as chaves usadas existem em `lang/pt.php`
- [ ] Todas as chaves usadas existem em `lang/en.php`
- [ ] Nenhuma chave órfã (definida e não usada) sem justificativa
- [ ] Nomenclatura `<modulo>.<contexto>.<detalhe>` em snake_case

## Exemplo

**Input:** "Sincroniza"

**Output:** relatório no formato acima + patch pronto para aplicar em ambos os arquivos de idioma. Se houver mais de 5 órfãs, perguntar antes de remover.
