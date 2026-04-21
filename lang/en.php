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
];
