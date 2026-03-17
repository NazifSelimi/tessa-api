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

];
