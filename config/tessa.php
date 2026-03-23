<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin Notification Email
    |--------------------------------------------------------------------------
    |
    | The email address that receives admin notifications such as new order
    | alerts. Set this in your .env file.
    |
    */

    'admin_email' => env('TESSA_ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the frontend application. Used in transactional emails
    | for links such as password reset and order details.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Frontend Social Auth Callback Path
    |--------------------------------------------------------------------------
    |
    | The SPA route that receives the OAuth result after the backend exchanges
    | the provider authorization code for an application API token.
    |
    */

    'social_auth_callback_path' => env('FRONTEND_SOCIAL_AUTH_CALLBACK_PATH', '/auth/social/callback'),

];
