<?php

namespace App\Listeners;

use App\Events\StylistRequestSubmitted;
use App\Mail\Stylists\NewStylistRequestAdminMail;
use App\Services\Mail\EmailService;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendNewStylistRequestAdminNotification implements ShouldQueue
{
    public int $tries = 3;

    public function handle(StylistRequestSubmitted $event): void
    {
        $settings = app(MailSettings::class);
        if (! $settings->autoEnabled('admin_stylist_request')) {
            return;
        }
        $recipients = $settings->adminRecipients();
        if ($recipients === []) {
            Log::warning('NewStylistRequestAdminNotification skipped: no shop alert address set');

            return;
        }
        foreach ($recipients as $email) {
            app(EmailService::class)->send($email, new NewStylistRequestAdminMail(
                requestStylist: $event->request,
                user: $event->user,
            ), ['type' => 'admin-stylist-request', 'user_id' => $event->user->id, 'locale' => 'en']);
        }
    }
}
