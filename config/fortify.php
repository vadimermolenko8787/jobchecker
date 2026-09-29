<?php

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
    ],
    'views' => true,

    // Only login and logout: the app has a single user created with `php artisan user:create`,
    // so registration, password reset by email and profile pages would be dead weight.
    'features' => [],
];
