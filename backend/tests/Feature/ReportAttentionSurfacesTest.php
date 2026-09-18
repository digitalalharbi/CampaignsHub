<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ShareSections;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — one section model, every client surface.
 *
 * The fixture is two findings on one platform, one of each audience:
 *
 *   - a LEAD campaign whose cost per lead rose from 40 to 60 — client-safe («review cost drivers»);
 *   - a TRAFFIC campaign whose cost per click doubled while its CTR held — a bidding matter, which is
 *     operator-internal and must not reach a client until the operator approves it.
 *
 * Asserted on the live link, the shared snapshot and the client print payload (the PDF), and on the
 * outline each of them carries.
 */
final class ReportAttentionSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    /** @var list<string> */
    private array $campaignIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Attention Co', 'slug' => 'attention-co', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'client-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Project', 'status' => 'active',
        ]);

        $this->operator = $this->user('op@attention.local', Permission::pluck('key')->all());

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'acct-'.uniqid(), 'name' => 'Ad account', 'currency' => 'SAR', 'status' => 'active',
        ]);

        // June is the previous window of the July report.
        $this->seedCampaign('Leads — internal name', CampaignObjective::Leads, [
            '2026-06-15' => ['spend' => 2000, 'conversions' => 50, 'clicks' => 1000, 'impressions' => 50_000],
            '2026-07-15' => ['spend' => 3000, 'conversions' => 50, 'clicks' => 1000, 'impressions' => 50_000],
        ]);
        $this->seedCampaign('Traffic — retargeting pool', CampaignObjective::Traffic, [
            '2026-06-15' => ['spend' => 1000, 'clicks' => 1000, 'impressions' => 100_000],
            '2026-07-15' => ['spend' => 2000, 'clicks' => 1000, 'impressions' => 100_000],
        ]);
    }

    public function test_the_operator_sees_both_audiences_and_every_client_surface_sees_only_the_client_safe_item(): void
    {
        $report = $this->generate();
        $operator = $this->operatorItems($report);
        $this->assertEqualsCanonicalizing(['cpl_rise', 'cpc_rise'], array_column($operator, 'code'));
        $this->assertSame(['cpl_rise' => 'client', 'cpc_rise' => 'operator'], array_column($operator, 'audience', 'code') + []);

        $this->assertEqualsCanonicalizing(['cpl_rise', 'cpc_rise'], array_column($report->data['attention'], 'code'), 'the snapshot lost the operator\'s view');

        foreach ($this->clientSurfaces($report) as $surface => $payload) {
            $this->assertSame(['cpl_rise'], array_column($payload['attention'], 'code'), "{$surface}: the client cut differs");
            $this->assertStringNotContainsString('review_bidding', json_encode($payload['attention']), "{$surface}: an operator-internal action reached a client");
            $this->assertStringNotContainsString('retargeting', json_encode($payload['attention']), "{$surface}: a campaign name reached a client");
            $this->assertArrayNotHasKey('audience', $payload['attention'][0], "{$surface}: operator fields reached a client");
            $this->assertTrue($this->outline($payload)['present'], "{$surface}: the outline denies a section it has");
        }

        // The same block, figure for figure, on every surface.
        $blocks = array_map(
            static fn (array $p): array => array_map(static fn (array $i): array => [$i['key'], $i['severity'], $i['nature'], $i['action'], $i['kpis'], $i['impact']], $p['attention']),
            $this->clientSurfaces($report),
        );
        // Compared loosely: a stored snapshot reads 40 back where a live response says 40.0.
        $first = array_shift($blocks);
        foreach ($blocks as $surface => $block) {
            $this->assertEquals($first, $block, "{$surface} disagrees with the live link about the block");
        }
    }

    public function test_an_operator_decision_reaches_every_client_surface_without_regeneration(): void
    {
        $report = $this->generate();
        $keys = array_column($this->operatorItems($report), 'key', 'code');

        $this->decide($report, $keys['cpc_rise'], 'approved')->assertOk();
        foreach ($this->clientSurfaces($report) as $surface => $payload) {
            $this->assertEqualsCanonicalizing(['cpl_rise', 'cpc_rise'], array_column($payload['attention'], 'code'), "{$surface}: approval did not publish the item");
        }

        $this->decide($report, $keys['cpl_rise'], 'hidden')->assertOk();
        $this->decide($report, $keys['cpc_rise'], null)->assertOk();
        foreach ($this->clientSurfaces($report) as $surface => $payload) {
            $this->assertSame([], $payload['attention'], "{$surface}: a hidden or un-approved item reached a client");
            $outline = $this->outline($payload);
            $this->assertFalse($outline['present'], "{$surface}: the outline promises an emptied section");
        }
    }

    /**
     * Coordinator decision on #490: an approval belongs to ONE report and its period. It must not
     * silently carry into the next period's report, or into another window on the same live link —
     * the operator re-approves against the figures they are actually publishing.
     */
    public function test_an_approval_does_not_carry_into_another_report_or_period(): void
    {
        // August repeats July's movement, so the same finding (same item key) exists in both.
        $this->seedCampaign('Leads — August', CampaignObjective::Leads, [
            '2026-08-15' => ['spend' => 4500, 'conversions' => 50, 'clicks' => 1000, 'impressions' => 50_000],
        ]);
        $this->seedCampaign('Traffic — August', CampaignObjective::Traffic, [
            '2026-08-15' => ['spend' => 4000, 'clicks' => 1000, 'impressions' => 100_000],
        ]);

        $july = $this->generate();
        $august = $this->generate('2026-08-01', '2026-08-31');
        $julyKeys = array_column($this->operatorItems($july), 'key', 'code');
        $augustKeys = array_column($this->operatorItems($august), 'key', 'code');
        $this->assertSame($julyKeys['cpc_rise'], $augustKeys['cpc_rise'] ?? null, 'the fixture must repeat the finding in August for this to mean anything');

        $this->decide($july, $julyKeys['cpc_rise'], 'approved')->assertOk();

        $this->assertContains('cpc_rise', array_column($this->clientSurfaces($july)['shared snapshot']['attention'], 'code'));
        foreach ($this->clientSurfaces($august) as $surface => $payload) {
            $this->assertNotContains('cpc_rise', array_column($payload['attention'], 'code'), "{$surface}: July's approval published August's item");
        }
        $this->assertSame(null, collect($this->operatorItems($august))->firstWhere('code', 'cpc_rise')['decision']);

        // The live link of the July report, opened on another window, is another period.
        [, $live] = app(ShareService::class)->create($july, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->id, 'campaign_ids' => $this->campaignIds,
                'providers' => ['meta'], 'earliest' => '2026-07-01', 'latest' => '2026-08-31',
            ],
        ], $this->operator->id);
        $august = $this->getJson("/api/v1/reports/shared/{$live}/live?from=2026-08-01&to=2026-08-31")->assertOk()->json('data.attention');
        $this->assertNotContains('cpc_rise', array_column($august, 'code'), 'an approval for July reached the same link opened on August');
    }

    public function test_approving_needs_the_approve_permission(): void
    {
        $report = $this->generate();
        $keys = array_column($this->operatorItems($report), 'key', 'code');
        $viewer = $this->user('viewer@attention.local', ['reports.view', 'reports.manage']);

        $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/attention/{$keys['cpc_rise']}", ['decision' => 'approved'])
            ->assertForbidden();
    }

    /**
     * The switch is the `recommendations` section of the ONE section registry (#486), saved on the
     * report — not a link flag of its own. Off there, it is off on the live link, the shared snapshot
     * and the client PDF alike, and the outline says the operator switched it off.
     */
    public function test_a_section_switched_off_in_the_registry_leaves_every_client_surface_cleanly(): void
    {
        $report = $this->generate();
        $report->update(['section_settings' => ['sections' => ['recommendations' => false]]]);

        foreach ($this->clientSurfaces($report->refresh()) as $surface => $payload) {
            $this->assertNull($payload['attention'] ?? null, "{$surface}: a switched-off section still travels");
            $outline = $this->outline($payload);
            $this->assertFalse($outline['present'], $surface);
            $this->assertSame('disabled_by_operator', $outline['absent_reason'], $surface);
        }
    }

    /** A link flag of the old shape no longer governs the section: there is one switch, not two. */
    public function test_there_is_no_second_switch_on_the_link(): void
    {
        $this->assertNotContains('recommendations', ShareSections::FLAGS);
        $this->assertContains('attention', app(ReportSectionRegistry::class)->get('recommendations')->payloadKeys);
    }

    public function test_a_link_that_hides_spend_carries_no_cost_block(): void
    {
        $report = $this->generate();

        foreach ($this->clientSurfaces($report, hideSpend: true, skipPrint: true) as $surface => $payload) {
            $this->assertSame([], $payload['attention'], "{$surface}: a cost reached a link that hides spend");
        }
    }

    // ---------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function operatorItems(Report $report): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/attention")
            ->assertOk()
            ->json('data.items');
    }

    private function decide(Report $report, string $key, ?string $decision): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/attention/{$key}", ['decision' => $decision]);
    }

    private function generate(string $from = '2026-07-01', string $to = '2026-07-31'): Report
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Report '.$from,
            'type' => 'monthly', 'status' => 'processing', 'audience' => 'client',
            'period_start' => $from, 'period_end' => $to, 'currency' => 'SAR',
        ]);
        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));

        return $report->refresh();
    }

    /**
     * The client payload of each surface, freshly requested.
     *
     * @param  array<string,mixed>  $settings
     * @return array<string, array<string,mixed>>
     */
    private function clientSurfaces(Report $report, array $settings = [], bool $hideSpend = false, bool $skipPrint = false): array
    {
        $shares = app(ShareService::class);
        $from = $report->period_start->toDateString();
        $to = $report->period_end->toDateString();
        [, $snapshot] = $shares->create($report, ['settings' => $settings, 'hide_spend' => $hideSpend], $this->operator->id);
        [, $live] = $shares->create($report, [
            'mode' => 'live', 'settings' => $settings, 'hide_spend' => $hideSpend,
            'scope' => [
                'project_id' => (string) $this->project->id, 'campaign_ids' => $this->campaignIds,
                'providers' => ['meta'], 'earliest' => $from, 'latest' => $to,
            ],
        ], $this->operator->id);

        $out = [
            'live link' => $this->getJson("/api/v1/reports/shared/{$live}/live?from={$from}&to={$to}")->assertOk()->json('data'),
            'shared snapshot' => $this->getJson("/api/v1/reports/shared/{$snapshot}")->assertOk()->json('data.data'),
        ];

        if (! $skipPrint) {
            $token = 'print-'.uniqid();
            Cache::put('report-print:'.hash('sha256', $token), ['report_id' => (string) $report->id, 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client'], 300);
            $out['client PDF'] = $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data');
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function outline(array $payload): array
    {
        return array_column($payload['outline'], null, 'key')['recommendations'];
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $email, 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $this->tenant, portal: Portal::App, role: 'owner'));

        return $user;
    }

    /** @param array<string, array<string, float|int>> $days */
    private function seedCampaign(string $name, CampaignObjective $objective, array $days): void
    {
        $this->holdingTenant((string) $this->tenant->id);

        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => $objective->value,
        ]);
        $this->campaignIds[] = (string) $campaign->id;

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'ext-'.uniqid(), 'name' => $name, 'status' => 'active',
        ]);

        foreach ($days as $date => $figures) {
            foreach ($figures as $key => $value) {
                DailyMetric::withoutGlobalScopes()->create([
                    'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                    'external_account_id' => $this->account->getKey(), 'external_campaign_id' => $external->id,
                    'unified_campaign_id' => $campaign->id, 'provider' => 'meta',
                    'metric_key' => $key, 'metric_date' => $date, 'value' => $value,
                ]);
            }
        }
    }
}
