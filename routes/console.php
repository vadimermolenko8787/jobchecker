<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

try {
    $enabled = (bool) Setting::get('schedule_enabled');
    $expression = (string) Setting::get('cron_expression');
    $telegram = (bool) Setting::get('telegram_enabled');
} catch (Throwable) {
    // DB not migrated yet (e.g. during composer install) — no schedule.
    $enabled = false;
    $expression = '';
    $telegram = false;
}

if ($enabled && $expression !== '') {
    Schedule::command('jobs:search --trigger=cron')
        ->cron($expression)
        // A killed process keeps this lock, and the default 24 hours would silence the search
        // for a whole day. Four hours still covers any realistic run.
        ->withoutOverlapping(240)
        ->runInBackground();
}

if ($telegram) {
    // Presses of the mute button are fetched, not pushed, so the delay is one tick.
    // The mutex is short because the command lives seconds, and two parallel getUpdates
    // calls on one bot fight over the same queue.
    Schedule::command('telegram:poll')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->runInBackground();
}
