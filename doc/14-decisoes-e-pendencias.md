# 14 — Decisões e pendências

## Decisões tomadas (ADRs curtos)

### ADR-001 — Multi-tenant por coluna, não por banco
**Decisão:** um único banco MySQL, `tenant_id` em todas as tabelas relevantes.
**Por quê:** escala alvo é pequena (<5 professores iniciais) e manter múltiplos bancos aumenta complexidade operacional no cPanel.
**Implicação:** toda query precisa respeitar filtro de tenant — criar helpers de repositório que não permitam esquecer.

### ADR-002 — XP seguindo modelo "B"
**Decisão:** atividades concedem XP fixo ao entregar; avaliações concedem XP se nota ≥ 8/10.
**Por quê:** atividades não têm nota, então condicionar XP a um critério de qualidade contradiz o desenho. A avaliação continua sendo o filtro de mérito.
**Alternativas descartadas:** (A) só avaliações dão XP (desmotiva entregas); (C) professor marca "aceito/refazer" em cada atividade (trabalho manual a mais).

### ADR-003 — Ranking com janelas rolantes
**Decisão:** últimos 7 dias e últimos 30 dias são janelas rolantes (`NOW() - X dias`), não semana/mês do calendário.
**Por quê:** mais justo para alunos que pontuam em datas distintas; mais simples de calcular.

### ADR-004 — Correção manual no MVP
**Decisão:** todas as atividades e avaliações são corrigidas manualmente pelo professor, inclusive código.
**Por quê:** professor tem ~10 alunos; autocorreção adiciona complexidade sem ganho real nesta escala.

### ADR-005 — Judge0 via RapidAPI
**Decisão:** usar Judge0 CE hospedado por terceiro (RapidAPI) em vez de self-host.
**Por quê:** Hostinger cPanel não roda Docker; self-host exigiria VPS separada.
**A revisar quando:** volume de execuções ultrapassar o plano gratuito do RapidAPI.

### ADR-006 — Mobile-first
**Decisão:** design começa por mobile, depois expande para desktop.
**Por quê:** alunos nos EAU consomem conteúdo majoritariamente por celular; experiência precisa ser boa nesse formato primeiro.

### ADR-007 — Uma avaliação por CU
**Decisão:** cada competence unit tem no máximo uma avaliação.
**Por quê:** modela "prova final da matéria" de forma unívoca; múltiplas avaliações confundiriam o cálculo de progresso e aprovação.

### ADR-008 — PDF do enunciado é upload do professor
**Decisão:** o enunciado da avaliação é um PDF que o professor faz upload, não algo gerado pela plataforma.
**Por quê:** muitos professores já têm suas provas em PDF; gerar PDF complicaria o editor sem valor claro.

### ADR-009 — Editor de conteúdo: TinyMCE 6 community
**Decisão:** usar TinyMCE 6 community (GPLv2+) como editor WYSIWYG do conteúdo das CUs.
**Por quê:** plugin `media` já reconhece URLs de YouTube/Vimeo e gera iframe; toolbar amigável; boa experiência mobile; integração PHP bem documentada.
**Alternativa descartada:** CKEditor 5 (alguns plugins úteis são só pagos).

### ADR-010 — Editor de código: CodeMirror 6
**Decisão:** usar CodeMirror 6 no editor da atividade de código.
**Por quê:** bundle modular e significativamente mais leve que Monaco (Monaco ~5 MB é impraticável em 4G/celular). Suporta as linguagens-alvo via pacotes enxutos.
**Alternativa descartada:** Monaco Editor (peso excessivo e UX fraca em mobile).

### ADR-011 — UI framework: Bootstrap 5
**Decisão:** usar Bootstrap 5 como framework de UI.
**Por quê:** componentes prontos (cards, navbar, modals, toasts) entregam uma interface profissional sem wireframes prévios. Tailwind exigiria mais trabalho de design customizado antes de parecer pronto.
**Alternativa descartada:** Tailwind CSS (melhor com wireframes maduros, não é o caso).

### ADR-012 — SMTP da Hostgator
**Decisão:** envio de email via SMTP da Hostgator; credenciais fornecidas pelo professor durante o desenvolvimento e armazenadas em `config/env.php` fora do repositório.
**Por quê:** o professor já tem essa conta; evita dependência de terceiros pagos no MVP.

### ADR-013 — Sem backup automatizado no MVP
**Decisão:** não há rotina de backup automatizada. Em emergência, dump SQL manual via phpMyAdmin e cópia da pasta `storage/uploads` pelo cPanel.
**Por quê:** escala pequena, risco aceito pelo usuário. Simplifica o MVP.
**A revisar quando:** entrarem professores externos via SaaS.

### ADR-014 — Idioma do email segue idioma do curso
**Decisão:** emails relacionados a um curso (feedback, nota, nova atividade, reenvio liberado) usam o idioma configurado no curso pelo professor. Emails fora desse contexto (boas-vindas, recuperação de senha) usam o idioma do perfil do usuário.
**Por quê:** preserva coerência com o conteúdo que o aluno está estudando, mesmo que a preferência pessoal dele seja outra.

### ADR-015 — Nota com uma casa decimal
**Decisão:** notas da avaliação aceitam valores de 0.0 a 10.0 com passo de 0.1 (schema `DECIMAL(3,1)`).
**Por quê:** dá ao professor granularidade para diferenciar entregas próximas (ex.: 7.5 vs 8.0 altera liberação de XP).

### ADR-016 — Sem exportação de notas no MVP
**Decisão:** dashboards não oferecem exportação CSV ou PDF de notas/status.
**Por quê:** não há necessidade imediata apontada pelo usuário; dados acessíveis via UI são suficientes.
**A revisar quando:** houver demanda oficial por relatórios externos.

### ADR-017 — Instalação via script SQL manual
**Decisão:** o banco é criado manualmente no cPanel e o schema é carregado a partir de `install/schema.sql` executado no phpMyAdmin. Não há instalador web.
**Por quê:** usuário-alvo é um professor técnico com acesso ao phpMyAdmin; instalador web é esforço a mais sem valor imediato.
**Arquivo produzido:** `install/schema.sql` com tabelas + seeds (super-admin padrão, etc.).

### ADR-018 — Sem Termos de Uso/Privacidade no MVP
**Decisão:** a plataforma não exibe termos de uso nem política de privacidade enquanto o cadastro público estiver desativado (uso só do autor + colega).
**Por quê:** dados são restritos ao círculo de teste; obrigações formais (LGPD/GDPR) entram em cena somente quando o SaaS abrir cadastro público.
**A revisar quando:** habilitar o cadastro público de professores.

### ADR-019 — Rate limits do Judge0
**Decisão:** 30 execuções por aluno por minuto, 3 execuções simultâneas por aluno, cap diário de 200 execuções por aluno.
**Por quê:** dimensionado para <10 alunos simultâneos e <30 totais; folga para iteração de código sem expor a chave do Judge0 a abuso.
**A revisar quando:** escala crescer ou aparecer demanda de uso muito intenso por atividade.

### ADR-020 — Cascade em exclusão de avaliação com submissões
**Decisão:** excluir uma avaliação com submissões remove em cascade todas as submissões e `xp_events` relacionados, sob confirmação explícita do professor.
**Por quê:** mantém o modelo simples; usuário aceita o risco.
**Alternativa descartada:** arquivar em vez de excluir (mais complexo, sem pedido do usuário).

### ADR-021 — Email do usuário é imutável
**Decisão:** o email de qualquer usuário (super-admin, professor, aluno) não pode ser alterado após a criação da conta. Nenhum papel — inclusive super-admin — expõe a edição de email na UI.
**Por quê:** email é a chave lógica de identidade no MVP (usado em login, notificações, histórico de auditoria); permitir troca abriria superfície para erros e exigiria fluxo de confirmação por email duplo sem valor imediato.
**Implicação operacional:** se um usuário realmente precisar trocar o endereço, a saída é criar uma conta nova e desativar a antiga (perfil/histórico não migra).
**A revisar quando:** cadastro público for habilitado e surgir demanda real de usuários trocando provedor de email.

### ADR-022 — Senha inicial do aluno é definida pelo professor
**Decisão:** no cadastro do aluno, o professor define manualmente a senha inicial (ou usa o botão "gerar forte" que produz 12 caracteres alfanuméricos + símbolos). A senha é armazenada com bcrypt cost 12 e enviada ao aluno por email (opt-out no form).
**Por quê:** contexto presencial — o professor está com o aluno na sala e pode entregar a senha diretamente; fluxo de self-service ("aluno escolhe senha") adicionaria uma etapa que atrasa o primeiro acesso em aula.
**Alternativa descartada:** email de ativação com link para o aluno definir a senha (mais seguro, porém exige conectividade imediata do aluno, que nem sempre há em sala).
**A revisar quando:** cadastro público / auto-matrícula for habilitado.

### ADR-023 — `courses.year` armazenado como `YEAR` (inteiro)
**Decisão:** o campo `year` do curso é um inteiro representando o ano civil (ex.: `2026`), tipo MySQL `YEAR` ou `SMALLINT`. Não é intervalo, período letivo nem string.
**Por quê:** alinha com rankings por "ano civil" (ADR-003 e doc/08); simplifica ordenação e filtros; evita ambiguidade de períodos letivos que variam entre países.
**Validação:** `1900 ≤ year ≤ current_year + 1` na criação e edição.

### ADR-024 — Professor só é desativado, nunca excluído
**Decisão:** o MVP não oferece exclusão definitiva de professor nem do tenant associado. O super-admin apenas ativa/desativa.
**Por quê:** preserva histórico completo de cursos, alunos, submissões e XP; evita exclusões irreversíveis acidentais; em escala <5 professores na fase inicial, o custo de manter registros inativos é desprezível.
**Implicação:** tabela `tenants` cresce monotonicamente; se necessário, limpeza vira processo manual fora da UI.
**A revisar quando:** surgir demanda formal (LGPD/GDPR) ou quando a escala crescer a ponto de exigir higienização programada.

### ADR-025 — Owner do tenant é fixo na criação
**Decisão:** `tenants.owner_user_id` não pode ser alterado no MVP. Se um tenant precisar mudar de dono, a prática é criar um tenant novo.
**Por quê:** simplifica ACL e auditoria; troca de owner implica rever permissões em cascata (cursos, conteúdo, submissões) com risco que não se justifica no MVP.
**A revisar quando:** houver cenário formal de transferência (venda do espaço, sucessão, etc.).

### ADR-031 — Histórico de conexões com geo-IP, retenção 180 dias
**Decisão (E16-04, 2026-04-25):** cada login bem-sucedido grava 1 row em `user_logins` com `(user_id, tenant_id, ip, location, user_agent, logged_in_at)`. O `ip` vem de `X-Forwarded-For` (primeira entrada) ou `REMOTE_ADDR`. `location` é resolvido por GeoIP via **ip-api.com (free tier 45 req/min)**, com falha silenciosa em rate-limit/timeout (location fica NULL). Visível APENAS pelo professor no detalhe do aluno (`/teacher/students/{id}`) — últimas 10 conexões. Retenção **180 dias** via cron `scripts/cron/purge-old-logins.php`.
**Privacidade:** IP é dado pessoal. A coleta é proporcional (login bem-sucedido apenas, não cada request) e tem retenção limitada. Antes de habilitar **cadastro público** ou expandir além do contexto presencial atual, atualizar Termos de Uso/Política de Privacidade explicitando: (a) coleta de IP + endereço aproximado, (b) finalidade de auditoria/suporte, (c) retenção 180 dias, (d) acesso restrito ao professor do tenant.
**Por quê:** o professor frequentemente recebe pedidos de suporte ("não consigo entrar", "alguém entrou na minha conta?") sem dados pra responder. IP + região + horário cobrem a maioria desses casos sem investir em SIEM/logging avançado.
**A revisar quando:** (a) cadastro público abrir; (b) volume crescer a ponto da tabela ficar pesada (centenas de milhares de rows); (c) LGPD/GDPR demandarem direito à exclusão sob demanda — hoje só o cron de retenção funciona.

### ADR-030 — Sem `audit_log` no MVP
**Decisão:** o MVP **não** implementa a tabela `audit_log` nem registra eventos estruturados de domínio (`create_teacher`, `enroll_student`, `delete_activity`, `grade_evaluation`, `login_success`, etc.). O sistema mantém apenas:
- Log de erros do PHP (padrão da hospedagem + `storage/logs/error.log`).
- Logs operacionais em arquivo (`storage/logs/mail-debug.log`, `judge0.log`, `cron-digest.log`, `cron-cleanup.log`, `deploy.log`).
- Dados de domínio próprios (submissões, notas, feedback, XP) que já registram a história visível do produto.
**Por quê:** escala alvo é <30 usuários ativos; rastro via git, logs operacionais e os próprios dados do modelo é suficiente para investigar a maioria dos incidentes. Auditoria estruturada custa trabalho em cada ponto de escrita sem ganho imediato percebido.
**Supersede:** todo o Epic E12 (Auditoria) — sai do roadmap do MVP. A rota `/admin/audit`, o model `AuditLog`, o helper `audit()`, a constante `AuditEvents.php` e todos os AC "Evento `audit_log`: …" previstos nos épicos E2–E11 deixam de existir.
**A revisar quando:** (a) cadastro público for aberto e a base crescer; (b) incidente de segurança exigir investigação retroativa; (c) LGPD/GDPR entrarem em cena.

### ADR-029 — MVP roda no plano gratuito do Judge0 sem rate limit próprio
**Decisão:** o LMS consome Judge0 CE pelo plano **gratuito** da RapidAPI e **não implementa** rate limit por aluno, contador de simultâneas, cap diário, nem controle tenant-wide no próprio código. O único limite de sanidade preservado é **64 KB por submissão de código** (validado localmente antes de chamar o Judge0).
**Comportamento ao esgotar a cota:** quando o Judge0 retornar HTTP 429 (ou equivalente), o backend repassa como erro amigável e o front exibe "Serviço de execução temporariamente indisponível — tente novamente mais tarde". Não há aviso prévio, não há fila, não há quota interna.
**Por quê:** na escala alvo (1 professor, <10 alunos), construir contadores, tabela `code_run_events`, cron de limpeza e painel administrativo custa mais do que o incômodo eventual de esgotar a cota. Aceita-se o trade-off.
**Supersede:** ADR-019 (rate limits numéricos). ADR-019 fica histórico para referência.
**A revisar quando:** (a) o plano pago for assinado; (b) a indisponibilidade por cota se tornar frequente a ponto de atrapalhar aulas; (c) o cadastro público for habilitado e alunos desconhecidos entrarem na plataforma.

### ADR-028 — Limites de upload por contexto (atualizado v0.30.0)
**Decisão (v0.30.0):** três tetos distintos por contexto:
- PDF de enunciado da avaliação: **12 MB** (`UPLOAD_MAX_MB_PDF_BRIEF` no env, default 12)
- Anexos TinyMCE em conteúdo (`content_attachments`): **12 MB**
- Entrega do aluno (atividade + avaliação): **10 MB**
**Por quê:** PO solicitou em 2026-04-27 — enunciados/anexos do professor frequentemente excedem 3 MB (figuras, diagramas), e entregas do aluno também precisam de mais espaço (PDFs de relatório, projetos zipados).
**Implicação:** constantes `MAX_BYTES` em `SubmissionStorage`, `EvaluationSubmissionStorage` (10MB), `AttachmentStorage` (12MB); env `UPLOAD_MAX_MB_PDF_BRIEF=12` em `EvaluationBriefStorage`. PO deve atualizar `config/env.php` em prod (de 10 → 12) via FileZilla — caso contrário PDF de enunciado fica em 10 MB.
**Histórico:** v0.7.0 limites uniformes 3 MB; v0.7.0 PDF enunciado virou 10 MB (ADR-028 original); v0.30.0 expansão pra 12/10 MB.
**A revisar quando:** surgirem casos reais ou se Hostinger reduzir `upload_max_filesize` no php.ini.

### ADR-027 — Aluno pode editar ou remover a própria entrega de atividade até o feedback
**Decisão:** após enviar uma atividade, o aluno pode (a) substituir o arquivo enviado ou (b) remover completamente a submissão enquanto ela **ainda não tiver feedback** registrado. No momento em que o professor grava feedback pela primeira vez, a submissão fica imutável (mesmo que o feedback seja editado depois).
**Por quê:** reduz fricção em erros honestos (aluno percebe que enviou o arquivo errado) sem abrir brecha para "tentar até colar"; o feedback do professor é o marco natural de imutabilidade.
**Impacto em XP:** substituir o arquivo **não** altera o `xp_event` já gravado (o aluno só entrega uma atividade, já ganhou o XP). Remover a submissão **apaga** o `xp_event` correspondente. Re-submeter após remoção gera um `xp_event` novo.
**Substitui:** a restrição "entrega única e não substituível" do doc/06, que fica obsoleta para atividades (avaliações seguem com fluxo próprio de reenvio — ADR-020 e E7).

### ADR-026 — Aluno é exclusivo do tenant
**Decisão:** cada aluno pertence a exatamente um tenant. O mesmo email pode existir como alunos independentes em tenants diferentes (são contas distintas, cada uma com sua senha, idioma, XP e histórico). O mesmo email **não** pode ser reutilizado entre professor/super-admin, e um email já usado como professor/super-admin não pode voltar como aluno.
**Por quê:** alinha com o contexto presencial em que cada professor monta sua própria turma e não há consentimento implícito para compartilhar dados do aluno entre tenants; elimina a fricção de "aluno compartilhado" no cadastro; reforça o isolamento multi-tenant.
**Impacto no schema:** `users.tenant_id INT NULL` (FK para `tenants`). `role='student'` exige `tenant_id NOT NULL`; `role='teacher'` e `role='super_admin'` exigem `tenant_id IS NULL` (o vínculo do professor ao tenant continua via `tenants.owner_user_id`). Unicidade: coluna gerada `email_tenant_key = CONCAT(email, ':', COALESCE(tenant_id, 0))` com `UNIQUE (email_tenant_key)`, garantindo: (a) email único entre teachers+super-admins; (b) email único entre alunos do mesmo tenant; (c) email livre entre alunos de tenants distintos.
**Consequência para remoção:** remover um aluno **é** apagar a conta — ele não pode "sair de um tenant" e ficar em outro, porque só está em um.

### ADR-032 — Login + reset multi-conta para email compartilhado entre tenants
**Decisão:** quando o mesmo email existe como aluno em N tenants distintos (cenário previsto por ADR-026), o fluxo de auth opera assim:
- **Login:** `AuthController::authenticate` testa `password_verify` contra **todas** as rows com aquele email. Se exatamente 1 bate → login direto (compatível com fluxo atual). Se 2+ batem (mesma senha em tenants distintos) → UI de seletor de tenant pede ao aluno escolher qual contexto entrar; sessão associa ao `users.id` específico.
- **Reset de senha:** `/forgot` continua respondendo genericamente (não vaza enumeração). Quando há 2+ contas com aquele email, o sistema envia **um único email** contendo lista de tenants, cada um com seu próprio token de reset (apontando pro `users.id` daquela conta). Reset numa conta **não afeta** as outras.

**Por quê:** ADR-026 deliberadamente isolou tenants ("não há consentimento implícito para compartilhar dados do aluno entre tenants"). Manter senha por conta preserva esse isolamento — se senha vaza num tenant, não vaza nos outros. Mover a desambiguação pro **email** é privacy-friendly: o email é canal autenticado (só o dono recebe), evita enumeração de tenants pela UI pública (`/forgot` → "qual tenant?" exporia se um email existe ou não em determinado tenant).

**Impacto no schema:** zero. A UK `email_tenant_key` (ADR-026) já permite N rows por email; `password_resets.user_id` já está na PK e suporta tokens por conta nativamente. Mudança fica na app: `AuthController::authenticate`, `pages/forgot.php`, `pages/reset.php` (não muda — token já é per-user), templates `templates/emails/reset.*.php` (renderiza loop), e UI nova de seletor de tenant no login quando ambíguo.

**Trade-off considerado e descartado:** alternativa "1 user row + relação N:N com tenants" (mesma senha resetando todos os tenants em cascata) foi avaliada e rejeitada — quebraria ADR-026, exigiria refactor de 20+ callsites que assumem `users.tenant_id` como source-of-truth + reescrita de XP/ranking/conquistas/notificações que dependem dessa premissa. Custo desproporcional pra um cenário marginal (até hoje 1 tenant em prod).

**Materialização:** ver `doc/15-roadmap-pos-mvp.md` F13 (épico futuro E22, fora do roadmap original — adicionado em 2026-04-25).

### ADR-033 — Curso compartilhado: autoria multi-professor, dados de aluno isolados por tenant
**Decisão (F23/E32, 2026-06-05):** um curso pode ser compartilhado com outros professores (de outros tenants) por email. O compartilhamento abrange **apenas a camada de autoria de conteúdo** — a árvore Core Competence → Competence Unit → conteúdo/anexos/atividades/avaliações/quizzes/learning outcomes é **única e editável por todos os colaboradores** (o que um edita vale pra todos). A **gestão de dados de aluno** (matrículas, entregas, notas, feedback, correção) **permanece por tenant**: cada professor matricula e **corrige só os seus próprios alunos**; um professor nunca gere os alunos do outro.

**Refinamento do ranking (2026-06-05, após E32-04):** o **ranking DO CURSO compartilhado junta os alunos dos dois professores** numa única classificação (por `course_id`, não por tenant) — um curso compartilhado é uma turma só. Já o **ranking geral do tenant** de cada professor continua só com os alunos dele, e **gerir notas/entregas mostra só os alunos do professor** (por tenant). XP é creditado no tenant do aluno (preserva o ranking de tenant); o ranking de curso agrega por `course_id` e por isso mostra todos juntos. Isto **revisa** o trecho original "vê o ranking só dos seus alunos" — vale para o ranking de tenant, não para o ranking do curso compartilhado.

**Mecânica:**
- O curso continua sob o tenant do dono (`courses.tenant_id` inalterado). Tabela nova `course_collaborators(course_id, user_id, invited_by, created_at)` liga o curso a professores de outros tenants.
- Acesso de conteúdo de um professor = "curso do meu tenant **OU** curso em que sou colaborador". Centralizar num helper único (`teacher_can_access_course` / `courses_accessible_by_teacher`) e refatorar **só** os callsites de autoria — os callsites de dados de aluno continuam filtrando por `tenant_id` como hoje.
- Convite: email de um **professor já cadastrado e ativo**; sem fluxo de aceite/criação de conta nesta feature.
- Permissões: colaborador edita conteúdo e gerencia os próprios alunos; **não pode excluir o curso** nem **gerenciar a lista de colaboradores** (ambos exclusivos do dono).
- Matrícula cross-tenant: `Enrollment::create` passa a permitir matricular um aluno do meu tenant num curso compartilhado comigo (hoje recusa curso de outro tenant).
- XP: `xp_events.tenant_id` = tenant do **aluno** (não do dono do curso) — preserva ranking por tenant. ⚠️ `evaluations.tenant_id`/`evaluation_submissions.tenant_id` são denormalizados pro tenant do dono; o XP não pode herdar daí.
- Revogar colaborador: mantém matrículas/dados dos alunos dele (só remove o acesso de edição). Desmatrícula em cascata foi descartada (destruiria dados de aluno). **A remoção é reversível: a UI oferece "desfazer"** (re-adicionar o mesmo colaborador restaura integralmente o acesso de edição, já que os dados dos alunos dele permaneceram intactos).
- Notificação: ao compartilhar, o professor convidado recebe evento `course_shared` (sino + email), respeitando a config de F9 (`notification_settings`).

**Por quê:** atende o pedido do PO ("um curso, N professores, cada um com seus alunos") com o menor refactor possível e **sem violar o ADR-026** (aluno exclusivo do tenant) nem o ADR-001 (isolamento por coluna): só a autoria de conteúdo cruza tenants; o dado sensível do aluno nunca cruza.

**Relação com outros ADRs:** relaxa parcialmente o pressuposto "curso pertence a 1 tenant e só o owner edita" implícito em ADR-001/ADR-025 — mas **apenas para autoria**; o isolamento de dados de aluno do ADR-026 segue intacto. ADR-025 (owner fixo) continua válido: compartilhar **não** transfere posse.

**A revisar quando:** surgir necessidade de papéis mais finos por colaborador (ex.: read-only), de convite a quem ainda não tem conta, ou de compartilhar também populações de alunos.

### ADR-034 — Cópia de conteúdo é deep copy física e independente, sem dados de aluno
**Decisão (F22/E31, 2026-06-05):** duplicar um curso, copiar uma Core Competence ou copiar uma Competence Unit produz uma **cópia física e independente** (novas rows, novos IDs, arquivos copiados em disco com novos `stored_path`). Editar a cópia nunca afeta a origem. A cópia abrange só **estrutura + conteúdo** (configs do curso, CCs, CUs, conteúdo HTML, anexos, atividades, avaliações, quizzes/questões/opções, learning outcomes). **Não** copia matrículas, entregas, notas, feedback, XP, conquistas nem colaboradores — toda turma nova começa zerada. Cada operação exige confirmação explícita.

**Por quê:** o PO quer reaproveitar conteúdo ao iniciar nova turma e adaptar sem mexer no original; turma nova com dados de aluno herdados não faz sentido (ADR-026: aluno é exclusivo do tenant, e a nova turma é um novo recorte). Cópia física (vs referência compartilhada) garante independência total de edição.

**Implicação:** service novo `CourseCopyService` transacional; helper de storage pra `copy` físico dos arquivos. Sem mudança de schema. O destino de copiar CC/CU é restrito a cursos que o professor pode editar (próprios + compartilhados via ADR-033).

### ADR-035 — Auditoria de conteúdo por curso (revisita ADR-030, escopo restrito)
**Decisão (F24/E33, 2026-06-05):** introduz a tabela `course_audit_log` registrando **apenas ações de estrutura e conteúdo** dos professores (create/update/delete de Core Competence, Competence Unit, conteúdo, atividade, avaliação) por curso. Cada registro guarda: curso, professor-autor, ação, tipo de entidade, id da entidade e um **rótulo-snapshot** do nome (pra entidade já excluída continuar legível) + timestamp. Visível a qualquer professor com acesso ao curso (dono + colaboradores). **Fora do escopo:** matrículas, notas, feedback, ações de aluno, login. Sem backfill (registra a partir da ativação); retenção indefinida no MVP da feature.

**Por quê:** o ADR-030 dispensou audit log porque havia 1 professor por tenant e nada compartilhado — rastro via git/logs/dados bastava. A F23 muda a premissa: com 2+ professores editando o **mesmo** conteúdo, "quem mexeu no quê" vira informação operacional necessária. O escopo restrito a conteúdo (não a todo evento de domínio que o E12 original previa) mantém o custo baixo e o foco no problema real.

**Relação com ADR-030:** ADR-030 fica **parcialmente superado** — o veto a `audit_log` genérico/global do MVP continua de pé; abre-se exceção pontual para auditoria **de conteúdo por curso** motivada pela autoria compartilhada. Não ressuscita o Epic E12 inteiro (rota `/admin/audit` global, `audit()` genérico, catálogo de eventos de domínio) — só o recorte de conteúdo por curso.

**A revisar quando:** (a) o volume exigir cron de retenção; (b) o PO pedir auditoria de matrículas/notas/ações de aluno; (c) houver demanda de auditoria global (aí reabrir a discussão do ADR-030 por inteiro).

### ADR-036 — Toggle "ver todos os alunos" no curso compartilhado é só leitura agregada
**Decisão (F25/E34, 2026-06-08):** num curso compartilhado (ADR-033), cada professor pode **opcionalmente** ver os alunos de **todos** os professores do curso (por `course_id`) nas telas de **roster, progresso, métricas e matriz**. O controle é um **toggle com preferência fixa por professor**, **default = "só meus alunos"** (comportamento atual, preserva privacidade). O acesso "ver todos" é **estritamente leitura agregada**: correção, notas e entregas **continuam restritas aos próprios alunos** (por tenant) — um professor nunca abre a entrega/nota de aluno de outro professor, nem gere aluno de outro tenant. Nas visões agregadas, alunos de outros professores aparecem na lista/matriz **sem links acionáveis** de perfil/entrega.

**Por quê:** o PO quer uma visão consolidada da turma compartilhada sem abrir mão do isolamento de escrita. Separar "visibilidade de leitura agregada" de "gestão por tenant" mantém a **regra de ouro do ADR-026/ADR-001** (dado sensível de aluno e toda escrita ficam por tenant) enquanto entrega a visão pedida.

**Mecânica:** preferência booleana por professor (`users.shared_course_show_all_students`, default 0). Só afeta cursos compartilhados (curso normal ignora). Os models de leitura (`CuRoster`, `Enrollment::listByCourse`, `CourseMetrics`, `CourseMatrix`) ganham modo dual: filtrar por tenant do aluno (default) **ou** por `course_id` (ver todos). Ranking de tenant inalterado; ranking do curso já agrega todos (E32-05), independente do toggle. Os caminhos de **correção/entrega permanecem inalterados** (sempre por tenant).

**A revisar quando:** o PO pedir gestão (não só leitura) cross-tenant — aí reabrir a discussão do isolamento de dados de aluno.

### ADR-037 — Widgets: sandbox de origem nula, biblioteca compartilhada no curso
**Decisão (F26/E35, 2026-06-08):** professores cadastram **widgets** (mini-apps interativas num `.zip` com `index.html` na raiz) e os inserem no conteúdo das CUs. Detalhamento em `doc/24-widgets.md`. Decisões-chave:

- **Isolamento:** o widget roda sempre dentro de `<iframe sandbox="allow-scripts">` **sem `allow-same-origin`** → origem opaca/nula; o JS do professor **não** acessa cookies/sessão/`localStorage`/DOM do LMS. Reforçado por **CSP restritiva** na resposta. (Subdomínio dedicado por origem real foi avaliado e adiado — exige DNS/vhost; o sandbox de origem nula basta pro MVP.) HTML sanitizado puro foi descartado: mataria o JS interativo (caso de uso central).
- **Render configurável no cadastro:** `inline` (embutido no conteúdo) ou `window` (ícone → nova janela isolada).
- **Storage fora do document root** (`storage/`), servido por endpoint PHP de passthrough (content-type allowlist, `nosniff`, anti path-traversal). Mantém o caminho aberto pra migração futura a GCS (não acopla a `public/uploads/`).
- **Upload seguro:** limite de MB, `finfo` de zip, **zip-slip guard**, allowlist de extensões internas (rejeita `.php`/executáveis), exige `index.html`.
- **Biblioteca compartilhada no curso:** o widget pertence ao tenant que o cadastrou (reutilizável nos cursos dele); num curso compartilhado o picker oferece os widgets de **todos os colaboradores** do curso. Editar/remover a definição = só o dono; inserir no conteúdo = qualquer professor com acesso ao curso.
- **Integração:** placeholder `[[widget:ID]]` no conteúdo (sobrevive ao HTML Purifier, sem relaxar a allowlist de iframes); expandido no render pro aluno. Acesso ao serving exige sessão + acesso ao conteúdo (professor do curso ou aluno matriculado).

**Por quê:** entrega interatividade rica (calculadoras, simuladores) sem abrir vetor de XSS/sequestro de sessão num SaaS multi-tenant. O sandbox de origem nula é a contenção primária; o storage fora do webroot e a allowlist de extensões fecham o vetor de execução de código no servidor.

**Relação com outros ADRs:** complementa o ADR-009 (TinyMCE) e a sanitização de conteúdo (doc 05) — o widget é um canal **separado e contido** pra JS, enquanto o conteúdo HTML segue sanitizado sem `<script>`. Alinha com `project_future_gcs_storage` (storage desacoplado).

**A revisar quando:** (a) volume/quotas de storage por tenant; (b) necessidade de versionamento de widget; (c) picker ruidoso em curso compartilhado → marcar disponibilidade por curso (junção explícita); (d) demanda por origem física isolada (subdomínio).

### ADR-038 — Formato de curso versionado: V1 clássico e V2 trilha, escolhido na criação e imutável
**Decisão (F27/E36, 2026-09-01):** `courses.structure_version` (`1` = clássico, `2` = trilha) define como o conteúdo da Unidade é organizado. **Todo curso existente é V1** (`DEFAULT 1`) e continua rodando pelo código atual, sem uma linha alterada. O formato é escolhido **na criação** e é **imutável** depois — a tela de edição mostra badge, e `Course::update()` não inclui a coluna no `SET`.

- **V1 (clássico):** a CU tem **uma** página de conteúdo (`contents`, `UNIQUE` por CU) + N atividades + até 1 avaliação, tudo empilhado numa tela.
- **V2 (trilha):** a CU vira uma sequência navegável — capa → lição → exercício → lição → … → avaliação no fim. A capa **reaproveita o registro de `contents`** que já existia: zero migração de dado, e o mesmo editor/sanitizador/anexos.
- **Ordem única sem tabela polimórfica:** `lessons.position` e `activities.position` compartilham o **mesmo espaço de numeração** dentro da CU; a trilha é um `UNION ALL` ordenado por `position`, e o reorder reescreve `1..N` das duas tabelas numa transação. A **avaliação não entra na numeração** — é 1 por CU (ADR-007) e sempre última, então a posição dela é implícita.
- **Navegação do aluno é livre dentro da CU:** uma vez que a unidade está desbloqueada, ele pula por qualquer item pela timeline. A trava sequencial continua **só entre CCs/CUs**.
- **Conclusão de lição é explícita:** o aluno marca "concluí" (só abrir não conta), o que alimenta o % da CU e credita XP (`lessons.xp_value`, `xp_events.source_type='lesson'`).

**Por quê:** conteúdo longo numa página só vira parede de scroll, e não havia como intercalar exercício no meio da explicação. Versionar em vez de migrar foi a exigência do PO — cursos em andamento não podem mudar de comportamento no meio do semestre. A imutabilidade evita o estado sem semântica definida: uma CU V2 tem lições que a tela V1 não sabe renderizar.

**Alternativa descartada:** tabela `cu_items` polimórfica para a ordem. Resolveria o mesmo problema com um join a mais em toda leitura da trilha; o espaço de numeração compartilhado entrega a mesma garantia porque o reorder já reescreve tudo de uma vez. **Custo aceito:** criar lição ou atividade em V2 exige calcular a próxima posição como `MAX` sobre as **duas** tabelas (`UnitTrackService::nextPosition`) — esquecer isso gera empate de `position` e ordem indefinida.

**A revisar quando:** (a) o PO pedir conversão de V1 para V2 num curso com conteúdo; (b) surgir um terceiro formato (aí `structure_version` já é `TINYINT`, não ENUM, de propósito); (c) a trilha precisar de itens além de lição/exercício/avaliação.

### ADR-039 — Desbloqueio manual de CU é override do gate, não mudança de estrutura
**Decisão (F27/E36, 2026-09-01):** o professor pode liberar uma **CU específica** para um **aluno específico**, furando a trava sequencial. Vale nos **dois formatos** de curso (V1 e V2) — é uma tabela (`student_cu_unlocks`) consultada dentro de `course_progression_state()`, o único ponto onde a visibilidade de CC/CU é calculada.

- O que o desbloqueio **não** faz: não marca a CU como concluída, não afeta o cálculo de `%`, e não libera as CUs seguintes.
- **A CC que contém a CU liberada também sai de `hidden`** — a página do curso pula CC `hidden` inteira antes de olhar as CUs, então sem isso a unidade liberada nunca renderizaria. As **outras** CUs dessa CC seguem `hidden`: libera-se a unidade, não a competência.
- **Isolamento em duas pernas** no endpoint: autoria da CU via `effective_authoring_tenant` (ADR-033) **e** posse do aluno via `current_tenant_id()` + matrícula no curso (ADR-036). Em curso compartilhado o professor só libera os **próprios** alunos.
- Recusado explicitamente em curso arquivado e em `cc_mode='free'`, onde não há trava para furar.
- `granted_by_user_id` é `SET NULL`: remover o professor não pode re-trancar a CU na cara do aluno.

**Por quê:** em curso presencial o professor precisa acomodar o aluno adiantado, o que repete conteúdo, o que voltou de licença — sem afrouxar a regra para a turma toda. Concentrar no helper faz as três páginas que barram URL direta (`student/cu`, `student/activity`, `student/evaluation`) herdarem o comportamento sem alteração nenhuma nelas.

**A revisar quando:** (a) o PO pedir granularidade por lição/exercício em vez de CU inteira; (b) surgir demanda de liberar por grupo em vez de por aluno; (c) o número de desbloqueios exigir uma tela própria de gestão (hoje vive na grade de `/teacher/cu/{id}`).

## Pendências em aberto

Nenhuma no momento. (F22–F24 tiveram suas dúvidas resolvidas no story breakdown de 2026-06-05: revogação de colaborador é reversível com "desfazer"; notificação `course_shared` confirmada; cópia mantém `published` da origem; auditoria registra todo save de conteúdo. F25–F26 consolidadas com o PO em 2026-06-08: toggle "ver todos" é só leitura agregada — ver ADR-036; widgets em iframe sandbox de origem nula, biblioteca compartilhada no curso — ver ADR-037 e `doc/24-widgets.md`.)
