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
    'error.403'          => 'Acesso negado',
    'error.403_message'  => 'Você não tem permissão para acessar esta página.',

    // Middleware de sessão (E1-05)
    'auth.account_deactivated' => 'Sua conta foi desativada. Fale com o administrador.',
    'auth.session_invalidated' => 'Sua sessão expirou porque a senha foi alterada. Entre novamente.',

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

    // Listagem administrativa de professores (E2-01)
    'admin.teachers.title'             => 'Professores',
    'admin.teachers.new'               => 'Novo professor',
    'admin.teachers.search'            => 'Buscar',
    'admin.teachers.search_placeholder'=> 'Nome ou email',
    'admin.teachers.status'            => 'Status',
    'admin.teachers.language'          => 'Idioma',
    'admin.teachers.name'              => 'Nome',
    'admin.teachers.courses'           => 'Cursos ativos',
    'admin.teachers.students'          => 'Alunos únicos',
    'admin.teachers.created_at'        => 'Criado em',
    'admin.teachers.last_login'        => 'Último login',
    'admin.teachers.never'             => 'nunca',
    'admin.teachers.active'            => 'Ativo',
    'admin.teachers.inactive'          => 'Inativo',
    'admin.teachers.filter_all'        => 'Todos',
    'admin.teachers.filter_active'     => 'Apenas ativos',
    'admin.teachers.filter_inactive'   => 'Apenas inativos',
    'admin.teachers.empty'             => 'Ainda não há professores cadastrados.',
    'admin.teachers.empty_cta'         => 'Cadastrar primeiro professor',
    'admin.teachers.no_results'        => 'Nenhum professor corresponde aos filtros.',
    'admin.teachers.actions'           => 'Ações',
    'admin.teachers.open'              => 'Abrir',
    'admin.teachers.deactivate'        => 'Desativar',
    'admin.teachers.reactivate'        => 'Reativar',
    'admin.teachers.pagination'        => 'Paginação',
    'admin.teachers.prev'              => 'Anterior',
    'admin.teachers.next'              => 'Próxima',
    'admin.teachers.page_of'           => 'Página :page de :total',

    // Cadastro de professor (E2-02)
    'admin.teachers.form.title'            => 'Novo professor',
    'admin.teachers.form.name'             => 'Nome completo',
    'admin.teachers.form.password'         => 'Senha inicial',
    'admin.teachers.form.password_hint'    => 'Mínimo de 8 caracteres. O professor pode trocar após o primeiro login.',
    'admin.teachers.form.tenant_name'      => 'Nome do espaço (tenant)',
    'admin.teachers.form.tenant_hint'      => 'Pode ser o mesmo nome do professor ou um nome institucional.',
    'admin.teachers.form.send_email'       => 'Enviar credenciais por email',
    'admin.teachers.form.submit'           => 'Cadastrar professor',
    'admin.teachers.form.has_errors'       => 'Revise os campos destacados abaixo.',
    'admin.teachers.form.smtp_off_notice'  => 'O envio de email ainda não está configurado — as credenciais aparecerão na tela após o cadastro.',
    'admin.teachers.form.err.name'            => 'Nome precisa ter entre 3 e 120 caracteres.',
    'admin.teachers.form.err.email_invalid'   => 'Informe um email válido.',
    'admin.teachers.form.err.email_taken'     => 'Já existe um usuário com esse email.',
    'admin.teachers.form.err.password_min'    => 'Senha precisa ter ao menos 8 caracteres.',
    'admin.teachers.form.err.language'        => 'Idioma inválido.',
    'admin.teachers.form.err.tenant_name'     => 'Nome do espaço precisa ter entre 3 e 120 caracteres.',
    'admin.teachers.form.err.tenant_taken'    => 'Já existe um espaço com esse nome.',

    'admin.teachers.created'            => 'Professor :name criado com sucesso.',
    'admin.teachers.creds_title'        => 'Credenciais de :name',
    'admin.teachers.creds_smtp_off'     => 'Copie as credenciais abaixo e entregue ao professor — este painel some ao recarregar.',
    'admin.teachers.creds_opted_out'    => 'Você optou por não enviar email. Copie as credenciais antes de sair da tela.',

    // Edição de professor (E2-03)
    'admin.teachers.edit.title'      => 'Editar professor',
    'admin.teachers.edit.metadata'   => 'Resumo da conta',
    'admin.teachers.edit.status'     => 'Status',
    'admin.teachers.edit.updated'    => 'Dados de :name atualizados.',
    'admin.teachers.email_immutable' => 'Email não pode ser alterado (ADR-021).',

    // Email de recuperação (idioma segue o do destinatário — ADR-014)
    'email.reset.subject'   => 'LMS — redefinição de senha',
    'email.reset.greeting'  => 'Olá, :name',
    'email.reset.intro'     => 'Recebemos uma solicitação para redefinir a senha da sua conta. Clique no botão abaixo (ou copie o link para o navegador):',
    'email.reset.cta'       => 'Redefinir senha',
    'email.reset.expires'   => 'Este link expira em :hours hora(s) e só pode ser usado uma vez.',
    'email.reset.disregard' => 'Se não foi você que solicitou, ignore este email — sua senha atual segue válida.',
    'email.reset.signature' => 'Equipe LMS',
];
