<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('php-wiki:scan')->hourly()->withoutOverlapping();
Schedule::command('php-wiki:lint')->dailyAt('02:00')->withoutOverlapping();
