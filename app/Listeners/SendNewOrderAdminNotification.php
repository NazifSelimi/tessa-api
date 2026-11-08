<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\NewOrderAdminMail;
use App\Services\Mail\EmailService;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendNewOrderAdminNotification implements ShouldQueue
{
    public int $tries = 3;

    public function handle(OrderPlaced $event): void
    {
        $settings = app(MailSettings::class);
        if (! $settings->autoEnabled('admin_new_order')) {
            return;
        }
        $recipients = $settings->adminRecipients();
        if ($recipients === []) {
            Log::warning('NewOrderAdminNotification skipped: no shop alert address set (Admin → Emails → Settings)');

            return;
        }

        $order = $event->order->loadMissing(['items.product', 'info', 'user']);
        foreach ($recipients as $email) {
            app(EmailService::class)->send($email, new NewOrderAdminMail($order), [
                'type' => 'admin-new-order',
                'order_id' => $order->id,
                'locale' => 'en',
            ]);
        }
    }
}
