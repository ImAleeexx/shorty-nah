<?php

use App\Clicks\VisitorHash;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Makes the hot path's Redis counters durable. Frequent enough that a Redis loss
// costs little, rare enough that it is never on a visitor's critical path.
Schedule::command('shortynah:reconcile-clicks')->everyFiveMinutes()->withoutOverlapping();

// A backstop, not the primary consumer: the queue worker drains continuously, and
// this ensures a worker restart cannot leave envelopes sitting.
Schedule::command('shortynah:drain-clicks --batch=1000 --passes=5')->everyMinute()->withoutOverlapping();

// Rotating the visitor salt is what makes an identifier non-recomputable
// afterwards. Discarding the previous salt is the whole mechanism.
Schedule::call(fn () => app(VisitorHash::class)->rotate())->daily();
