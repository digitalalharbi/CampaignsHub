<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * SCHEDULE-REGISTERED-001 — every scheduled command exists.
 *
 * `subscriptions:lifecycle` was scheduled daily at 01:00 from the day billing shipped, and never registered: Domain commands are
 * listed by hand in `bootstrap/app.php`, it was not, and the scheduler logs «command not found» to a terminal nobody reads. Trials
 * never converted, renewals were never charged, unpaid periods never went past due and expired grace never suspended — hidden only
 * because Production still runs the sandbox gateway. The inventory test pins what is scheduled; this pins that it can run.
 */
final class ScheduledCommandsRegisteredTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_scheduled_artisan_command_is_registered(): void
    {
        $registered = array_keys(Artisan::all());
        $missing = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (preg_match("/artisan'?\\s+'?([a-z0-9:_-]+)/i", (string) $event->command, $m) === 1 && ! in_array($m[1], $registered, true)) {
                $missing[] = $m[1];
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'scheduled but not registered');
    }

    /** In production the lifecycle that never ran changes nothing until the Owner enables it, and says what it would act on. */
    public function test_the_lifecycle_only_counts_in_production_until_it_is_enabled(): void
    {
        $this->app['env'] = 'production';
        config(['subscriptions.lifecycle_enabled' => false]);

        $this->artisan('subscriptions:lifecycle')
            ->expectsOutputToContain('not enabled in production')
            ->expectsOutputToContain('renewals_due')
            ->assertSuccessful();
    }
}
