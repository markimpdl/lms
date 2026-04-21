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

    // Reset email (language follows the recipient — ADR-014)
    'email.reset.subject'   => 'LMS — password reset',
    'email.reset.greeting'  => 'Hello, :name',
    'email.reset.intro'     => 'We received a request to reset your account password. Click the button below (or copy the link into your browser):',
    'email.reset.cta'       => 'Reset password',
    'email.reset.expires'   => 'This link expires in :hours hour(s) and can only be used once.',
    'email.reset.disregard' => 'If you did not request this, please ignore this email — your current password remains valid.',
    'email.reset.signature' => 'LMS Team',
];
