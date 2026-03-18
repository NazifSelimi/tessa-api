<?php

namespace App\Mail\Stylists;

use App\Models\RequestStylist;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StylistRequestStatusMail extends Mailable
{
    use Queueable, SerializesModels;


    public function __construct(
        public readonly User $user,
        public readonly string $statusLabel,
        public readonly ?RequestStylist $requestStylist = null,
        public readonly ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Stylist Application — ' . $this->statusLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stylists.request-status-update',
            with: [
                'user' => $this->user,
                'statusLabel' => $this->statusLabel,
                'request' => $this->requestStylist,
                'reason' => $this->reason,
                'frontendUrl' => config('tessa.frontend_url'),
            ],
        );
    }
}

