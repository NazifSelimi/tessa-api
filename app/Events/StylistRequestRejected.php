<?php

namespace App\Events;

use App\Models\RequestStylist;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class StylistRequestRejected
{
    use Dispatchable;

    public function __construct(
        public readonly RequestStylist $request,
        public readonly User $user,
        public readonly ?string $reason = null,
    ) {}
}
