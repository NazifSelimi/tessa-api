<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewOrderAdminMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Order $order,
    ) {
        // Shop alerts are written in English, whatever language the customer used.
        $this->locale('en');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New order #' . $this->order->id . ' — ' . number_format(round((float) $this->order->total), 0, ',', '.') . ' MKD',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-order-admin',
            with: [
                'order' => $this->order,
                'items' => $this->order->items,
                'info' => $this->order->info,
                'customer' => $this->order->user,
            ],
        );
    }
}
