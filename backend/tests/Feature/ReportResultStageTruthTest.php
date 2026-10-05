<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RESULT-STAGE-TRUTH-001 — «الإضافة إلى السلة لا تظهر في التقارير».
 *
 * The metric was collected, stored, totalled, offered in the builder by name — and absent from the
 * client's page. Traced end to end, it disappeared at one seam and only one:
 *
 * The card reads a basket add from the FUNNEL rather than from the totals, and correctly so: the
 * totals pivot wraps every stage in `COALESCE(…, 0)`, so a platform that never counts basket adds
 * and a platform that counted none of them are the same `0` there. The funnel keeps the null.
 *
 * But `funnel` is a SECTION's payload key. An executive summary drops it, and so does an operator who
 * switches the funnel off — both to `[]`. The card then read `undefined` and the figure vanished from
 * a report whose operator had ticked «الإضافات للسلة» themselves. A control that SHORTENS a document
 * had silently deleted a measurement.
 *
 * So the stage readings are published beside the funnel and belong to no section. These tests hold
 * the four properties that makes true: present in both forms, reported-aware, never coalesced to
 * zero, and never invented for a platform that sent nothing.
 */
final class ReportResultStageTruthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Stages', 'slug' => 'st-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create([
            'name' => 'Op', 'email' => 'st-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'acc-'.uniqid(),
            'name' => 'Shop', 'currency' => 'SAR', 'status' => 'active',
        ]);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Sales', 'status' => 'active', 'objective' => 'sales', 'platforms' => ['meta'],
        ]);
    }

    /**
     * The figure survives the EXECUTIVE SUMMARY, which is the form the owner was reading.
     *
     * The summary drops the funnel chart on purpose — it is a detailed block. It must not drop the
     * basket count with it: the shape of the document is not a claim about what the platform sent.
     */
    public function test_a_reported_basket_add_reaches_a_summary_report(): void
    {
        $this->report(['spend' => 500.0, 'add_to_cart' => 42.0, 'purchases' => 7.0]);

        $summary = $this->payload('executive_summary');

        $this->assertSame(
            [],
            $summary['funnel'] ?? [],
            'the summary still drops the funnel CHART — whether by emptying the key or by omitting it',
        );
        $this->assertSame(
            ['count' => 42, 'reported' => true],
            $summary['result_stages']['add_to_cart'] ?? null,
            'the basket count disappeared with the section that draws it',
        );
    }

    /** And it is the same reading in the detailed form, where the funnel is drawn as well. */
    public function test_the_detailed_funnel_still_carries_the_basket_step(): void
    {
        $this->report(['spend' => 500.0, 'add_to_cart' => 42.0, 'purchases' => 7.0]);

        $detailed = $this->payload('detailed');
        $stages = collect($detailed['funnel'])->keyBy('stage');

        $this->assertSame(42, (int) $stages['add_to_cart']['count']);
        $this->assertTrue($stages['add_to_cart']['reported']);
        $this->assertSame(['count' => 42, 'reported' => true], $detailed['result_stages']['add_to_cart'] ?? null);
    }

    /**
     * A platform that never sent the stage says so — it does not say zero.
     *
     * This is the collapse the funnel's own null exists to prevent, and the one the totals pivot
     * cannot express. «0 add to cart» beside 7 purchases is a sentence about our pipeline that reads
     * as a sentence about the client's shop.
     */
    public function test_a_basket_add_nobody_reported_is_unavailable_and_never_zero(): void
    {
        $this->report(['spend' => 500.0, 'purchases' => 7.0]);

        foreach (['executive_summary', 'detailed'] as $form) {
            $reading = $this->payload($form)['result_stages']['add_to_cart'] ?? null;

            $this->assertSame(['count' => null, 'reported' => false], $reading, "collapsed to a figure in {$form}");
        }
    }

    /** A measured zero is a measurement and stays one. */
    public function test_a_measured_zero_basket_add_stays_a_zero(): void
    {
        $this->report(['spend' => 500.0, 'add_to_cart' => 0.0, 'purchases' => 7.0]);

        $this->assertSame(
            ['count' => 0, 'reported' => true],
            $this->payload('executive_summary')['result_stages']['add_to_cart'] ?? null,
        );
    }

    /**
     * Nothing is invented for a report that has no basket step at all.
     *
     * An awareness account reporting impressions and clicks must not gain a stage reading claiming a
     * count — the readings state what the window holds, never what the shape of the key suggests.
     */
    public function test_an_awareness_report_gains_no_basket_figure(): void
    {
        $this->report(['spend' => 500.0, 'impressions' => 90000.0, 'clicks' => 1200.0]);

        $stages = $this->payload('executive_summary')['result_stages'];

        $this->assertFalse($stages['add_to_cart']['reported']);
        $this->assertNull($stages['add_to_cart']['count']);
        $this->assertTrue($stages['impressions']['reported'], 'a stage that WAS reported still reads as reported');
    }

    /** @param array<string, float> $metrics */
    private function report(array $metrics): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $this->campaign->getKey(),
            'provider' => 'meta', 'external_id' => 'e-'.uniqid(), 'name' => 'Sales', 'status' => 'active',
        ]);

        $rows = [];
        foreach ($metrics as $key => $value) {
            $rows[] = new NormalizedMetric(
                tenantId: (string) $this->tenant->getKey(),
                projectId: (string) $this->project->getKey(),
                provider: 'meta',
                externalAccountId: (string) $this->account->getKey(),
                externalCampaignId: (string) $external->getKey(),
                unifiedCampaignId: (string) $this->campaign->getKey(),
                metricDate: Carbon::parse('2026-09-15'),
                metricKey: $key,
                value: $value,
                projectCurrency: 'SAR',
            );
        }

        app(UpsertDailyMetrics::class)->handle($rows);
    }

    /** @return array<string, mixed> */
    private function payload(string $form): array
    {
        $created = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->getKey()}/reports/live", [
                'name' => 'Link '.$form,
                'from' => '2026-09-01',
                'to' => '2026-09-30',
                'form' => $form,
                'metrics' => ['spend', 'add_to_cart', 'purchases'],
            ])
            ->assertCreated()
            ->json('data');

        return $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');
    }
}
