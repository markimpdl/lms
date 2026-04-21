# 01 — Glossário

Termos usados em todo o projeto. Quando houver dúvida sobre um termo, este é o documento-fonte.

| Termo | Definição |
|-------|-----------|
| **Tenant** | Espaço de dados isolado de um professor. Um tenant contém todos os cursos, alunos e conteúdos de um único professor. |
| **Super-admin** | Dono da plataforma. Gerencia tenants (professores) e configurações globais. |
| **Professor** | Usuário dono de um tenant. Cria cursos e gerencia alunos. |
| **Aluno** | Usuário final. Pode estar matriculado em múltiplos cursos (mesmo em tenants diferentes) com progresso independente. |
| **Curso** | Container mais alto. Ex.: "Desenvolvimento Web 2026". Pertence a um tenant. |
| **Core Competence (CC)** | Agrupamento temático dentro de um curso. Ex.: "Back-end", "Front-end". |
| **Competence Unit (CU)** | Matéria individual dentro de uma CC. É a unidade onde o aluno consome conteúdo e entrega trabalhos. Ex.: "PHP básico". |
| **Conteúdo** | Página HTML de uma CU, escrita pelo professor em editor tipo CMS. Pode ter embeds de vídeo e anexos. |
| **Atividade** | Tarefa durante a disciplina. Sem nota — tem apenas feedback qualitativo. Tipos: quiz, pesquisa, formulário, projeto, código. Entrega é única. |
| **Avaliação** | Prova final da CU, apresentada como PDF para o aluno. Aluno entrega arquivo de resposta. Recebe nota 0–10. Pode ter reenvio se o professor autorizar no feedback. |
| **Aprovação** | Avaliação com nota ≥ 6/10. |
| **XP** | Pontos de gamificação, separados da nota acadêmica. Atividades dão XP fixo ao entregar; avaliações dão XP somente se nota ≥ 8/10 (80%). |
| **Grupo** | Rótulo para agrupar alunos em rankings. Ex.: "Skills Challenge", "Skills Hub", "Emirates Skills". Um aluno pode estar em múltiplos grupos. |
| **Ano** | Ano civil (2025, 2026…), usado como filtro de ranking. |
| **Progresso** | Porcentagem de CUs de um curso em que o aluno está aprovado (avaliação ≥ 6). |
| **Feedback** | Comentário textual do professor sobre uma entrega. Em avaliações, pode incluir a nota e a permissão de reenvio. |
| **Notificação** | Alerta ao aluno quando há feedback, nova atividade ou evento. Entregue por email e no sininho in-app. |
| **Sininho** | Lista de notificações in-app, no topo da interface. |
