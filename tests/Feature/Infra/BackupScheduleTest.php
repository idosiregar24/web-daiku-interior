<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/* PRD §11.4 "Backup database otomatis setiap tengah malam" (routes/console.php). */

test('db:backup is scheduled every day at 00:00 WIB without overlapping runs', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'db:backup'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 0 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Jakarta')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});
