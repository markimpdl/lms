<?php
declare(strict_types=1);

return [
    // App
    'app.title'          => 'LMS',
    'app.bootstrap_ok'   => 'Bootstrap OK. Timezone: :tz',
    'app.demo_flash_ok'  => 'Flash is working — this message was carried over from a previous session.',
    'app.demo_flash_btn' => 'Test flash',

    // Common
    'common.save'        => 'Save',
    'common.cancel'      => 'Cancel',
    'common.back'        => 'Back',
    'common.edit'        => 'Edit',
    'common.delete'      => 'Delete',
    'common.confirm'     => 'Confirm',
    'common.welcome'     => 'Welcome',
    'common.language'    => 'Language',
    'common.yes'         => 'Yes',
    'common.no'          => 'No',
    'common.loading'     => 'Loading...',
    'common.search'      => 'Search',
    'common.required'    => 'Required',

    // Auth
    'auth.login'         => 'Log in',
    'auth.logout'        => 'Log out',
    'auth.email'         => 'E-mail',
    'auth.password'      => 'Password',
    'auth.forgot'        => 'Forgot my password',
    'auth.invalid'       => 'Invalid e-mail or password.',
    'auth.forbidden'     => 'Access denied.',

    // Navbar
    'nav.logout'         => 'Log out',
    'nav.profile'        => 'My profile',
    'nav.dashboard'      => 'Go to dashboard',

    // Auth extras (E1-01)
    'auth.rate_limited'  => 'Too many attempts. Please try again in a few minutes.',

    // Dashboards (stubs in E1-01; replaced in later epics)
    'dashboard.teacher.title'   => 'Teacher dashboard',
    'dashboard.teacher.welcome' => 'Welcome, :name.',
    'dashboard.student.title'   => 'Student dashboard',
    'dashboard.student.welcome' => 'Welcome, :name.',
    'dashboard.admin.title'     => 'Super-admin dashboard',
    'dashboard.admin.welcome'   => 'Welcome, :name.',
    'dashboard.stub_notice'     => 'This screen is a placeholder — real content lands in later epics.',

    // Errors
    'error.404'          => 'Page not found',
    'error.404_message'  => 'The page you tried to reach does not exist or has been moved.',
    'error.403'          => 'Access denied',
    'error.403_message'  => 'You do not have permission to access this page.',

    // Session middleware (E1-05)
    'auth.account_deactivated' => 'Your account has been deactivated. Please contact the administrator.',
    'auth.session_invalidated' => 'Your session expired because the password was changed. Please log in again.',

    // Password reset (E1-03) — forgot
    'auth.forgot.title'            => 'Forgot my password',
    'auth.forgot.instruction'      => 'Enter the registered email. If an account exists, we will send a link to reset the password.',
    'auth.forgot.submit'           => 'Send link',
    'auth.forgot.generic_response' => 'If the email is registered, you will receive a link in a few minutes. Check your spam folder too.',

    // Password reset — reset
    'auth.reset.title'        => 'Set a new password',
    'auth.reset.new_password' => 'New password',
    'auth.reset.confirm'      => 'Confirm new password',
    'auth.reset.submit'       => 'Update password',
    'auth.reset.min_length'   => 'The new password must have at least 8 characters.',
    'auth.reset.mismatch'     => 'The passwords do not match.',
    'auth.reset.invalid_link' => 'Invalid or expired link. Please request a new one.',
    'auth.reset.success'      => 'Password updated. Log in with your new password.',

    // Profile (E1-04)
    'profile.title'                   => 'My profile',
    'profile.tab_data'                => 'Details',
    'profile.tab_password'            => 'Password',
    'profile.name'                    => 'Name',
    'profile.email_readonly'          => 'Email cannot be changed',
    'profile.lang_pt'                 => 'Portuguese',
    'profile.lang_en'                 => 'English',
    'profile.current_password'        => 'Current password',
    'profile.new_password'            => 'New password',
    'profile.confirm_password'        => 'Confirm new password',
    'profile.change_password'         => 'Change password',
    'profile.updated'                 => 'Profile updated.',
    'profile.password_changed'        => 'Password changed successfully.',
    'profile.error.name'              => 'Enter a name between 1 and 150 characters.',
    'profile.error.language'          => 'Invalid language.',
    'profile.error.current_wrong'     => 'Current password is incorrect.',
    'profile.error.password_min'      => 'The new password must have at least 8 characters.',
    'profile.error.password_mismatch' => 'The passwords do not match.',

    // Teacher admin listing (E2-01)
    'admin.teachers.title'             => 'Teachers',
    'admin.teachers.new'               => 'New teacher',
    'admin.teachers.search'            => 'Search',
    'admin.teachers.search_placeholder'=> 'Name or email',
    'admin.teachers.status'            => 'Status',
    'admin.teachers.language'          => 'Language',
    'admin.teachers.name'              => 'Name',
    'admin.teachers.courses'           => 'Active courses',
    'admin.teachers.students'          => 'Unique students',
    'admin.teachers.created_at'        => 'Created',
    'admin.teachers.last_login'        => 'Last login',
    'admin.teachers.never'             => 'never',
    'admin.teachers.active'            => 'Active',
    'admin.teachers.inactive'          => 'Inactive',
    'admin.teachers.filter_all'        => 'All',
    'admin.teachers.filter_active'     => 'Active only',
    'admin.teachers.filter_inactive'   => 'Inactive only',
    'admin.teachers.empty'             => 'No teachers registered yet.',
    'admin.teachers.empty_cta'         => 'Register first teacher',
    'admin.teachers.no_results'        => 'No teachers match the filters.',
    'admin.teachers.actions'           => 'Actions',
    'admin.teachers.open'              => 'Open',
    'admin.teachers.deactivate'        => 'Deactivate',
    'admin.teachers.reactivate'        => 'Reactivate',
    'admin.teachers.pagination'        => 'Pagination',
    'admin.teachers.prev'              => 'Previous',
    'admin.teachers.next'              => 'Next',
    'admin.teachers.page_of'           => 'Page :page of :total',

    // Teacher creation (E2-02)
    'admin.teachers.form.title'            => 'New teacher',
    'admin.teachers.form.name'             => 'Full name',
    'admin.teachers.form.password'         => 'Initial password',
    'admin.teachers.form.password_hint'    => 'At least 8 characters. The teacher can change it after the first login.',
    'admin.teachers.form.tenant_name'      => 'Workspace (tenant) name',
    'admin.teachers.form.tenant_hint'      => 'Can be the teacher\'s name or an institutional name.',
    'admin.teachers.form.send_email'       => 'Send credentials by email',
    'admin.teachers.form.submit'           => 'Create teacher',
    'admin.teachers.form.has_errors'       => 'Please review the highlighted fields below.',
    'admin.teachers.form.smtp_off_notice'  => 'Email delivery is not configured yet — credentials will be shown on screen after creation.',
    'admin.teachers.form.err.name'            => 'Name must be between 3 and 120 characters.',
    'admin.teachers.form.err.email_invalid'   => 'Enter a valid email.',
    'admin.teachers.form.err.email_taken'     => 'A user with this email already exists.',
    'admin.teachers.form.err.password_min'    => 'Password must be at least 8 characters long.',
    'admin.teachers.form.err.language'        => 'Invalid language.',
    'admin.teachers.form.err.tenant_name'     => 'Workspace name must be between 3 and 120 characters.',
    'admin.teachers.form.err.tenant_taken'    => 'A workspace with this name already exists.',

    'admin.teachers.created'            => 'Teacher :name created successfully.',
    'admin.teachers.creds_title'        => 'Credentials for :name',
    'admin.teachers.creds_smtp_off'     => 'Copy the credentials below and hand them to the teacher — this panel disappears on refresh.',
    'admin.teachers.creds_opted_out'    => 'You opted not to send an email. Copy the credentials before leaving this page.',

    // Teacher edit (E2-03)
    'admin.teachers.edit.title'      => 'Edit teacher',
    'admin.teachers.edit.metadata'   => 'Account summary',
    'admin.teachers.edit.status'     => 'Status',
    'admin.teachers.edit.updated'    => 'Details for :name updated.',
    'admin.teachers.email_immutable' => 'Email cannot be changed (ADR-021).',

    // Reset email (language follows the recipient — ADR-014)
    'email.reset.subject'   => 'LMS — password reset',
    'email.reset.greeting'  => 'Hello, :name',
    'email.reset.intro'     => 'We received a request to reset your account password. Click the button below (or copy the link into your browser):',
    'email.reset.cta'       => 'Reset password',
    'email.reset.expires'   => 'This link expires in :hours hour(s) and can only be used once.',
    'email.reset.disregard' => 'If you did not request this, please ignore this email — your current password remains valid.',
    'email.reset.signature' => 'LMS Team',
];
