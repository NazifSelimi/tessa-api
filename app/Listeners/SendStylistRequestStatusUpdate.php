<?php

namespace App\Listeners;

use App\Events\StylistRequestApproved;
use App\Events\StylistRequestRejected;
use App\Events\StylistRequestSubmitted;
use App\Mail\Stylists\StylistRequestStatusMail;
use App\Services\Mail\EmailService;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendStylistRequestStatusUpdate implements ShouldQueue
{
    public int $tries = 3;

    public function handle(StylistRequestApproved|StylistRequestRejected|StylistRequestSubmitted $event): void
    {
        if (! app(MailSettings::class)->autoEnabled('stylist_status')) {
            return;
        }
        $user = $event->user;

        if (! $user->email) {
            Log::warning('StylistRequestStatusUpdate skipped: no recipient email', ['user_id' => $user->id]);

            return;
        }

        $statusKey = match (true) {
            $event instanceof StylistRequestSubmitted => 'submitted',
            $event instanceof StylistRequestApproved => 'approved',
            default => 'rejected',
        };

        app(EmailService::class)->send($user->email, new StylistRequestStatusMail(
            user: $user,
            statusLabel: $statusKey,
            requestStylist: $event->request,
            reason: $event instanceof StylistRequestRejected ? $event->reason : null,
        ), [
            'type' => 'stylist-status',
            'user_id' => $user->id,
            'name' => trim($user->first_name . ' ' . $user->last_name) ?: null,
            'locale' => EmailLocale::forUser($user),
        ]);
    }
}
