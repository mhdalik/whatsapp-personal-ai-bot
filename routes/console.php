<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


// Dispatch due reminders every minute
Schedule::command('reminders:dispatch-due')
    ->everyTwoMinutes()
    ->withoutOverlapping();
