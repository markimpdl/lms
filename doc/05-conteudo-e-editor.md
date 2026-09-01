# 05 — Conteúdo e editor

## O que é o conteúdo

Cada **Competence Unit** tem uma página de conteúdo: um documento HTML rico, escrito pelo professor, que o aluno lê ao entrar na CU. É a principal fonte de estudo antes das atividades e da avaliação.

**Em curso V2 (trilha, ADR-038)** essa mesma página vira a **capa** da unidade — a abertura, antes do primeiro item —, e o corpo do estudo se divide em **lições**: telas menores, na ordem que o professor definir, intercaladas com os exercícios.

### Lições (só em curso V2)

- Uma lição tem título, HTML rico, XP opcional e um estado de publicação próprio. **Rascunho não aparece pro aluno** e não entra no cálculo de progresso dele.
- Usa o **mesmo editor e a mesma sanitização** da página de conteúdo — mesma allowlist de tags, mesma regra de iframe, mesmos anexos da CU disponíveis no picker de imagem.
- O aluno marca a lição como **concluída** explicitamente (só abrir não conta). Isso alimenta o progresso da CU e credita o XP.
- Apagar uma lição remove as conclusões dos alunos (cascade) **e o XP creditado por ela** — o modal de exclusão avisa quantos alunos serão afetados antes de confirmar.

## Editor WYSIWYG

O professor edita o conteúdo em um editor visual tipo CMS. Requisitos mínimos do editor:

- Formatação de texto: negrito, itálico, sublinhado, tachado, cor.
- Títulos (H2, H3, H4).
- Listas ordenadas e não ordenadas.
- Links.
- Tabelas simples.
- Blocos de código com destaque de sintaxe (pelo menos Python, C#, JavaScript, HTML, CSS).
- Alinhamento de texto.
- Desfazer / refazer.

**Escolha:** **TinyMCE 6 community** (licença GPLv2+). Motivos: plugin `media` já suporta embed de YouTube/Vimeo por URL; toolbar amigável com toggle "fonte"; boa experiência em mobile; PHP integration bem documentada.

## Embeds de vídeo

O professor insere vídeos do **YouTube** e **Vimeo** colando a URL. O backend converte para iframe seguro com uma **allowlist** restrita a esses dois domínios.

- Colar URL → o editor oferece "inserir vídeo".
- Renderização para o aluno: iframe responsivo (16:9), com `loading="lazy"`.
- Nenhum outro domínio é aceito para evitar XSS via iframe.

## Anexos

O professor pode anexar arquivos ao conteúdo.

- Tipos aceitos: `pdf`, `zip`, `txt` (iniciais) e imagens (`png`, `jpg`, `jpeg`, `gif`, `webp`) para uso inline.
- Tamanho máximo por arquivo: **3 MB**.
- Armazenamento em disco via Hostinger (estrutura `uploads/tenant_<id>/content/<cu_id>/...`).
- Download exige sessão autenticada e validação de tenant/matrícula.

## Segurança do HTML

Antes de salvar o HTML no banco, aplicar **sanitização** no servidor (ex.: HTML Purifier para PHP) para bloquear:

- `<script>`, event handlers (`onclick`, `onerror`, etc.).
- Tags e atributos fora da allowlist.
- iframes de origens não autorizadas.

A sanitização acontece no servidor, nunca só no cliente.

## Mobile

O conteúdo é renderizado num layout responsivo. Vídeos 16:9 encolhem, tabelas com scroll horizontal, tipografia legível em telas a partir de 360px.

## Tradução

O conteúdo é escrito num único idioma por CU — não há tradução automática. A UI em volta do conteúdo (menus, botões) respeita o idioma do usuário (PT/EN).
