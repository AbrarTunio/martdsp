<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Nightly copy of the shop
|--------------------------------------------------------------------------
|
| The hour is a setting, because a shop that shuts at midnight should not be
| dumping its database mid-sale. Reading it needs the database, which is not
| there while the app is being installed, so a failure to read leaves the
| usual hour in place rather than stopping the scheduler.
|
| For this to run at all, Windows Task Scheduler must call
| `php artisan schedule:run` every minute. See docs/PLAN.md.
|
*/
Schedule::command('supermart:backup')
    ->dailyAt(sprintf('%02d:10', rescue(fn (): int => (int) Setting::read('backup.hour'), 23, false)))
    ->withoutOverlapping();
