<?php

/*
|--------------------------------------------------------------------------
| Switchable features
|--------------------------------------------------------------------------
|
| Every tool an admin can switch off on /admin/features. Core parts of the
| shop (products, orders, users, coupons, stylist requests, settings) are
| always on and are not listed. "area" says who notices when it is off:
| shop = customers/stylists, admin = admin panel only. Turning a feature off
| hides it in the UI and makes its API routes answer "feature off".
|
*/

return [
    'hair_quiz' => [
        'label' => 'Hair quiz',
        'description' => 'Customers answer questions about their hair and get product recommendations (/quiz).',
        'area' => 'shop',
        'default' => true,
    ],
    'referrals' => [
        'label' => 'Stylist referral codes & store credit',
        'description' => 'Stylists share a code; clients get a discount and the stylist earns store credit.',
        'area' => 'shop',
        'default' => true,
    ],
    'bundles' => [
        'label' => 'Offers & bundles',
        'description' => 'Fixed-price sets and multi-product deals shown in the shop and applied at checkout.',
        'area' => 'shop',
        'default' => true,
    ],
    'quick_order' => [
        'label' => 'Stylist quick order',
        'description' => 'Salons order many products fast from one list.',
        'area' => 'shop',
        'default' => true,
    ],
    'admin_emails' => [
        'label' => 'Emails from the admin',
        'description' => 'Write and send emails to customers and stylists, see every sent / failed email and retry.',
        'area' => 'admin',
        'default' => true,
    ],
    'ai_assistant' => [
        'label' => 'AI assistant connection (MCP)',
        'description' => 'Connect Claude, Cursor or another AI assistant to see orders, stock and to-dos, update stock and draft emails. Free; needs an admin token.',
        'area' => 'admin',
        'default' => true,
    ],
    'order_delete' => [
        'label' => 'Delete orders',
        'description' => 'Move orders to the Trash (restore any time) and delete them forever from there.',
        'area' => 'admin',
        'default' => true,
    ],
    'post_studio' => [
        'label' => 'Post Studio',
        'description' => 'Make Instagram / Facebook / story images from products.',
        'area' => 'admin',
        'default' => true,
    ],
    'content_blocks' => [
        'label' => 'Editable page sections',
        'description' => 'Build homepage and campaign sections without code.',
        'area' => 'admin',
        'default' => true,
    ],
    'image_review' => [
        'label' => 'Image review',
        'description' => 'Review and manage product photo galleries.',
        'area' => 'admin',
        'default' => true,
    ],
    'stylist_invitations' => [
        'label' => 'Stylist invitations',
        'description' => 'One-time activation links / QR codes for salons.',
        'area' => 'admin',
        'default' => true,
    ],
    'distributors' => [
        'label' => 'Distributor codes',
        'description' => 'Older invitation codes created by distributors.',
        'area' => 'admin',
        'default' => true,
    ],
    'supplier_list' => [
        'label' => 'Supplier list (old)',
        'description' => 'The September supplier price list compared with the catalogue. Replaced by the stock list.',
        'area' => 'admin',
        'default' => true,
    ],
    'messaging' => [
        'label' => 'Viber & WhatsApp messages',
        'description' => 'Message templates, send-to-a-list and the optional automatic WhatsApp on /admin/messages.',
        'area' => 'admin',
        'default' => true,
    ],
];
