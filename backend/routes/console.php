<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune expired upload sessions + orphaned files hourly.
Schedule::command('requests:prune-uploads')->hourly();

// Evaluate request SLA (warnings + breaches) every 10 minutes.
Schedule::command('requests:evaluate-sla')->everyTenMinutes();

// Dispatch due scheduled reports (snapshot + honest delivery ledger) every 5 minutes.
Schedule::command('reports:dispatch-scheduled')->everyFiveMinutes();

// Evaluate alert rules (budget risk, no results, ROAS drop, sync failure, token expiry) every 15 minutes.
Schedule::command('alerts:evaluate')->everyFifteenMinutes();
