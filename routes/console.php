<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| withoutOverlapping matters here: the command walks an unbounded set in
| chunks, so a slow run under load would otherwise have a second copy start
| on top of it five minutes later and rewrite the same rows.
|
*/

Schedule::command('appointments:release-expired-holds')
    ->everyFiveMinutes()
    ->withoutOverlapping();
