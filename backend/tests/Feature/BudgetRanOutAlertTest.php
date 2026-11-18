<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Master task 2 (still open after #1025) and OWNER_NOTIFICATIONS_RULES §6–§7: a campaign whose platform spend reached its
 * whole budget is told «خلصت ميزانية الحملة» — the budget alert, said as a fact — never «قربت تخلص»; and a campaign that
 * is paused or ended raises no budget alert at all. «Ran out» is written only when the measured spend reached the budget.
 */
final class BudgetRanOutAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * A daytime clock. These tests seed «yesterday» as the UTC date, while an alert measures yesterday in Riyadh
         * (DIGESTS-YESTERDAY-BY-OBJECTIVE-001): from 21:00 to 24:00 UTC the two days differ and the fixture is not yesterday.
         */
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A name with no digits in it, because a test below forbids a digit sequence in the message.
     *
     * It was `'Camp '.uniqid()`, a hex string — and the alert message names the campaign, so a
     * suffix that happened to contain `375` failed «the alarm must not name 375%» on a run where the
     * product was entirely correct. Observed: `Camp 6aa89485b375a`. A fixture that can fail for a
     * reason unrelated to its subject is a test that gets loosened by whoever meets it at 2am.
     *
     * The digits are mapped out rather than randomised away: `Str::random()` is alphanumeric, so it
     * would have left exactly the same collision in place at a lower rate, which is the worse of the
     * two outcomes. `strtr` keeps the uniqueness `uniqid()` was there for.
     */
    private function campaign(float $budget, string $currency = 'SAR'): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Camp '.strtr(uniqid(), '0123456789', 'abcdefghij'), 'objective' => 'conversions', 'status' => 'active',
            'total_budget' => $budget, 'budget_currency' => $currency,
        ]);
    }

    /** A spend the contract could not convert: `value` null, the original kept beside it. */
    private function withheldSpend(UnifiedCampaign $c, float $original, string $currency): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'sandbox', 'metric_key' => 'spend',
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'value' => null, 'original_amount' => $original, 'original_currency' => $currency,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function spend(UnifiedCampaign $c, float $value, string $currency = 'SAR'): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'sandbox', 'metric_key' => 'spend',
            'metric_date' => Carbon::now()->subDay()->toDateString(), 'value' => $value,
            'project_currency' => $currency,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function rule(): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'type' => 'budget_risk', 'name' => 'Budget', 'is_active' => true,
            'threshold' => ['ratio' => 0.9], 'channels' => ['in_app'], 'severity' => 'warning',
        ]);
    }

    public function test_a_campaign_that_spent_its_whole_budget_is_told_it_ran_out_not_that_it_is_at_risk(): void
    {
        $c = $this->campaign(1_000);
        $this->spend($c, 1_000);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule()));
        $event = AlertEvent::query()->where('entity_id', (string) $c->id)->firstOrFail();
        $this->assertTrue($event->context['budget_exhausted'] ?? false);

        $plain = AlertEventText::plain('budget_risk', (array) $event->context, true, '');
        $this->assertStringContainsString('خلصت ميزانية', $plain['headline']);
        $this->assertStringNotContainsString('قربت', $plain['headline']);
        $this->assertStringContainsString('جدّد', $plain['action']);
    }

    public function test_a_campaign_under_its_whole_budget_still_reads_nearly_used_up(): void
    {
        $c = $this->campaign(1_000);
        $this->spend($c, 950);

        app(AlertEvaluator::class)->evaluateRule($this->rule());
        $event = AlertEvent::query()->where('entity_id', (string) $c->id)->firstOrFail();
        $this->assertFalse($event->context['budget_exhausted'] ?? false);
        $this->assertStringContainsString('قربت تخلص', AlertEventText::plain('budget_risk', (array) $event->context, true, '')['headline']);
    }

    public function test_a_paused_or_ended_campaign_raises_no_budget_alert(): void
    {
        $paused = $this->campaign(1_000);
        $paused->forceFill(['status' => 'paused'])->save();
        $this->spend($paused, 1_000);
        $ended = $this->campaign(1_000);
        $ended->forceFill(['ends_on' => Carbon::now()->subDays(2)->toDateString()])->save();
        $this->spend($ended, 980);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }
}
