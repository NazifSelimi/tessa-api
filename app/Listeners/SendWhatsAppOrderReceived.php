<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Automatic "order received" WhatsApp. Does nothing unless switched on in /admin/messages. */
class SendWhatsAppOrderReceived implements ShouldQueue
{
    public int $tries = 1; // a retry could send (and bill) the same message twice

    public function handle(OrderPlaced $event): void
    {
        app(WhatsAppSender::class)->orderStatus($event->order);
    }
}
