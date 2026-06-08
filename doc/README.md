# Documentação — LMS

Plataforma LMS mini-SaaS para apoiar cursos presenciais, com entregas digitais, gamificação e ranking.

Esta pasta é o ponto de entrada para entender o projeto. Cada arquivo é curto e focado, para servir de base ao _story breakdown_ posterior.

## Índice

| # | Documento | Tema |
|---|-----------|------|
| 00 | [Visão geral](00-visao-geral.md) | Pitch, objetivos, não-escopo, personas |
| 01 | [Glossário](01-glossario.md) | Termos do domínio |
| 02 | [Papéis e permissões](02-papeis-e-permissoes.md) | Super-admin, professor, aluno (multi-tenant) |
| 03 | [Domínio e hierarquia](03-dominio-e-hierarquia.md) | Entidades e relacionamentos |
| 04 | [Fluxos de usuário](04-fluxos-de-usuario.md) | Jornadas principais do professor e do aluno |
| 05 | [Conteúdo e editor](05-conteudo-e-editor.md) | CMS HTML, embeds, anexos |
| 06 | [Atividades e avaliações](06-atividades-e-avaliacoes.md) | Tipos, entregas, reenvio, arquivos |
| 07 | [Código e Judge0](07-codigo-e-judge0.md) | Linguagens, compilador online |
| 08 | [Gamificação e ranking](08-gamificacao-e-ranking.md) | XP, rankings, grupos, ano |
| 09 | [Notificações](09-notificacoes.md) | Gatilhos, email, sininho |
| 10 | [Progresso e dashboards](10-progresso-e-dashboards.md) | Visão do aluno e do professor |
| 11 | [Requisitos técnicos](11-requisitos-tecnicos.md) | Stack, infra, i18n, mobile |
| 12 | [Modelo de dados](12-modelo-de-dados.md) | Rascunho de tabelas |
| 13 | [Integrações](13-integracoes.md) | Judge0, SMTP, embeds |
| 14 | [Decisões e pendências](14-decisoes-e-pendencias.md) | ADRs curtos, dúvidas abertas |
| 15 | [Roadmap pós-MVP](15-roadmap-pos-mvp.md) | F1-F26 — próximas funcionalidades aprovadas pelo PO |
| 24 | [Widgets interativos](24-widgets.md) | Mini-apps em zip (HTML+JS) no conteúdo, sandbox de origem nula (F26) |
| 99 | [Pendências técnicas](99-pendencias-tecnicas.md) | Checklist de validações/config para o deploy |

## Como usar

- Leia `00-visao-geral.md` primeiro.
- Para entender _o que_ será construído: `03`, `04`, `06`, `08`.
- Para entender _como_ será construído: `11`, `12`, `13`.
- Dúvidas ainda em aberto estão em `14`.
