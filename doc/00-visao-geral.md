# 00 — Visão geral

## O que é

Um LMS (Learning Management System) como **mini-SaaS multi-professor**, para apoiar cursos presenciais de programação com entregas digitais, feedback estruturado, progresso visível e ranking gamificado.

A plataforma será usada inicialmente pelo autor (professor nos Emirados Árabes Unidos) e um colega, antes de abrir cadastro público para outros professores.

## Por que

- Curso acontece presencialmente, mas precisa de canal digital para alunos entregarem atividades e avaliações.
- Feedback por email/WhatsApp é difícil de organizar e perde histórico.
- O professor precisa ver o progresso de cada aluno por matéria, core competence e curso sem abrir planilhas manuais.
- Ranking e XP criam motivação extrínseca para engajar a turma.

## Personas

1. **Super-admin** — o dono da plataforma. Gerencia cadastros de professores, habilita/desabilita o cadastro público, acompanha uso geral.
2. **Professor** — cria seu curso, conteúdo, atividades e avaliações. Cadastra seus alunos, dá feedback, vê progresso e ranking da turma.
3. **Aluno** — acessa conteúdo, entrega atividades e avaliações, recebe feedback e nota, vê seu progresso e posição no ranking.

## Objetivos

- Entregas digitais de atividades (quiz, pesquisa, formulário, projeto) e avaliações (nota 0–10).
- Editor de conteúdo tipo CMS com vídeo embutido (YouTube/Vimeo).
- Gamificação via XP e rankings (geral, 7 dias, 30 dias) por grupo e por ano civil.
- Notificações por email e sininho in-app.
- Integração opcional com compilador online (Judge0) para atividades de código.
- Experiência mobile-first.
- Multi-tenant: cada professor só enxerga seus próprios dados.

## Não-escopo (nesta fase)

- Cobrança, billing ou planos pagos.
- Videoconferência embutida.
- Autocorreção automatizada de código (correção inicial é manual).
- Aplicativo nativo (iOS/Android) — web responsiva basta.
- Comunicação aluno↔aluno (chat, fórum).
- SSO (Google/Microsoft) — login por email+senha.
- Relatórios em PDF complexos além das avaliações.

## Escala alvo inicial

- ~10 alunos simultâneos por professor, raramente mais.
- 2–3 professores ativos no primeiro semestre.
- Plano Hostinger revenda com limites altos.
