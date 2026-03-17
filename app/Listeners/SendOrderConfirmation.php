<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\OrderConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmation implements ShouldQueue
{
    public int $tries = 3;

    public function handle(OrderPlaced $event): void
    {
        $order = $event->order->loadMissing(['items.product', 'info', 'user']);
        $email = $order->info?->email ?? $order->user?->email;

        if (! $email) {
            Log::warning('OrderConfirmation skipped: no recipient email', ['order_id' => $order->id]);
            return;
        }

        Mail::to($email)->send(new OrderConfirmationMail($order));
    }
}
