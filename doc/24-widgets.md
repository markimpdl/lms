# 24 — Widgets interativos

> **Feature F26 / Épico E35.** Adicionada em 2026-06-08. Decisão de arquitetura em **ADR-037**.

## O que é um widget

Um **widget** é uma mini-aplicação interativa empacotada num **arquivo `.zip`** (HTML + CSS + JS + imagens), criada pelo professor. Exemplo do PO: uma **calculadora**. Depois de cadastrado, o widget pode ser **inserido no conteúdo de uma Competence Unit** e aparece para o aluno junto ao material de estudo.

O zip contém uma página estática autossuficiente. O ponto de entrada padrão é **`index.html`** na raiz do zip.

## Modos de renderização (escolhido no cadastro)

Ao cadastrar o widget, o professor escolhe **como ele aparece** no conteúdo:

| Modo | Comportamento |
|------|---------------|
| **`inline`** | O widget renderiza **embutido** no conteúdo da CU (ex.: a calculadora aparece direto na página de estudo, dentro de um quadro). |
| **`window`** | No conteúdo aparece um **ícone/botão**; ao clicar, o widget abre em **nova janela/aba** (página isolada em tela cheia). |

## Segurança — sandbox de origem nula (ADR-037)

Widgets executam **JavaScript arbitrário escrito pelo professor**, rodando no navegador do aluno. Para impedir que esse JS comprometa a sessão do aluno ou o LMS:

- O widget é sempre servido dentro de um **`<iframe sandbox="allow-scripts">`** — **sem** `allow-same-origin`.
- Sem `allow-same-origin`, o documento do iframe é tratado como **origem opaca/nula**: o JS do widget **não** consegue ler `document.cookie`, `localStorage`/`sessionStorage` do LMS, nem acessar o `window.parent` (DOM da página hospedeira).
- O modo `window` abre a mesma página isolada em tela cheia (também sem acesso à sessão).
- **CSP restritiva** na resposta do widget (`default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self' data:`) limita exfiltração/chamadas externas. (`'unsafe-inline'` é necessário porque o widget é HTML/JS solto do professor; o sandbox de origem nula é a camada principal de contenção.)

> **Importante (corrigido no smoke do E35):** sub-recursos (imagens/CSS/JS) requisitados de **dentro** do iframe sandbox de origem nula **NÃO** enviam o cookie de sessão (origem opaca → tratada como cross-site). Por isso o serving **não** usa `require_auth` — a autorização é por **token assinado no path** (ver "Autorização do serving — token no path" abaixo).

## Armazenamento e serving

- O zip é **extraído para fora do document root** (em `storage/`, não em `public/`), evitando que qualquer arquivo executável (`.php`) empacotado seja servido/rodado pelo Apache da Hostinger.
- Os assets do widget são entregues por um **endpoint PHP de passthrough** (`/widget/serve/{id}/<path>`) que faz stream dos bytes com:
  - **mapa de content-type** por extensão (allowlist),
  - `X-Content-Type-Options: nosniff`,
  - a CSP do widget,
  - **defesa contra path traversal** (caminho confinado à pasta do widget; rejeita `..`).
- Isso mantém o caminho **compatível com a migração futura pra GCS** (ver `project_future_gcs_storage`): basta trocar o backend de storage por trás do endpoint, sem acoplar a `public/uploads/`.

### Autorização do serving — token no path (não cookie)

O widget roda num `<iframe sandbox>` de **origem nula**: requisições de sub-recursos (imagens/CSS/JS) feitas de dentro do iframe **não enviam o cookie de sessão** (origem opaca é tratada como cross-site). Por isso o endpoint de serving **não usa `require_auth`** — um gate por sessão redirecionaria os assets pro `/login` (302) e quebraria o widget.

Em vez disso, a URL carrega um **token assinado no path**: `/widget/serve/{id}/{token}/...` (padrão "signed URL", estilo presigned S3).
- `token = HMAC(widgetId | dia, secret)`, truncado. Rotaciona por dia (aceita hoje e ontem).
- **Secret dedicado**: 256 bits aleatórios em `storage/widget_secret.key` (gerado no 1º uso, `0600`, gitignored, fora do webroot). **Não** reusa credencial (DB/SMTP) nem tem fallback público → token não é forjável por quem só tem o repo.
- O token é emitido **apenas** nas páginas onde o usuário já está autorizado a ver o widget (conteúdo acessível via `expand_widgets`; página `/widget/open/{id}`, que continua atrás de auth + `Widget::userCanAccess`). O token **no path** faz os sub-recursos relativos herdarem o gate.

**Trade-off conhecido (aceito):** a URL é *bearer* — quem a obtiver acessa aquele asset até a virada do dia. Aceitável porque assets de widget são conteúdo de baixa sensibilidade do professor e a URL é de vida curta e por-widget. Se algum widget exigir confidencialidade real, revisitar (ex.: token por-sessão exigiria abrir mão do sandbox cookieless).

### Validação no upload (extração segura)

- Limite de tamanho do zip (alinhar com os limites de upload do projeto; sugerido **10–12 MB**).
- `finfo_file` confirma que é um zip real.
- **Zip-slip guard:** rejeita entradas com `..`, caminho absoluto, ou que escapem da pasta de destino.
- **Allowlist de extensões internas:** `html`, `htm`, `css`, `js`, `json`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `svg`, `woff`, `woff2`, `ttf`, `map`. **Rejeita** `php`, `phtml`, `phar`, executáveis, etc.
- Exige a presença do **`index.html`** (ou o `entry_file` configurado) na raiz.

## Biblioteca compartilhada no curso (ADR-037)

- O widget pertence ao **tenant do professor que o cadastrou** (sua biblioteca, reutilizável nos cursos dele).
- Num **curso compartilhado** (F23/ADR-033), o **picker de widgets** do editor de conteúdo oferece os widgets de **todos os professores com acesso ao curso** (dono + colaboradores) — "biblioteca compartilhada no curso".
- **Editar/remover a definição** do widget = só o **tenant que o cadastrou**.
- **Inserir** um widget no conteúdo = qualquer professor com acesso ao curso.
- Renderização para o aluno independe de quem inseriu (faz parte do conteúdo da CU, que já é compartilhado).

## Integração no editor de conteúdo

- Botão na toolbar do **TinyMCE** → abre o picker de widgets disponíveis no curso → insere um **placeholder/shortcode** no conteúdo: `[[widget:ID]]`.
- O conteúdo é salvo com o **token** (não com `<iframe>` cru) → sobrevive ao **HTML Purifier** sem precisar relaxar a allowlist de iframes.
- Na **renderização pro aluno**, o token é **expandido**:
  - modo `inline` → `<iframe sandbox="allow-scripts" src="/widget/serve/{id}/">` responsivo;
  - modo `window` → ícone/botão que abre `/widget/open/{id}` em nova janela.
- **Widget removido em uso:** se o token referencia um widget inexistente/inativo, renderiza um placeholder gracioso (ex.: "widget indisponível") em vez de quebrar a página.

## Acesso ao endpoint de serving

- **Não** usa `require_auth` (o iframe sandbox cookieless impede) — gate por **token assinado no path** (ver "Autorização do serving — token no path"). O token é emitido só nas páginas onde o usuário já estava autorizado a ver o widget.
- A página `/widget/open/{id}` (modo window), essa sim, **continua** atrás de auth + `Widget::userCanAccess` (professor com acesso / aluno matriculado em curso que referencia o widget) — é uma página navegada normal, com cookie.

## Mobile e i18n

- iframe `inline` responsivo (encolhe em viewport 360px; dimensões sugeridas opcionais por widget).
- Toda a UI de gestão (biblioteca, cadastro, picker) e os rótulos respeitam PT/EN via `__t()`.

## Pendências / a revisar quando

- **Granularidade do picker:** no MVP, o curso compartilhado oferece *todos* os widgets dos colaboradores. Se ficar ruidoso, evoluir para "marcar widget como disponível no curso" (junção explícita).
- **Versionamento:** re-upload substitui os arquivos do widget (mesma `id`/`slug`). Sem histórico de versões no MVP.
- **Quotas de storage** por tenant — revisitar se o volume crescer.
