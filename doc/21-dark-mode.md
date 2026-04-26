# Dark mode — preferência aluno (E27 — F18)

> Setting visual por aluno (light/dark). Apenas aluno no MVP — teacher/super-admin ficam light. Entregue em v0.24.0.

## TL;DR

- **Quem usa:** aluno (qualquer tenant). Teacher/super-admin não vê toggle e fica sempre light.
- **Onde escolhe:** `/profile` → aba "Aparência" (3ª aba) → 2 radio cards (Claro/Escuro).
- **Onde persiste:** `users.theme ENUM('light','dark')` no DB + sincronizado com `$_SESSION['user']['theme']` em todo login.
- **Onde aplica:** classe `lms-theme-dark` no `<body>` (setada em `layout.php` quando `current_user_theme()==='dark'` E página é student-area).
- **Sem auto-detecção** via `prefers-color-scheme` no MVP — escolha explícita do user.

## Arquitetura

```
Login (AuthController::authenticate)
       │
       └─ SELECT users.theme → $_SESSION['user']['theme']

Cada request
       │
       ├─ helper current_user_theme(): 'light' | 'dark'
       │
       └─ layout.php
              │
              ├─ Se isStudentArea AND theme==='dark':
              │     <body class="lms-student-area lms-theme-dark">
              │
              └─ student-area.css carrega (cobre ambos os temas)

User troca tema em /profile
       │
       └─ POST /profile/theme (handler em src/pages/profile/theme.php)
              │
              ├─ Defesa: 403 se role !== 'student'
              ├─ Whitelist 'light'|'dark' silenciosa
              ├─ UPDATE users.theme + $_SESSION['user']['theme']
              └─ Redirect 303 pra referer same-host (fallback /profile)
```

## CSS strategy: token overrides

Block dark único em `student-area.css`:

```css
body.lms-student-area.lms-theme-dark {
    /* Tokens neutrais — invertidos */
    --lms-page-bg:        #0F172A;
    --lms-card-bg:        #1E293B;
    --lms-neutral-900:    #F9FAFB;  /* texto principal claro */
    --lms-neutral-700:    #E5E7EB;
    --lms-neutral-500:    #9CA3AF;
    --lms-neutral-300:    #4B5563;
    --lms-neutral-200:    #374151;
    --lms-neutral-100:    #1F2937;
    --lms-neutral-50:     #111827;
    /* ... */
}
```

**Componentes existentes que consomem tokens herdam automaticamente.** Nenhuma alteração de CSS por componente foi necessária — só os componentes Bootstrap (`.bg-white`, `.text-muted`, `.alert-*`, `.bg-*-subtle`, `.form-control`, `.table`) precisaram override explícito porque não consomem nossos tokens.

### Decisões consolidadas

- **Cores semânticas (success/warning/danger/violet/primary) preservadas em hue** — só os `-tint` viram `rgba(.., 0.18)` translúcidos. Identidade visual consistente entre light e dark.
- **Shadows mais opacas em dark** (rgba 0.4-0.6 vs 0.04-0.22) — sombras precisam ser mais visíveis em fundos escuros.
- **TinyMCE output (`.content-render`)** coberto especificamente: text/links/pre/blockquote.
- **Sem FOUT**: classe é setada server-side antes do CSS carregar.

## Defesa em camadas

| Layer | Defesa |
|---|---|
| **UI gate** (`/profile`) | Aba "Aparência" só renderiza quando `role='student'` |
| **Server-side handler** (`/profile/theme`) | 403 quando `role !== 'student'` |
| **Whitelist** | POST com valor inválido redireciona silencioso (não vaza tentativa) |
| **Helper `current_user_theme()`** | Retorna `'light'` pra non-aluno + valida whitelist na leitura (defesa contra DB editado) |
| **`layout.php` aplica class** | Só quando `isStudentArea` (ainda mais conservador — teacher/admin nunca recebe class no body mesmo se DB tivesse `theme='dark'`) |

## Edge cases tratados

- **Aluno em rotas non-student-area** (ex.: `/notifications`): a class `lms-theme-dark` não é aplicada (gate é `isStudentArea`). Aceitável — o conjunto de rotas student-area cobre o que o aluno vê visualmente.
- **Teacher/super-admin com `users.theme='dark'`** no DB (manualmente editado ou de uso futuro): `current_user_theme()` retorna `'light'` (helper gateado por role); class nem é aplicada.
- **DB editado pra valor inválido** (ex.: `theme='blue'`): helper valida whitelist e cai em `'light'`.
- **Sessão antiga sem `theme` populado**: AuthController atualizado em E27-01 popula default `'light'` em todo login; sessões pré-E27 sem reload caem em `'light'` (helper default).

## Pendências esperadas pós-smoke

Anotar em `doc/99-pendencias-tecnicas.md` se aparecer:
- Componentes LMS-específicos (course card, achievement icons, XP bar, ranking pill, modais Bootstrap, filter pills) que não cobrimos explicitamente — herdam tokens, mas podem precisar ajuste fino visual em dark.
- Contraste WCAG AA pra alguns pares de cor (texto sobre card, badge sobre fundo) — validar com Lighthouse ou ferramenta manual.
- Prefers-color-scheme auto-detecção (futura iteração se PO pedir).

## Próximo épico

E28 (F19) — Patentes informativas no ranking. Não relacionado a dark mode (mas as cores das patentes em dark devem ser revistas pra contraste — fica em E29 ou polish independente).
