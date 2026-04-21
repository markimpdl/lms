# 02 — Papéis e permissões

A plataforma é **multi-tenant**: cada professor é um tenant isolado. Professores nunca veem dados de outros professores, mesmo que compartilhem o mesmo banco físico.

## Papéis

### Super-admin
Identidade única, fora da estrutura de tenant. Tem acesso à área administrativa da plataforma.

Pode:
- Criar, editar e desativar contas de professor.
- Habilitar/desabilitar o cadastro público de professores.
- Ver métricas agregadas (total de cursos, alunos, entregas) — sem ler conteúdo dos tenants.
- Gerenciar configurações globais (idiomas, SMTP, credenciais do Judge0).

Não pode:
- Editar conteúdo dos cursos dos professores.
- Fazer entregas ou dar feedback no lugar de um professor.

### Professor (dono do tenant)
Pode, dentro do próprio tenant:
- Criar, editar e arquivar cursos, core competences e competence units.
- Escrever conteúdo HTML das CUs com vídeos e anexos.
- Criar atividades e avaliações; abrir ou fechar entrega.
- Cadastrar, editar e desativar alunos (nome, email, senha inicial).
- Matricular alunos em cursos.
- Criar grupos e atribuir alunos a grupos.
- Ler todas as entregas dos seus alunos, dar feedback, atribuir nota, permitir reenvio.
- Configurar XP de cada atividade e avaliação.
- Ver dashboards de progresso e rankings.

Não pode:
- Ver dados de alunos ou cursos de outros professores.
- Alterar o próprio email sem confirmação.

### Aluno
Pode:
- Fazer login e redefinir a própria senha.
- Ver apenas os cursos em que está matriculado.
- Ler conteúdo, entregar atividades e avaliações (quando a entrega está aberta).
- Ler feedback recebido.
- Reenviar avaliação quando o professor autoriza no feedback.
- Ver o próprio progresso e os rankings.

Não pode:
- Ver entregas de outros alunos.
- Ver conteúdo de cursos em que não está matriculado.
- Editar a nota recebida ou o conteúdo criado pelo professor.

## Multi-tenant: regra de isolamento

Toda consulta SQL feita por um professor **deve** filtrar por `tenant_id`. Toda consulta feita por um aluno deve restringir aos cursos em que ele tem matrícula ativa. Esta regra se aplica em queries, APIs internas, uploads e downloads de arquivos.
