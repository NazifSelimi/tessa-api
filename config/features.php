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
| "plan" is the lowest subscription plan that includes it (basic ⊂ pro ⊂
| premium); the shop's plan is the Setting business.plan.
|
*/

return [
    'hair_quiz' => [
        'label' => 'Hair quiz',
        'description' => 'Customers answer questions about their hair and get product recommendations (/quiz).',
        'area' => 'shop',
        'default' => true,
        'plan' => 'basic',
    ],
    'referrals' => [
        'label' => 'Referral codes & store credit',
        'description' => 'Business accounts share a code; their clients get a discount and the business earns store credit.',
        'area' => 'shop',
        'default' => true,
        'plan' => 'basic',
    ],
    'bundles' => [
        'label' => 'Offers & bundles',
        'description' => 'Fixed-price sets and multi-product deals shown in the shop and applied at checkout.',
        'area' => 'shop',
        'default' => true,
        'plan' => 'basic',
    ],
    'quick_order' => [
        'label' => 'Business quick order',
        'description' => 'Business accounts order many products fast from one list.',
        'area' => 'shop',
        'default' => true,
        'plan' => 'basic',
    ],
    'admin_emails' => [
        'label' => 'Emails from the admin',
        'description' => 'Write and send emails to customers and business accounts, see every sent / failed email and retry.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'pro',
    ],
    'mcp' => [
        'label' => 'AI assistant connection (MCP)',
        'description' => 'Connect Claude, Cursor or another AI assistant to see orders, stock and to-dos, update stock and draft emails. Free; needs an admin token.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'premium',
    ],
    'whatsapp_auto' => [
        'label' => 'Automatic WhatsApp / Viber messages',
        'description' => 'Send order and status messages to customers on WhatsApp or Viber automatically.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'premium',
    ],
    'order_delete' => [
        'label' => 'Delete orders',
        'description' => 'Move orders to the Trash (restore any time) and delete them forever from there.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'pro',
    ],
    'post_studio' => [
        'label' => 'Post Studio',
        'description' => 'Make Instagram / Facebook / story images from products.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'pro',
    ],
    'content_blocks' => [
        'label' => 'Editable page sections',
        'description' => 'Build homepage and campaign sections without code.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'basic',
    ],
    'image_review' => [
        'label' => 'Image review',
        'description' => 'Review and manage product photo galleries.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'basic',
    ],
    'stylist_invitations' => [
        'label' => 'Business invitations',
        'description' => 'One-time activation links / QR codes to invite businesses.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'pro',
    ],
    'distributors' => [
        'label' => 'Distributor codes',
        'description' => 'Older invitation codes created by distributors.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'basic',
    ],
    'supplier_list' => [
        'label' => 'Supplier list (old)',
        'description' => 'The September supplier price list compared with the catalogue. Replaced by the stock list.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'basic',
    ],
    'messaging' => [
        'label' => 'Viber & WhatsApp messages',
        'description' => 'Message templates, send-to-a-list and the optional automatic WhatsApp on /admin/messages.',
        'area' => 'admin',
        'default' => true,
    ],
    'sales_reports' => [
        'label' => 'Sales reports',
        'description' => 'Revenue, profit, best sellers, customers, codes and slow stock for any period on /admin/reports.',
        'area' => 'admin',
        'default' => true,
        'plan' => 'pro',
    ],
];
