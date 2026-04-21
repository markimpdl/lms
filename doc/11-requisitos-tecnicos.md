# 11 — Requisitos técnicos

## Stack

- **Backend:** PHP 8.2+ (tipos estritos onde fizer sentido).
- **Banco:** MySQL 8 (ou MariaDB 10.6+ se o plano só oferecer isso).
- **Frontend:** HTML5 + **Bootstrap 5** + JavaScript sem framework pesado (Alpine.js para interações simples).
- **Editor de conteúdo:** **TinyMCE 6 community**.
- **Editor de código:** **CodeMirror 6**.
- **Execução de código:** Judge0 (via API de terceiro em RapidAPI inicialmente).

Justificativa: a Hostinger em plano revenda roda PHP + MySQL de forma previsível, mas não oferece Node.js server-side nem Docker. Por isso o frontend fica com JavaScript leve e dependências via CDN ou build local simples.

## Hospedagem

- **Hostinger**, plano revenda do usuário, com cPanel.
- Sem acesso root, sem Docker, sem serviços persistentes (workers, filas nativas).
- Email: SMTP autenticado (podem ser as credenciais do cPanel ou SMTP externo como SendGrid/Mailgun/Brevo).
- Cron jobs do cPanel para tarefas agendadas (digest diário, limpeza).

## Escala alvo

- ~10 alunos simultâneos por professor.
- 2–3 professores ativos no primeiro semestre.
- Total < 100 usuários ativos por dia.

Não precisa de cache Redis, fila externa ou CDN nesta fase.

## Arquitetura em alto nível

- Aplicação PHP tradicional (MVC simples) servida via Apache/LiteSpeed.
- Sessão PHP nativa para login.
- Uploads em disco local, organizados por tenant.
- Um único banco MySQL, **multi-tenant por coluna** (`tenant_id` em cada tabela relevante).
- Logs em arquivo + tabela de auditoria para eventos sensíveis.

## Internacionalização (i18n)

- Idiomas: **português** e **inglês**.
- Cada usuário tem preferência de idioma (profile).
- Mensagens da UI em arquivos de tradução (`lang/pt.php`, `lang/en.php`) ou gettext.
- Conteúdo dos cursos (escrito pelo professor) não é traduzido automaticamente.

## Segurança mínima

- Senhas hash com `password_hash` (bcrypt/argon2).
- CSRF token em todos os formulários.
- Sanitização de HTML (HTML Purifier) antes de salvar conteúdo rico.
- Escape de saída (`htmlspecialchars`) para todos os dados não-ricos.
- Prepared statements em todas as queries (PDO).
- Upload: validação de extensão, mime-type e tamanho; armazenamento fora da raiz web sempre que possível; download proxy via PHP.
- Rate limit em login e em execuções de código.
- Cookies HttpOnly + Secure + SameSite=Lax.

## Performance e mobile

- Tempo de carregamento alvo da tela inicial: < 2 s em 4G.
- Imagens responsivas (`srcset`).
- CSS e JS minificados em produção.
- Uso leve de JavaScript — páginas funcionam mesmo com JS desligado, onde viável (fallbacks razoáveis).
- Design mobile-first desde o começo, não adaptado depois.

## Ambientes

- **Local:** XAMPP/WAMP ou Docker Compose simples (PHP + MySQL) — para o desenvolvimento.
- **Produção:** Hostinger cPanel.

## Instalação

- O banco MySQL é criado **manualmente** no cPanel pelo professor.
- Um **script SQL** (`install/schema.sql`) contendo todas as tabelas e seeds iniciais (usuário super-admin padrão, linguagens, etc.) é executado via phpMyAdmin pelo professor.
- Não há instalador web automatizado nesta fase.

## Backup

- **Sem backup automatizado** no MVP (decisão explícita).
- Em caso de necessidade, backup é feito manualmente via phpMyAdmin (dump SQL) e cópia da pasta `storage/uploads` pelo cPanel.

## Observabilidade

- Log de erros PHP.
- Log de auditoria (login, criação de conta, entregas, feedback, lançamento de nota).
- Painel mínimo para o super-admin ver métricas agregadas.
