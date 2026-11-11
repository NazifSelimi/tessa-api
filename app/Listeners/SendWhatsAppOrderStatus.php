<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Automatic WhatsApp on order-status changes. Does nothing unless switched on in /admin/messages. */
class SendWhatsAppOrderStatus implements ShouldQueue
{
    public int $tries = 1; // a retry could send (and bill) the same message twice

    public function handle(OrderStatusUpdated $event): void
    {
        if ($event->order->status === $event->previousStatus) {
            return;
        }
        app(WhatsAppSender::class)->orderStatus($event->order);
    }
}
