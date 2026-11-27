<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * REPORT-LINK-FOLLOW-ACCOUNTS-001 — a live link keeps following its ad accounts.
 *
 * Production, 2026-11-27: the Karam Alnakhil link (/r/lfji…) held a frozen list of ten campaigns and no account ceiling. Five
 * campaigns launched afterwards never reached it, so the client read 9,580 USD and 1,331 orders while the project spent 12,061 USD
 * with 1,522 orders. Two defects: an in-place edit rebuilt the ceiling without `account_ids` / `dynamic_campaigns`, and a frozen link
 * had no way back to following its accounts.
 */
final class ReportLinkFollowAccountsTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->freezeWorld();
        $this->seed(PermissionSeeder::class);
        $this->buildWorld(2);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@world.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /** @return list<ExternalAccount> */
    private function metaAccounts(): array
    {
        return array_values(array_filter($this->boundAccounts, static fn (ExternalAccount $a): bool => $a->provider === 'meta'));
    }

    /** A frozen live link over the Meta campaigns as they exist now — the shape the Karam link was stored in. */
    private function frozenMetaLink(): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'Frozen', 'type' => 'live', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => self::WORLD_FROM, 'period_end' => self::WORLD_TO, 'data' => [],
        ]);
        $campaigns = DB::table('daily_metrics')->where('project_id', $this->project->id)
            ->whereIn('external_account_id', array_map(static fn (ExternalAccount $a): string => (string) $a->id, $this->metaAccounts()))
            ->distinct()->pluck('unified_campaign_id')->map(strval(...))->all();
        [$share, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->id, 'campaign_ids' => $campaigns, 'providers' => ['meta'],
                'earliest' => self::WORLD_FROM, 'latest' => self::WORLD_TO,
            ],
        ], null);
        app(TenantContext::class)->forget();

        return [$share, $raw, $report];
    }

    private function url(Report $report, ReportShare $share): string
    {
        return "/api/v1/projects/{$report->project_id}/reports/{$report->id}/shares/{$share->id}";
    }

    private function liveSpend(string $raw): float
    {
        return (float) $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data.totals.spend');
    }

    public function test_a_frozen_link_follows_its_own_accounts_and_picks_up_campaigns_launched_later(): void
    {
        [$share, $raw, $report] = $this->frozenMetaLink();
        $before = $this->liveSpend($raw);
        $this->assertGreaterThan(0, $before, 'the world has to reach the link');

        $this->growWorld(1, 2);
        $this->assertEqualsWithDelta($before, $this->liveSpend($raw), 0.01, 'fail-first: a frozen link never sees a campaign launched after it');

        $this->actingAs($this->owner, 'sanctum')->patchJson($this->url($report, $share), ['follow_accounts' => true])->assertOk();

        $scope = ReportShare::withoutGlobalScopes()->find($share->id)->scope;
        $this->assertTrue($scope['dynamic_campaigns']);
        $this->assertSame([], $scope['campaign_ids']);
        $metaIds = array_map(static fn (ExternalAccount $a): string => (string) $a->id, $this->metaAccounts());
        sort($metaIds);
        $stored = $scope['account_ids'];
        sort($stored);
        $this->assertSame($metaIds, $stored, 'only the accounts that fed the link — never the project\'s Snapchat or TikTok accounts');

        $after = $this->liveSpend($raw);
        $this->assertGreaterThan($before, $after, 'the campaign launched later is on the same URL now');

        $providers = array_column((array) $this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data.platforms'), 'provider');
        $this->assertSame(['meta'], array_values(array_unique($providers)));
    }

    public function test_editing_a_link_that_follows_its_accounts_keeps_it_following(): void
    {
        [$share, $raw] = $this->liveLink();
        $report = Report::withoutGlobalScopes()->find($share->report_id);

        $this->actingAs($this->owner, 'sanctum')->patchJson($this->url($report, $share), ['earliest' => '2026-10-03'])->assertOk();

        $scope = ReportShare::withoutGlobalScopes()->find($share->id)->scope;
        $this->assertTrue($scope['dynamic_campaigns'], 'an edit of one axis must not freeze the campaign list');
        $this->assertSame([], $scope['campaign_ids']);
        $this->assertCount(count($this->boundAccounts), $scope['account_ids']);
        $this->assertSame('2026-10-03', $scope['earliest']);

        $before = $this->liveSpend($raw);
        $this->growWorld(1, 4);
        $this->assertGreaterThan($before, $this->liveSpend($raw));
    }

    public function test_naming_campaigns_narrows_the_link_and_ends_follow(): void
    {
        [$share] = $this->liveLink();
        $report = Report::withoutGlobalScopes()->find($share->report_id);
        $one = (string) DB::table('daily_metrics')->where('project_id', $this->project->id)->whereNotNull('unified_campaign_id')->value('unified_campaign_id');

        $this->actingAs($this->owner, 'sanctum')->patchJson($this->url($report, $share), ['campaign_ids' => [$one]])->assertOk();

        $scope = ReportShare::withoutGlobalScopes()->find($share->id)->scope;
        $this->assertFalse($scope['dynamic_campaigns']);
        $this->assertSame([$one], $scope['campaign_ids']);
        $this->assertNotEmpty($scope['account_ids'], 'the account ceiling still bounds the narrowed link');
    }
}
