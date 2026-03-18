<?php

namespace App\Events;

use App\Models\RequestStylist;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StylistRequestRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RequestStylist $request,
        public readonly User $user,
        public readonly ?string $reason = null,
    ) {}
}

