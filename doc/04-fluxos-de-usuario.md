# 04 — Fluxos de usuário

Jornadas principais, do ponto de vista do usuário. Cada fluxo gera 1 ou mais histórias no _story breakdown_.

## Professor — setup inicial de um curso

1. Faz login.
2. Cria um curso (nome, descrição, ano, idioma).
3. Adiciona uma ou mais **core competences** ao curso.
4. Dentro de cada CC, cria **competence units**.
5. Abre uma CU e escreve o **conteúdo** (HTML com editor WYSIWYG, embeds YouTube/Vimeo, anexos).
6. Cria **atividades** da CU (título, instrução, tipo, arquivos aceitos, XP, entrega aberta/fechada).
7. Cria a **avaliação** da CU (título, PDF do enunciado, XP, entrega aberta/fechada).

## Professor — cadastrando alunos e grupos

1. Acessa "Alunos" e cadastra nome, email, senha inicial.
2. Cria grupos (ex.: Skills Challenge, Skills Hub, Emirates Skills).
3. Matricula cada aluno em um ou mais cursos.
4. Atribui cada aluno a um ou mais grupos.
5. (Opcional) Envia email de boas-vindas com as credenciais.

## Aluno — consumindo uma unidade

1. Faz login.
2. Vê a lista dos cursos em que está matriculado, cada um com sua barra de progresso.
3. Entra em um curso e navega pelas CCs → CUs.
4. Abre uma CU:
   - Lê o conteúdo (texto, vídeos embutidos, anexos para baixar).
   - Vê as atividades listadas com status (não entregue / entregue / com feedback).
   - Vê a avaliação com status (não entregue / entregue / corrigida com nota).

## Aluno — entregando uma atividade

1. Clica em uma atividade com entrega aberta.
2. Lê as instruções.
3. Anexa arquivo (zip, pdf ou txt, até 3 MB) ou submete código, se for atividade de código.
4. Confirma envio.
5. Recebe notificação in-app + email quando o professor der feedback.

## Professor — dando feedback em atividade

1. Abre a lista de entregas de uma atividade.
2. Baixa o arquivo enviado.
3. Escreve comentário de feedback.
4. Salva. Sistema notifica o aluno e registra XP (valor configurado na atividade).

## Aluno — fazendo a avaliação final

1. Abre a avaliação da CU.
2. Baixa o PDF do enunciado.
3. Anexa arquivo de resposta.
4. Confirma envio.
5. Aguarda correção.

## Professor — corrigindo avaliação

1. Abre a lista de entregas da avaliação.
2. Baixa o arquivo.
3. Atribui nota de 0 a 10.
4. Escreve feedback textual.
5. (Opcional) Marca "permitir nova tentativa".
6. Salva. Sistema notifica aluno; se nota ≥ 8, registra XP.

## Aluno — reenviando avaliação

1. Recebe notificação "reenvio liberado".
2. Abre a avaliação, anexa novo arquivo.
3. Substitui a tentativa anterior como a submissão atual (histórico preservado).

## Aluno — acompanhando ranking

1. Abre a tela de ranking.
2. Filtra por janela (geral / 7 dias / 30 dias), grupo e ano.
3. Vê a posição de todos os alunos listados pelo XP acumulado na janela escolhida.
