<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PERF-RECOVERY-001 — one deterministic world a public live report is built over.
 *
 * Three platforms, two bound ad accounts each, a project of campaigns on every objective, creatives on
 * both grains, period-reach answers for some windows and not others — plus the accounts a link must
 * NEVER show: one the operator deselected and one bound to another project. Ids come from a counter and
 * dates from a frozen clock, so two builds of this world are byte-identical and a payload can be held
 * to a stored baseline.
 *
 * Every account carrying rows here is explicitly bound or explicitly excluded. Rows of an account with
 * NO binding are the one thing `BoundAccountVisibility::UNBOUND_ROWS_VISIBLE` changes the answer for, so
 * they are seeded separately ({@see seedUnboundAccount()}) and the baseline is the same under either
 * value of that constant.
 */
trait BuildsLiveReportWorld
{
    public const WORLD_FROM = '2026-10-01';

    public const WORLD_TO = '2026-10-20';

    protected Tenant $tenant;

    protected Project $project;

    protected Project $otherProject;

    protected ClientWorkspace $workspace;

    /** @var array<string, array<string, string>> provider => connection id */
    private array $connections = [];

    /** @var list<ExternalAccount> bound and visible */
    protected array $boundAccounts = [];

    protected ExternalAccount $deselectedAccount;

    protected ExternalAccount $elsewhereAccount;

    private int $uuidCounter = 0;

    /** @var array<string, array<int, string>> */
    protected array $externalCampaignIds = [];

    protected function freezeWorld(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 09:00:00', 'UTC'));
        $this->uuidCounter = 0;
        Str::createUuidsUsing(fn () => sprintf('00000000-0000-4000-8000-%012d', ++$this->uuidCounter));
    }

    protected function thawWorld(): void
    {
        Str::createUuidsNormally();
        Carbon::setTestNow();
    }

    /** @param int $campaignsPerAccount unified campaigns per bound account */
    protected function buildWorld(int $campaignsPerAccount = 4): void
    {
        $this->tenant = Tenant::create(['name' => 'World', 'slug' => 'world', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-world', 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Project', 'status' => 'active']);
        $this->otherProject = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Other', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        foreach (['meta', 'snapchat', 'tiktok'] as $provider) {
            $this->connections[$provider] = [
                'id' => (string) app(TokenVault::class)->open(
                    tenantId: (string) $this->tenant->id,
                    provider: $provider,
                    tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
                    connectionName: $provider.'-conn',
                )->getKey(),
            ];

            foreach ([1, 2] as $n) {
                $account = $this->account($provider, "{$provider}-act-{$n}");
                $this->bind($account, $this->project, true);
                $this->boundAccounts[] = $account;
                $this->seedAccount($account, $provider, $campaignsPerAccount, $this->project);
            }
        }

        // Rows filed under THIS project by accounts that must stay invisible: deselected, and re-bound to another project.
        $this->deselectedAccount = $this->account('snapchat', 'snapchat-act-deselected');
        $this->bind($this->deselectedAccount, $this->project, false);
        $this->seedAccount($this->deselectedAccount, 'snapchat', 2, $this->project, 'ghost');

        $this->elsewhereAccount = $this->account('meta', 'meta-act-elsewhere');
        $this->bind($this->elsewhereAccount, $this->otherProject, true);
        $this->seedAccount($this->elsewhereAccount, 'meta', 2, $this->project, 'other');
    }

    /** More campaigns, with their creatives and rows, under every bound account — the same shapes, further along. */
    protected function growWorld(int $extra, int $alreadyHave): void
    {
        foreach ($this->boundAccounts as $account) {
            $this->seedAccount($account, (string) $account->provider, $extra, $this->project, 'live', $alreadyHave);
        }
    }

    /** An account with NO binding row anywhere, rows filed under the project — what the visibility constant decides. */
    protected function seedUnboundAccount(): ExternalAccount
    {
        $account = $this->account('tiktok', 'tiktok-act-unbound');
        $this->seedAccount($account, 'tiktok', 2, $this->project, 'unbound');

        return $account;
    }

    /** @return array{0: ReportShare, 1: string} the share and its raw token */
    protected function liveLink(bool $dynamic = true, ?array $accounts = null): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'Live', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => self::WORLD_FROM, 'period_end' => self::WORLD_TO, 'data' => [],
        ]);

        $ids = array_map(fn (ExternalAccount $a): string => (string) $a->id, $accounts ?? $this->boundAccounts);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        [$share, $raw] = app(ShareService::class)->create($report, [
            // The link's own choices that make the report ask per campaign: the campaign table and a configured «result».
            'settings' => [
                'campaign_breakdown' => true,
                'result_events' => ['meta' => ['all' => 'conversions'], 'snapchat' => ['all' => 'conversions'], 'tiktok' => ['all' => 'conversions']],
            ],
            'scope' => [
                'project_id' => (string) $this->project->id, 'campaign_ids' => [], 'account_ids' => $ids,
                'dynamic_campaigns' => $dynamic, 'providers' => [], 'earliest' => self::WORLD_FROM, 'latest' => self::WORLD_TO,
            ],
        ], null);

        return [$share, $raw];
    }

    private function account(string $provider, string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connections[$provider]['id'], 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'currency' => 'SAR', 'status' => 'active',
            'last_synced_at' => Carbon::now()->subHours(3),
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $account->provider, 'purpose' => 'reporting', 'is_active' => $active,
        ]);
    }

    /**
     * Campaigns, metrics, creatives and ads for one account, filed under `$project`.
     *
     * Campaign `k` takes its objective, reach shape and creative formats from `k`, so every account holds
     * the same mix and every code path that a mix reaches is walked: a campaign whose reach is one
     * provider row (deduplicated), one whose reach spans days with a stored period answer, one with a
     * span and no answer, and one that reports no reach at all.
     */
    private function seedAccount(ExternalAccount $account, string $provider, int $campaigns, Project $project, string $label = 'live', int $first = 0): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()];
        $objectives = ['sales', 'leads', 'awareness', 'traffic'];
        $dates = [];
        for ($d = Carbon::parse(self::WORLD_FROM); $d->lte(Carbon::parse(self::WORLD_TO)); $d = $d->copy()->addDay()) {
            $dates[] = $d->toDateString();
        }
        $accountIndex = crc32($account->external_id) % 7 + 1;

        for ($k = $first; $k < $first + $campaigns; $k++) {
            $objective = $objectives[$k % 4];
            $unified = (string) Str::uuid();
            $external = (string) Str::uuid();
            $this->externalCampaignIds[$account->external_id][$k] = $external;

            DB::table('unified_campaigns')->insert($base + [
                'id' => $unified, 'client_workspace_id' => $this->workspace->id, 'name' => "{$label} {$account->external_id} c{$k}",
                'objective' => $objective, 'objective_source' => 'declared', 'status' => 'active', 'platforms' => json_encode([$provider]),
            ]);
            DB::table('external_campaigns')->insert($base + [
                'id' => $external, 'external_account_id' => $account->id, 'unified_campaign_id' => $unified, 'provider' => $provider,
                'external_id' => "ext-{$account->external_id}-c{$k}", 'name' => "n{$k}", 'status' => 'active', 'objective' => $objective,
                'starts_at' => Carbon::parse('2026-09-15'),
            ]);

            foreach ($dates as $i => $date) {
                $spend = 20 + $accountIndex * 3 + $k * 5 + ($i * $i % 13);
                $row = ['spend' => $spend, 'impressions' => 1000 + $i * 40 + $k * 100, 'clicks' => 30 + ($i % 7) + $k, 'conversions' => ($i + $k) % 4];
                // Reach shapes: 0 = a single day only (one provider grain); 1 = every day; 2 = every day; 3 = none.
                if ($k % 4 === 0 && $i === 3) {
                    $row['reach'] = 800 + $k;
                } elseif ($k % 4 === 1 || $k % 4 === 2) {
                    $row['reach'] = 600 + $i;
                }
                foreach ($row as $key => $value) {
                    DB::table('daily_metrics')->insert($base + [
                        'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $external,
                        'unified_campaign_id' => $unified, 'provider' => $provider, 'metric_key' => $key, 'metric_date' => $date,
                        'value' => $value, 'project_currency' => 'SAR', 'original_currency' => 'SAR', 'original_amount' => $value,
                        'data_freshness_at' => Carbon::now()->subHours(3),
                    ]);
                }
            }

            // A stored provider answer for the exact window of campaigns shaped 1 — the others have none.
            if ($k % 4 === 1) {
                DB::table('period_reach')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'external_account_id' => $account->id, 'provider' => $provider,
                    'grain' => 'campaign', 'external_entity_id' => "ext-{$account->external_id}-c{$k}", 'external_campaign_id' => $external,
                    'date_from' => self::WORLD_FROM, 'date_to' => self::WORLD_TO, 'reach' => 9000 + $k, 'frequency' => 1.5,
                    'impressions' => 13500, 'state' => 'reported', 'fetched_at' => Carbon::now()->subHour(),
                    'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
                ]);
            }

            // Two creatives per campaign (one per format) on both grains: creative rows on snapchat, ad rows on the others.
            foreach (['image', 'video'] as $f => $format) {
                $creative = (string) Str::uuid();
                $adSet = (string) Str::uuid();
                $ad = (string) Str::uuid();

                DB::table('external_creatives')->insert($base + [
                    'id' => $creative, 'external_campaign_id' => $external, 'campaign_id' => $unified, 'provider' => $provider,
                    'external_creative_id' => "cr-{$account->external_id}-{$k}-{$f}", 'name' => "{$label} creative {$account->external_id} {$k} {$format}",
                    'format' => $format, 'asset_url' => 'https://cdn.example/'.$account->external_id.'/'.$k.'/'.$f,
                    'last_active_at' => Carbon::parse(self::WORLD_TO),
                ]);
                DB::table('external_ad_sets')->insert($base + [
                    'id' => $adSet, 'external_campaign_id' => $external, 'unified_campaign_id' => $unified, 'provider' => $provider,
                    'external_id' => "as-{$account->external_id}-{$k}-{$f}", 'name' => 'as',
                ]);
                DB::table('external_ads')->insert($base + [
                    'id' => $ad, 'external_campaign_id' => $external, 'external_ad_set_id' => $adSet, 'unified_campaign_id' => $unified,
                    'creative_id' => $creative, 'provider' => $provider, 'external_id' => "ad-{$account->external_id}-{$k}-{$f}", 'name' => 'ad',
                ]);

                foreach ($dates as $i => $date) {
                    $spend = 5 + $f * 2 + $k + ($i * $i % 11);
                    $figures = [
                        'spend' => $spend, 'impressions' => 400 + $i * 13 + $f * 50 + ($i * $i % 17), 'clicks' => 8 + ($i * 5 % 9) + $f,
                        'conversions' => ($i * 7 + $k * 3 + $f * 5) % 5, 'leads' => ($i * 3 + $f) % 4, 'revenue' => ($i + $k) % 3 === 0 ? 40 + $f * 10 : null,
                    ];

                    if ($provider === 'snapchat') {
                        DB::table('creative_daily_metrics')->insert($base + [
                            'id' => (string) Str::uuid(), 'creative_id' => $creative, 'campaign_id' => $unified, 'metric_date' => $date,
                            'spend' => $figures['spend'], 'impressions' => $figures['impressions'], 'clicks' => $figures['clicks'],
                            'conversions' => $figures['conversions'], 'revenue' => $figures['revenue'],
                        ]);
                    }

                    // The ad grain carries every provider, including the ones the creative grain never fills.
                    DB::table('entity_daily_metrics')->insert($base + [
                        'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $external,
                        'external_ad_set_id' => $adSet, 'provider' => $provider, 'entity_type' => 'ad', 'entity_id' => $ad,
                        'external_entity_id' => "ad-{$account->external_id}-{$k}-{$f}", 'metric_date' => $date,
                    ] + $figures);
                }
            }
        }
    }

    /** True when an unbound account's rows are part of what a link shows (the visibility constant's current reading). */
    protected function unboundRowsShow(): bool
    {
        return BoundAccountVisibility::UNBOUND_ROWS_VISIBLE;
    }
}
