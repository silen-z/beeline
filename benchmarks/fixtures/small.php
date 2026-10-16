<?php

declare(strict_types=1);

// A small, realistic application: 22 routes with shared prefixes.
return [
    'routes' => [
        '/',
        '/about',
        '/contact',
        '/login',
        '/logout',
        '/users',
        '/users/me',
        '/users/{id}',
        '/users/{id}/edit',
        '/users/{id}/posts',
        '/users/{id}/posts/{post}',
        '/posts',
        '/posts/{slug}',
        '/posts/{slug}/comments',
        '/tags/{tag}',
        '/search/{query?}',
        '/admin',
        '/admin/users',
        '/admin/settings',
        '/api/status',
        '/api/v1/items/{id}',
        '/assets/{path+}',
    ],
    'requests' => [
        'static' => '/admin/settings',
        'param' => '/users/42/posts/7',
        'catch-all' => '/assets/css/app.css',
        'optional-present' => '/search/widgets',
        'optional-absent' => '/search',
        'not-found' => '/nope/x',
        'backtrack-hit' => '/users/me/edit',
        'backtrack-miss' => '/users/me/unknown',
    ],
];
