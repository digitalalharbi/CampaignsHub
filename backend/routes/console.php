<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Evaluate alert rules hourly (raise/resolve notifications). Failure-isolated per project.
Schedule::command('alerts:evaluate')->hourly()->withoutOverlapping();
