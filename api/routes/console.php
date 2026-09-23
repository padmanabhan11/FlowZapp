<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Doc 11 "Notification triggers" and S23/S24. Times are UTC; the App Platform scheduler runs `php artisan schedule:run` each minute.
Schedule::command('documents:review-due')->dailyAt('06:00');
Schedule::command('notifications:weekly-digest')->weeklyOn(1, '07:00');
Schedule::command('workspaces:purge-scheduled')->dailyAt('03:00');
Schedule::command('recordings:abandon-stale')->dailyAt('03:30');
