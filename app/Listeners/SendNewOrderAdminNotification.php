<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\NewOrderAdminMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendNewOrderAdminNotification implements ShouldQueue
{
    public int $tries = 3;

    public function handle(OrderPlaced $event): void
    {
        $adminEmail = config('tessa.admin_email');

        if (! $adminEmail) {
            Log::warning('NewOrderAdminNotification skipped: TESSA_ADMIN_EMAIL not configured');
            return;
        }

        $order = $event->order->loadMissing(['items.product', 'info', 'user']);

        Mail::to($adminEmail)->send(new NewOrderAdminMail($order));
    }
}
