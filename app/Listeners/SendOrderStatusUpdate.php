<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Mail\OrderStatusUpdateMail;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderStatusUpdate implements ShouldQueue
{
    public int $tries = 3;

    private const STATUS_LABELS = [
        Order::STATUS_PENDING   => 'Pending',
        Order::STATUS_PAID      => 'Confirmed',
        Order::STATUS_SHIPPED   => 'Shipped',
        Order::STATUS_CANCELLED => 'Cancelled',
    ];

    public function handle(OrderStatusUpdated $event): void
    {
        // Don't email for status changes that aren't customer-relevant
        if ($event->order->status === $event->previousStatus) {
            return;
        }

        $order = $event->order->loadMissing(['items.product', 'info', 'user']);
        $email = $order->info?->email ?? $order->user?->email;

        if (! $email) {
            Log::warning('OrderStatusUpdate skipped: no recipient email', ['order_id' => $order->id]);
            return;
        }

        $label = self::STATUS_LABELS[$order->status] ?? 'Updated';

        Mail::to($email)->send(new OrderStatusUpdateMail($order, $label));
    }
}
