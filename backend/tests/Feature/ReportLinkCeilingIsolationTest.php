<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Reports\Models\ReportScopeTemplate;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ReportBreakdowns;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsDrilldownFixture;
use Tests\TestCase;
use Throwable;

/**
 * SECURITY CLUSTER 5 — a public report link shows exactly what its ceiling allows, on EVERY surface.
 *
 * The drill-down fixture holds a project with a meta account and a tiktok account. A link granted
 * ONLY the meta account must never publish a figure that belongs to the tiktok one: not in the PDF's
 * platform drill-down, not in the format comparison, not in the store funnel's ad side.
 */
final class ReportLinkCeilingIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDrilldownFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDrilldownFixture();
    }

    private function accountId(string $externalId): string
    {
        return (string) ExternalAccount::withoutGlobalScopes()->where('external_id', $externalId)->value('id');
    }

    /** A link granted the meta account (and its one campaign) of a project that also holds tiktok. */
    private function metaOnlyShare(array $over = []): array
    {
        return app(ShareService::class)->create($this->report, $over + [
            'allow_download' => true,
            'scope' => [
                'project_id' => (string) $this->project->id,
                'account_ids' => [$this->accountId('act-meta')],
                'campaign_ids' => [$this->campaigns[0]],
                'providers' => [],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);
    }

    /** @return list<array<string, mixed>> */
    private function printedDrilldowns(ReportShare $share): array
    {
        $token = 'ceil-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $this->report->id, 'type' => 'document', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->id,
        ], 300);

        return $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data.platform_drilldowns') ?? [];
    }

    public function test_a_narrowed_links_pdf_drilldown_prints_only_the_links_accounts(): void
    {
        // A live-builder report holds no scope of its own: the ceiling lives on the share.
        $this->report->forceFill([
            'scope' => null,
            'config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]],
            'audience' => 'client',
            'data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31']],
        ])->saveQuietly();

        [$share] = $this->metaOnlyShare();
        $blocks = collect($this->printedDrilldowns($share));

        $this->assertSame(['meta'], $blocks->pluck('provider')->sort()->values()->all(), 'the PDF drill-down printed a platform the link was never granted');
        $this->assertEqualsWithDelta(300.0, $blocks->firstWhere('provider', 'meta')['totals']['spend'], 0.0001);
    }

    public function test_a_saved_reports_narrower_link_is_not_widened_back_to_the_reports_scope(): void
    {
        $this->report->forceFill([
            'scope' => ['project_ids' => [(string) $this->project->id], 'campaign_ids' => $this->campaigns],
            'config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]],
            'audience' => 'client',
            'data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31']],
        ])->saveQuietly();

        [$share] = $this->metaOnlyShare();

        $this->assertSame(['meta'], collect($this->printedDrilldowns($share))->pluck('provider')->sort()->values()->all());
    }

    public function test_the_content_format_comparison_counts_only_the_links_accounts(): void
    {
        // Distinguish the two accounts' creatives by format so the comparison shows who was counted.
        DB::table('external_creatives')->where('provider', 'tiktok')->update(['format' => 'video']);
        [, $raw] = $this->metaOnlyShare();

        $formats = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data.content_formats');

        $this->assertIsArray($formats);
        $counted = json_encode($formats['coverage'] ?? []).json_encode($formats['objectives'] ?? []).json_encode($formats['spend_mix'] ?? []);
        $this->assertStringNotContainsString('video', $counted, "the tiktok account's creatives were counted in a meta-only link's comparison");
    }

    public function test_the_store_funnels_ad_side_is_the_links_accounts_only(): void
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Salla',
        );
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 'store-1',
            'name' => 'Shop', 'currency' => 'SAR', 'status' => 'active',
        ]);
        CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->id,
            'external_account_id' => $store->getKey(), 'provider' => 'salla', 'external_id' => 'o-1', 'status' => 'completed',
            'placed_at' => Carbon::parse('2026-07-15'), 'currency' => 'SAR', 'total' => 250,
            'attribution_method' => 'none', 'attributed_at' => Carbon::now(),
        ]);

        [, $raw] = $this->metaOnlyShare();
        $funnel = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data.store_funnel');

        $this->assertNotNull($funnel);
        $this->assertEqualsWithDelta(300.0, (float) $funnel['totals']['spend'], 0.0001, "the tiktok account's spend was published in a meta-only link's store funnel");
        $this->assertSame(['meta'], collect($funnel['comparisons']['platforms'])->pluck('platform')->all());
    }

    public function test_a_password_protected_links_attribution_needs_the_password(): void
    {
        $this->report->forceFill(['data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31']]])->saveQuietly();
        [, $raw] = app(ShareService::class)->create($this->report, [
            'password' => 'open-sesame',
            'settings' => ['sections' => ['attribution' => true]],
        ], null);

        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertStatus(401);
        $this->getJson("/api/v1/reports/shared/{$raw}/attribution", ['X-Report-Password' => 'wrong'])->assertStatus(401);
        $this->getJson("/api/v1/reports/shared/{$raw}/attribution", ['X-Report-Password' => 'open-sesame'])->assertOk();
    }

    public function test_a_failed_generation_leaves_no_project_behind_in_the_worker(): void
    {
        // A window that cannot be read: the generation dies right after the project was set.
        $this->report->setRawAttributes(['period_end' => 'not-a-date'] + $this->report->getAttributes());

        try {
            app(ReportGenerator::class)->generate($this->report);
            $this->fail('the generation was expected to fail');
        } catch (Throwable) {
            // expected
        }

        $this->assertFalse(app(ProjectContext::class)->hasProject(), 'the failed job handed its project to the next job in the worker');
    }

    public function test_a_queue_worker_starts_each_job_without_a_project(): void
    {
        app(ProjectContext::class)->setProjectId((string) $this->project->id);

        event(new JobProcessing('redis', \Mockery::mock(Job::class, ['payload' => []])));

        $this->assertFalse(app(ProjectContext::class)->hasProject());
    }

    /** Ad sets and ads under every fixture campaign, named after it, so a leaked name says whose it is. */
    private function seedAdSetsAndAds(): void
    {
        foreach (ExternalCampaign::withoutGlobalScopes()->where('project_id', $this->project->id)->get() as $external) {
            $set = ExternalAdSet::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $external->getKey(),
                'unified_campaign_id' => $external->unified_campaign_id, 'provider' => $external->provider,
                'external_id' => 'as-'.uniqid(), 'name' => $external->name.' set', 'status' => 'active',
            ]);
            ExternalAd::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_ad_set_id' => $set->getKey(),
                'external_campaign_id' => $external->getKey(), 'unified_campaign_id' => $external->unified_campaign_id,
                'provider' => $external->provider, 'external_id' => 'ad-'.uniqid(), 'name' => $external->name.' ad', 'status' => 'active',
            ]);
        }
    }

    public function test_the_scope_pickers_never_name_entities_of_an_account_the_project_does_not_show(): void
    {
        $this->seedAdSetsAndAds();
        $base = "/api/v1/projects/{$this->project->id}/reports/scope/options";

        $bodies = [$this->actingAs($this->operator, 'sanctum')->getJson($base)->assertOk()->getContent()];
        foreach (['campaigns', 'ad_sets', 'ads', 'creatives'] as $axis) {
            $bodies[] = $this->actingAs($this->operator, 'sanctum')->getJson("{$base}?axis={$axis}")->assertOk()->getContent();
        }
        $bodies[] = $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/reports/live/options")->assertOk()->getContent();

        foreach ($bodies as $n => $body) {
            $this->assertStringNotContainsString('Meta Elsewhere', $body, "picker response #{$n} named an entity of the deselected account");
        }
        // The project's own entities are still offered.
        $this->assertStringContainsString('Meta Summer set', $bodies[2]);
        $this->assertStringContainsString('Meta Summer ad', $bodies[3]);
        $this->assertStringContainsString('Meta Summer creative', $bodies[4]);
        $this->assertStringContainsString('Meta Summer', $bodies[1]);
    }

    /** A person whose reach is ONE project: a tenant role that reads reports, and a manager membership on this project only. */
    private function projectManager(): User
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'RM', 'slug' => 'rm-'.uniqid()]);
        $role->givePermissionTo('reports.view', 'projects.view');
        $user = User::create(['name' => 'PM', 'email' => 'pm-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);
        ProjectMembership::create(['project_id' => $this->project->id, 'user_id' => $user->id, 'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active']);
        app(TenantContext::class)->forget();

        return $user;
    }

    private function sharedTemplate(): ReportScopeTemplate
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $template = ReportScopeTemplate::create(['project_id' => null, 'name' => 'Tenant-wide', 'scope' => ['providers' => ['meta']]]);
        app(TenantContext::class)->forget();

        return $template;
    }

    public function test_a_project_restricted_manager_cannot_change_or_remove_a_tenant_wide_template(): void
    {
        $template = $this->sharedTemplate();
        $manager = $this->projectManager();
        $base = "/api/v1/projects/{$this->project->id}/reports/scope-templates";

        $this->actingAs($manager, 'sanctum')->patchJson("{$base}/{$template->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->putJson("{$base}/{$template->id}/sections", ['sections' => []])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->deleteJson("{$base}/{$template->id}")->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson($base, ['name' => 'Mine for everyone', 'shared' => true, 'scope' => ['providers' => ['meta']]])->assertForbidden();

        $this->assertSame('Tenant-wide', ReportScopeTemplate::withoutGlobalScopes()->find($template->id)->name);

        // Their own project's templates are still theirs to manage.
        $this->actingAs($manager, 'sanctum')->postJson($base, ['name' => 'Mine', 'scope' => ['providers' => ['meta']]])->assertCreated();
    }

    public function test_a_tenant_administrator_edits_a_shared_template_but_cannot_put_one_projects_ids_in_it(): void
    {
        $template = $this->sharedTemplate();
        $base = "/api/v1/projects/{$this->project->id}/reports/scope-templates/{$template->id}";

        $this->actingAs($this->operator, 'sanctum')->patchJson($base, ['name' => 'Renamed'])->assertOk();
        $this->actingAs($this->operator, 'sanctum')
            ->patchJson($base, ['scope' => ['campaign_ids' => [$this->campaigns[0]]]])
            ->assertUnprocessable();
    }

    public function test_a_new_report_cannot_store_a_scope_naming_another_project(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other client', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $url = "/api/v1/projects/{$this->project->id}/reports";
        $body = fn (array $scope): array => ['name' => 'R', 'type' => 'executive', 'scope' => ['account_ids' => [$this->accountId('act-meta')]] + $scope];

        $this->actingAs($this->operator, 'sanctum')->postJson($url, $body(['project_ids' => [(string) $other->id]]))->assertUnprocessable();
        $this->actingAs($this->operator, 'sanctum')->postJson($url, $body(['project_ids' => [(string) $this->project->id, (string) $other->id]]))->assertUnprocessable();
        // Its own project is a statement of the frame, not a lift of it.
        $this->actingAs($this->operator, 'sanctum')->postJson($url, $body(['project_ids' => [(string) $this->project->id]]))->assertCreated();
    }
}
