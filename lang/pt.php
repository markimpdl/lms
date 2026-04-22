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

    // Recuperação de senha (E1-03) — forgot
    'auth.forgot.title'            => 'Esqueci minha senha',
    'auth.forgot.instruction'      => 'Informe o email cadastrado. Se existir uma conta, enviaremos um link para redefinir a senha.',
    'auth.forgot.submit'           => 'Enviar link',
    'auth.forgot.generic_response' => 'Se o email estiver cadastrado, você receberá um link em alguns minutos. Verifique também a caixa de spam.',

    // Recuperação de senha — reset
    'auth.reset.title'        => 'Definir nova senha',
    'auth.reset.new_password' => 'Nova senha',
    'auth.reset.confirm'      => 'Confirme a nova senha',
    'auth.reset.submit'       => 'Atualizar senha',
    'auth.reset.min_length'   => 'A nova senha precisa ter ao menos 8 caracteres.',
    'auth.reset.mismatch'     => 'As senhas digitadas não conferem.',
    'auth.reset.invalid_link' => 'Link inválido ou expirado. Solicite um novo.',
    'auth.reset.success'      => 'Senha atualizada. Faça login com a nova senha.',

    // Perfil (E1-04)
    'profile.title'                   => 'Meu perfil',
    'profile.tab_data'                => 'Dados',
    'profile.tab_password'            => 'Senha',
    'profile.name'                    => 'Nome',
    'profile.email_readonly'          => 'Email não pode ser alterado',
    'profile.lang_pt'                 => 'Português',
    'profile.lang_en'                 => 'Inglês',
    'profile.current_password'        => 'Senha atual',
    'profile.new_password'            => 'Nova senha',
    'profile.confirm_password'        => 'Confirme a nova senha',
    'profile.change_password'         => 'Alterar senha',
    'profile.updated'                 => 'Perfil atualizado.',
    'profile.password_changed'        => 'Senha alterada com sucesso.',
    'profile.error.name'              => 'Informe um nome entre 1 e 150 caracteres.',
    'profile.error.language'          => 'Idioma inválido.',
    'profile.error.current_wrong'     => 'Senha atual incorreta.',
    'profile.error.password_min'      => 'A nova senha precisa ter ao menos 8 caracteres.',
    'profile.error.password_mismatch' => 'As senhas digitadas não conferem.',

    // Email de recuperação (idioma segue o do destinatário — ADR-014)
    'email.reset.subject'   => 'LMS — redefinição de senha',
    'email.reset.greeting'  => 'Olá, :name',
    'email.reset.intro'     => 'Recebemos uma solicitação para redefinir a senha da sua conta. Clique no botão abaixo (ou copie o link para o navegador):',
    'email.reset.cta'       => 'Redefinir senha',
    'email.reset.expires'   => 'Este link expira em :hours hora(s) e só pode ser usado uma vez.',
    'email.reset.disregard' => 'Se não foi você que solicitou, ignore este email — sua senha atual segue válida.',
    'email.reset.signature' => 'Equipe LMS',
];
