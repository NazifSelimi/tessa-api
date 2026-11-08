<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * An email written by an admin (one person or a whole group): heading, text,
 * optional button and product cards, in the shop's branded layout.
 */
class AdminMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{name: string, price: string|null, image: string|null, url: string}>  $products
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly string $body,
        public readonly ?string $buttonText = null,
        public readonly ?string $buttonUrl = null,
        public readonly array $products = [],
        public readonly ?string $recipientName = null,
        public readonly ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    /** Lets Gmail / Apple Mail show their own "Unsubscribe" button on group emails. */
    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl ? ['List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>'] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-message', with: [
            'heading' => $this->heading,
            'paragraphs' => self::paragraphs($this->body, $this->recipientName),
            'buttonText' => $this->buttonText,
            'buttonUrl' => $this->buttonUrl,
            'products' => $this->products,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ]);
    }

    /**
     * Plain text → paragraphs. Blank lines split paragraphs, {name} becomes the
     * recipient's first name, **bold** is bold, and web links become clickable.
     *
     * @return list<string> safe HTML
     */
    public static function paragraphs(string $body, ?string $name): array
    {
        $body = str_replace(['{name}', '{ime}', '{emri}'], $name ?: '', $body);
        $blocks = preg_split("/\R{2,}/", trim($body)) ?: [];

        return array_values(array_filter(array_map(function (string $block) {
            $html = e(trim($block));
            $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
            $html = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" style="color:inherit;">$1</a>', $html);

            return nl2br($html, false);
        }, $blocks)));
    }
}
