<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Demo users (@demo-agency.local) and Sandbox data must never be created outside dev/test/demo.
 * Structural seeding (permissions, platform admin) still runs everywhere.
 */
final class DemoSeederGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeding_creates_no_demo_accounts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        foreach (['agency@campaignshub.io', 'analyst@demo-agency.local', 'viewer@demo-agency.local'] as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
        // Structural seeding still happened.
        $this->assertDatabaseHas('users', ['email' => 'platform@campaignshub.io']);
        $this->assertTrue(Permission::where('key', 'campaigns.view')->exists());
    }

    public function test_local_seeding_creates_demo_accounts(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'agency@campaignshub.io']);
        $this->assertDatabaseHas('users', ['email' => 'viewer@demo-agency.local']);
    }

    /**
     * No demo row claims a fraction of an event happened.
     *
     * An impression, a click, an order — each is something that occurred a whole number of times.
     * Two seeders kept two decimals through the arithmetic and rounded only at the point of writing,
     * or not at all, and the aggregate they rolled up into printed «Orders 1,288.75» on the
     * dashboard's creative section. The obvious repair — rounding in the formatter — would have made
     * the number look right while the source stayed wrong, and would then have hidden the genuine
     * fractions of any platform that really does report partial conversions.
     *
     * MONEY is deliberately not checked: a riyal has halalas, and spend, revenue, CPC and AOV are
     * expected to carry them.
     */
    public function test_no_demo_event_count_is_a_fraction(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $creativeColumns = [
            'impressions', 'clicks', 'conversions', 'purchases', 'add_to_cart', 'checkout',
            'landing_page_views', 'engagements', 'reach', 'video_views', 'video_completions',
            'video_views_2s', 'video_views_3s', 'video_views_6s',
            'video_p25', 'video_p50', 'video_p75', 'video_p100',
        ];

        foreach ($creativeColumns as $column) {
            $offenders = DB::table('creative_daily_metrics')
                ->whereNotNull($column)
                ->whereRaw("{$column} <> ROUND({$column})")
                ->count();

            $this->assertSame(0, $offenders, "creative_daily_metrics.{$column} holds fractional events in demo data.");
        }

        // `daily_metrics` is key/value, so the same rule is applied per event-shaped metric key.
        $eventKeys = [
            'impressions', 'clicks', 'conversions', 'purchases', 'leads', 'reach',
            'landing_page_views', 'add_to_cart', 'checkout', 'video_views',
        ];

        $offenders = DB::table('daily_metrics')
            ->whereIn('metric_key', $eventKeys)
            ->whereRaw('value <> ROUND(value)')
            ->get(['metric_key', 'value'])
            ->take(5);

        $this->assertCount(
            0,
            $offenders,
            'daily_metrics holds fractional events in demo data: '.$offenders->map(
                static fn ($r): string => "{$r->metric_key}={$r->value}",
            )->implode(', '),
        );
    }
}
