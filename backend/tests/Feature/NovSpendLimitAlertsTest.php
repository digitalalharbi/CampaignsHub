<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\SpendLimitEvent;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Notifications\Mail\AlertBundleMail;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/** Nov overhaul, task 6 — a limit announces 75%, 90% and 100% once each, in the system and by mail; run-out date; stale-data marker. */
final class NovSpendLimitAlertsTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bootFunding();
        Mail::fake();
        config(['budgets.limit_alert_email' => 'info@campaignshub.io']);
    }

    private function rule(string $projectId): AlertRule
    {
        return AlertRule::create(['tenant_id' => $this->tenant->id, 'project_id' => $projectId, 'type' => 'budget_risk', 'name' => 'Budget',
            'active' => true, 'severity' => 'warning', 'cooldown_minutes' => 60]);
    }

    private function sweep(AlertRule $rule, int $addDays = 0): int
    {
        return app(AlertEvaluator::class)->evaluateRule($rule, Carbon::now()->addDays($addDays));
    }

    public function test_each_of_75_90_and_100_is_recorded_and_mailed_once_even_when_no_threshold_was_configured(): void
    {
        $project = $this->newProject('Palm');
        $limit = $this->makeLimit($project, 1_000, 'SAR');           // created with NO thresholds: only 100 by default
        $rule = $this->rule($project->id);

        // The in-system alert of one limit is ONE open alert that is refreshed as it worsens; the ledger and the mail are per threshold.
        $this->limitSpend($project, 2, 760);                          // 76%
        $this->assertSame(1, $this->sweep($rule));
        $this->limitSpend($project, 1, 150);                          // 91%
        $this->sweep($rule, 5);
        $this->limitSpend($project, 0, 100);                          // 101%
        $this->sweep($rule, 10);
        $this->sweep($rule, 15);
        $this->sweep($rule, 20);

        $this->assertSame([75, 90, 100], SpendLimitEvent::withoutGlobalScopes()->where('spend_limit_id', $limit->id)->orderBy('threshold')->pluck('threshold')->all());
        Mail::assertSent(AlertBundleMail::class, 3);
        Mail::assertSent(AlertBundleMail::class, fn (AlertBundleMail $m): bool => $m->hasTo('info@campaignshub.io'));
    }

    public function test_a_jump_past_two_thresholds_records_both_and_announces_the_highest_once(): void
    {
        $project = $this->newProject('Palm');
        $limit = $this->makeLimit($project, 1_000, 'SAR');
        $rule = $this->rule($project->id);
        $this->limitSpend($project, 1, 950);                          // 95%: 75 and 90 crossed together

        $this->assertSame(1, $this->sweep($rule));
        $this->assertSame(0, $this->sweep($rule, 5));

        $this->assertSame([75, 90], SpendLimitEvent::withoutGlobalScopes()->where('spend_limit_id', $limit->id)->orderBy('threshold')->pluck('threshold')->all());
        Mail::assertSent(AlertBundleMail::class, 1);
        $this->assertSame(1, AlertEvent::withoutGlobalScopes()->count());
    }

    public function test_no_mail_when_the_address_is_empty_but_the_alert_still_stands(): void
    {
        config(['budgets.limit_alert_email' => '']);
        $project = $this->newProject('Palm');
        $this->makeLimit($project, 1_000, 'SAR');
        $this->limitSpend($project, 1, 800);

        $this->assertSame(1, $this->sweep($this->rule($project->id)));
        Mail::assertNothingSent();
    }

    public function test_the_run_out_date_follows_the_last_three_days_not_the_whole_period(): void
    {
        $project = $this->newProject('Palm');
        // 10 days ago: a big day; the last 3 full days: 100 a day. Limit 1,000, spent 1,000 - ... use a clean case.
        $limit = $this->makeLimit($project, 1_000, 'SAR');
        $this->limitSpend($project, 9, 100);
        foreach ([3, 2, 1] as $ago) {
            $this->limitSpend($project, $ago, 100);
        }
        // consumed 400, remaining 600, last-3-days rate 100/day => 6 days from today

        $row = app(SpendLimitGovernor::class)->read($limit, Carbon::today(), detail: true);

        $this->assertSame('projected', $row['run_out']['reason']);
        $this->assertSame(100.0, $row['run_out']['per_day']);
        $this->assertSame(Carbon::today()->addDays(6)->toDateString(), $row['run_out']['date']);
    }

    public function test_no_run_out_date_without_recent_spend(): void
    {
        $project = $this->newProject('Palm');
        $limit = $this->makeLimit($project, 1_000, 'SAR');
        $this->limitSpend($project, 9, 100);

        $row = app(SpendLimitGovernor::class)->read($limit, Carbon::today(), detail: true);

        $this->assertNull($row['run_out']['date']);
        $this->assertSame('no_spend_rate', $row['run_out']['reason']);
    }

    public function test_a_limit_on_a_platform_whose_sync_failed_or_is_older_than_two_hours_is_marked(): void
    {
        $project = $this->newProject('Palm');
        $this->limitSpend($project, 1, 100, provider: 'meta');
        $this->limitSpend($project, 1, 100, provider: 'snapchat');
        $meta = $this->account($project, 'meta');
        $snap = $this->account($project, 'snapchat');
        $this->bind($project, $meta);
        $this->bind($project, $snap);
        $meta->forceFill(['last_synced_at' => now()->subMinutes(20), 'last_sync_error_category' => null])->save();
        $snap->forceFill(['last_synced_at' => now()->subMinutes(20), 'last_sync_error_category' => null])->save();

        $metaLimit = $this->makeLimit($project, 1_000, 'SAR', scope: SpendLimitScope::Platform, scopeId: 'meta');
        $whole = $this->makeLimit($project, 1_000, 'SAR');
        $governor = app(SpendLimitGovernor::class);

        $this->assertFalse($governor->read($metaLimit, Carbon::today(), detail: true)['freshness']['stale'], 'fresh and healthy: no marker');

        $snap->forceFill(['last_synced_at' => now()->subHours(3)])->save();
        $this->assertFalse($governor->read($metaLimit, Carbon::today(), detail: true)['freshness']['stale'], 'another platform’s age does not mark a meta-only limit');
        $row = $governor->read($whole, Carbon::today(), detail: true);
        $this->assertTrue($row['freshness']['stale']);
        $this->assertSame('old', $row['freshness']['items'][0]['reason']);

        $meta->forceFill(['last_sync_error_category' => 'authorisation_lost'])->save();
        $row = $governor->read($metaLimit, Carbon::today(), detail: true);
        $this->assertTrue($row['freshness']['stale']);
        $this->assertSame('failed', $row['freshness']['items'][0]['reason']);
        $this->assertSame('authorisation_lost', $row['freshness']['items'][0]['error_category']);
    }

    public function test_a_budget_carries_the_same_marker_and_a_run_out_date(): void
    {
        $project = $this->newProject('Palm');
        $account = $this->account($project, 'meta');
        $this->bind($project, $account);
        $account->forceFill(['last_synced_at' => now()->subHours(5), 'last_sync_error_category' => null])->save();
        foreach ([3, 2, 1] as $ago) {
            $this->limitSpend($project, $ago, 100);
        }
        $this->addBudget($project, 10_000);

        $f = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$project->id)->assertOk()->json('data');

        $this->assertTrue($f['freshness']['stale']);
        $this->assertSame(100.0, (float) $f['pace_3d']);
        $this->assertSame(Carbon::today('Asia/Riyadh')->addDays((int) floor(9_700 / 100))->toDateString(), $f['run_out_date']);
    }
}
