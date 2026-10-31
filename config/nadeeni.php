<?php

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    'admin' => [
        'name' => env('ADMIN_NAME', 'Platform Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
