<?php
declare(strict_types=1);

return [
    // App
    'app.title'          => 'LMS',
    'app.bootstrap_ok'   => 'Bootstrap OK. Fuso horário: :tz',
    'app.demo_flash_ok'  => 'Flash funcionando — esta mensagem veio de uma sessão anterior.',
    'app.demo_flash_btn' => 'Testar flash',

    // Common
    'common.save'        => 'Salvar',
    'common.cancel'      => 'Cancelar',
    'common.back'        => 'Voltar',
    'common.edit'        => 'Editar',
    'common.delete'      => 'Excluir',
    'common.confirm'     => 'Confirmar',
    'common.welcome'     => 'Bem-vindo(a)',
    'common.language'    => 'Idioma',
    'common.yes'         => 'Sim',
    'common.no'          => 'Não',
    'common.loading'     => 'Carregando...',
    'common.search'      => 'Buscar',
    'common.required'    => 'Obrigatório',

    // Auth
    'auth.login'         => 'Entrar',
    'auth.logout'        => 'Sair',
    'auth.email'         => 'E-mail',
    'auth.password'      => 'Senha',
    'auth.forgot'        => 'Esqueci minha senha',
    'auth.invalid'       => 'E-mail ou senha inválidos.',
    'auth.forbidden'     => 'Acesso negado.',

    // Navbar
    'nav.logout'         => 'Sair',
    'nav.profile'        => 'Meu perfil',
    'nav.dashboard'      => 'Ir para o painel',

    // Auth extras (E1-01)
    'auth.rate_limited'  => 'Muitas tentativas. Tente novamente em alguns minutos.',

    // Dashboards (stubs em E1-01; substituídos em épicos dedicados)
    'dashboard.teacher.title'   => 'Painel do professor',
    'dashboard.teacher.welcome' => 'Bem-vindo(a), :name.',
    'dashboard.student.title'   => 'Painel do aluno',
    'dashboard.student.welcome' => 'Bem-vindo(a), :name.',
    'dashboard.admin.title'     => 'Painel do super-admin',
    'dashboard.admin.welcome'   => 'Bem-vindo(a), :name.',
    'dashboard.stub_notice'     => 'Esta tela é um placeholder — o conteúdo real é implementado em épicos posteriores.',

    // Erros
    'error.404'          => 'Página não encontrada',
    'error.404_message'  => 'A página que você tentou acessar não existe ou foi movida.',
];
