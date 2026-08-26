<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Makes the hot path's Redis counters durable. Frequent enough that a Redis loss
// costs little, rare enough that it is never on a visitor's critical path.
Schedule::command('shortynah:reconcile-clicks')->everyFiveMinutes()->withoutOverlapping();
