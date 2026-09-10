<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

try {
    $enabled = (bool) Setting::get('schedule_enabled');
    $expression = (string) Setting::get('cron_expression');
} catch (\Throwable) {
    // DB not migrated yet (e.g. during composer install) — no schedule.
    $enabled = false;
    $expression = '';
}

if ($enabled && $expression !== '') {
    Schedule::command('jobs:search --trigger=cron')
        ->cron($expression)
        // A killed process keeps this lock, and the default 24 hours would silence the search
        // for a whole day. Four hours still covers any realistic run.
        ->withoutOverlapping(240)
        ->runInBackground();
}
