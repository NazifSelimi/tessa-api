<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Mail\OrderStatusUpdateMail;
use App\Models\Order;
use App\Services\Mail\EmailService;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendOrderStatusUpdate implements ShouldQueue
{
    public int $tries = 3;

    /** Translation keys of each status (lang/{locale}/emails.php). */
    public const STATUS_KEYS = [
        Order::STATUS_PENDING => 'pending',
        Order::STATUS_PAID => 'confirmed',
        Order::STATUS_SHIPPED => 'shipped',
        Order::STATUS_CANCELLED => 'cancelled',
    ];

    public function handle(OrderStatusUpdated $event): void
    {
        // Don't email for status changes that aren't customer-relevant
        if ($event->order->status === $event->previousStatus) {
            return;
        }
        if (! app(MailSettings::class)->autoEnabled('order_status')) {
            return;
        }

        $order = $event->order->loadMissing(['items.product', 'info', 'user']);
        $email = $order->info?->email ?: $order->user?->email;

        if (! $email) {
            Log::warning('OrderStatusUpdate skipped: no recipient email', ['order_id' => $order->id]);

            return;
        }

        $statusKey = self::STATUS_KEYS[$order->status] ?? 'updated';

        app(EmailService::class)->send($email, new OrderStatusUpdateMail($order, $statusKey), [
            'type' => 'order-status',
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'locale' => EmailLocale::forOrder($order),
        ]);
    }
}
