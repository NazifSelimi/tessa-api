<?php

namespace App\Mail\Stylists;

use App\Models\RequestStylist;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewStylistRequestAdminMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly RequestStylist $requestStylist,
        public readonly User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Stylist Application — ' . trim($this->user->first_name . ' ' . $this->user->last_name),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stylists.new-request-admin',
            with: [
                'user' => $this->user,
                'request' => $this->requestStylist,
            ],
        );
    }
}

