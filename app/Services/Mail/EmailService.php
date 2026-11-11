<?php

namespace App\Services\Mail;

use App\Jobs\SendLoggedEmail;
use App\Models\EmailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

/**
 * The one way the shop sends email: every message gets an outbox row (queued →
 * sent / failed), is sent by a queued job with retries, and can be retried by
 * an admin. Nothing is lost silently any more.
 */
class EmailService
{
    /**
     * @param  array{type?: string, order_id?: int|null, user_id?: int|null, campaign_id?: int|null, created_by?: int|null, name?: string|null, locale?: string|null}  $context
     */
    public function send(string $to, Mailable $mail, array $context = [], ?\DateTimeInterface $sendAt = null): EmailLog
    {
        $to = trim($to);
        if (! empty($context['locale'])) {
            $mail->locale($context['locale']);
        }

        $log = EmailLog::create([
            'type' => $context['type'] ?? Str::kebab(class_basename($mail)),
            'to_email' => $to,
            'to_name' => $context['name'] ?? null,
            'subject' => $this->subjectOf($mail),
            'status' => EmailLog::STATUS_QUEUED,
            'payload' => serialize($mail),
            'order_id' => $context['order_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'campaign_id' => $context['campaign_id'] ?? null,
            'created_by' => $context['created_by'] ?? null,
        ]);

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $log->update(['status' => EmailLog::STATUS_FAILED, 'error' => 'Not a valid email address.']);

            return $log;
        }

        // Group emails can be spread over time to stay inside the mailbox's daily limit.
        $sendAt && $sendAt > now()
            ? SendLoggedEmail::dispatch($log->id)->delay($sendAt)
            : SendLoggedEmail::dispatch($log->id);

        return $log->refresh();
    }

    /** Puts a failed / stuck email back in the queue. */
    public function retry(EmailLog $log): EmailLog
    {
        if ($log->status === EmailLog::STATUS_SENT || ! $log->payload) {
            return $log;
        }
        $log->update(['status' => EmailLog::STATUS_QUEUED, 'error' => null]);
        SendLoggedEmail::dispatch($log->id);

        return $log->refresh();
    }

    /** The finished email as HTML, for the admin preview. */
    public function renderLog(EmailLog $log): ?string
    {
        if (! $log->payload) {
            return null;
        }
        try {
            $mail = unserialize($log->payload);

            return $mail instanceof Mailable ? self::prepare($mail)->render() : null;
        } catch (\Throwable $e) {
            return '<p style="font-family:sans-serif;color:#b42318">This email can no longer be shown: ' . e($e->getMessage()) . '</p>';
        }
    }

    /** Fixes older saved emails: shop alerts are always English. */
    public static function prepare(Mailable $mail): Mailable
    {
        if (! $mail->locale && ($mail instanceof \App\Mail\NewOrderAdminMail || $mail instanceof \App\Mail\Stylists\NewStylistRequestAdminMail)) {
            $mail->locale('en');
        }

        return $mail;
    }

    public function subjectOf(Mailable $mail): ?string
    {
        // Read the subject in the email's own language, as the recipient sees it.
        $locale = $mail->locale ?? null;
        $previous = app()->getLocale();
        if ($locale && $locale !== $previous) {
            app()->setLocale($locale);
            try {
                return $this->readSubject($mail);
            } finally {
                app()->setLocale($previous);
            }
        }

        return $this->readSubject($mail);
    }

    private function readSubject(Mailable $mail): ?string
    {
        try {
            if (method_exists($mail, 'envelope')) {
                $subject = $mail->envelope()->subject;
                if ($subject) {
                    return $subject;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return $mail->subject ?? null;
    }
}
