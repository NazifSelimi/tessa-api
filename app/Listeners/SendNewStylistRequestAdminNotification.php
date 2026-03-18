<?php

namespace App\Listeners;

use App\Events\StylistRequestSubmitted;
use App\Mail\Stylists\NewStylistRequestAdminMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendNewStylistRequestAdminNotification implements ShouldQueue
{
    public int $tries = 3;

    public function handle(StylistRequestSubmitted $event): void
    {
        $adminEmail = config('tessa.admin_email');

        if (! $adminEmail) {
            Log::warning('NewStylistRequestAdminNotification skipped: TESSA_ADMIN_EMAIL not configured');
            return;
        }

        try {
            Mail::to($adminEmail)->send(new NewStylistRequestAdminMail(
                requestStylist: $event->request,
                user: $event->user,
            ));
        } catch (\Throwable $e) {
            Log::warning('NewStylistRequestAdminNotification failed', [
                'request_id' => $event->request->id,
                'user_id' => $event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

