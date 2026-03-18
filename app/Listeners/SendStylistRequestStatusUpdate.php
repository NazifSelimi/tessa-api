<?php

namespace App\Listeners;

use App\Events\StylistRequestApproved;
use App\Events\StylistRequestRejected;
use App\Mail\Stylists\StylistRequestStatusMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendStylistRequestStatusUpdate implements ShouldQueue
{
    public int $tries = 3;

    public function handle(StylistRequestApproved|StylistRequestRejected $event): void
    {
        $user = $event->user;

        if (! $user->email) {
            Log::warning('StylistRequestStatusUpdate skipped: no recipient email', [
                'user_id' => $user->id,
                'request_id' => $event->request->id ?? null,
            ]);
            return;
        }

        $statusLabel = $event instanceof StylistRequestApproved ? 'Approved' : 'Rejected';

        try {
            Mail::to($user->email)->send(new StylistRequestStatusMail(
                user: $user,
                statusLabel: $statusLabel,
                requestStylist: $event->request,
                reason: $event instanceof StylistRequestRejected ? $event->reason : null,
            ));
        } catch (\Throwable $e) {
            Log::warning('StylistRequestStatusUpdate failed', [
                'user_id' => $user->id,
                'request_id' => $event->request->id ?? null,
                'status' => $statusLabel,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

