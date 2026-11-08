<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Services\Mail\MailSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one outbox email and records what happened. */
class SendLoggedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Wait 1 minute, then 5, before trying again (mail servers often recover). */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $logId) {}

    public function handle(MailSettings $settings): void
    {
        $log = EmailLog::find($this->logId);
        if (! $log || $log->status === EmailLog::STATUS_SENT) {
            return;
        }

        $log->increment('attempts');

        try {
            $mail = unserialize((string) $log->payload);
            if (! $mail instanceof Mailable) {
                throw new \RuntimeException('The saved email could not be read.');
            }
            $settings->apply();
            $delivery = $settings->deliveryMode();
            Mail::to($log->to_email, $log->to_name)->sendNow($mail);

            $log->update([
                'status' => EmailLog::STATUS_SENT,
                'delivery' => $delivery,
                'redirected_to' => $delivery === 'redirected' ? $settings->testRecipient() : null,
                'error' => null,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Without a real queue (sync) there is no later attempt; never let a mail error break checkout.
            $final = $this->attempts() >= $this->tries || ! $this->job || $this->job->getConnectionName() === 'sync';
            $log->update([
                'status' => $final ? EmailLog::STATUS_FAILED : EmailLog::STATUS_RETRYING,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            if ($final) {
                return; // recorded as failed; an admin can retry from the outbox
            }
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        EmailLog::whereKey($this->logId)->where('status', '!=', EmailLog::STATUS_SENT)->update([
            'status' => EmailLog::STATUS_FAILED,
            'error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }
}
