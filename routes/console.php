<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sign-in tokens expire after 24 hours (config/sanctum.php) but stay in the table until pruned.
// Needs the server cron: * * * * * php artisan schedule:run
\Illuminate\Support\Facades\Schedule::command('sanctum:prune-expired --hours=24')->daily();
