<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\OrderConfirmationMail;
use App\Services\Mail\EmailService;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendOrderConfirmation implements ShouldQueue
{
    public int $tries = 3;

    public function handle(OrderPlaced $event): void
    {
        if (! app(MailSettings::class)->autoEnabled('order_confirmation')) {
            return;
        }
        $order = $event->order->loadMissing(['items.product', 'info', 'user']);
        $email = $order->info?->email ?: $order->user?->email;

        if (! $email) {
            Log::warning('OrderConfirmation skipped: no recipient email', ['order_id' => $order->id]);

            return;
        }

        app(EmailService::class)->send($email, new OrderConfirmationMail($order), [
            'type' => 'order-confirmation',
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'name' => trim(($order->info?->first_name ?? '') . ' ' . ($order->info?->last_name ?? '')) ?: null,
            'locale' => EmailLocale::forOrder($order),
        ]);
    }
}
