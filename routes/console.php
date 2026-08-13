<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cscs:health')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('cscs:prune-source-files')->dailyAt('02:30')->withoutOverlapping();
