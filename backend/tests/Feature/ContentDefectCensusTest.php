<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Content Production Recovery — the census is the defect list, so it is held to three promises.
 *
 * It WRITES NOTHING (it is pointed at the live estate), it PRINTS nothing a workflow log must not
 * carry, and it puts each broken creative under the category that describes what the reader sees —
 * asserted per SECTION of the output, because a creative id appearing somewhere in the text proves
 * nothing about which finding it was filed under.
 *
 * Every fixture here expresses its defect in the data rather than in an assertion: the seeded demo
 * library holds no creative whose results live on its ads while its spend lives on itself, which is
 * why a green run on seeded data said nothing about the Owner's screen.
 */
final class ContentDefectCensusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $client;

    private ExternalCampaign $campaign;

    private ExternalAccount $account;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Census', 'slug' => 'cs-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential([
            'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snapchat', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $this->account = $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Account Name Must Not Print',
            'status' => 'active',
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'snapchat', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /** A diagnostic pointed at a live estate may not change it — every column of every row. */
    public function test_the_census_writes_nothing(): void
    {
        $this->mixedGrainLeadCreative();
        $this->spendOnlyCreative();
        $this->resultsWithoutSpendCreative();
        $this->fetchedCreativeWithNoAsset();

        $before = $this->tableDigests();

        $this->artisan('content:census')->assertExitCode(0);

        $this->assertSame($before, $this->tableDigests(), 'the census changed a table it was only meant to read');
    }

    /**
     * D — the live shape: Snapchat reports SPEND for the creative, and the LEADS it was bought for
     * arrive on its ads. The card reads the creative grain and never states the leads.
     */
    public function test_a_lead_creative_whose_leads_live_only_on_its_ads_is_filed_under_d(): void
    {
        $creative = $this->mixedGrainLeadCreative();

        $section = $this->section('D');

        $this->assertStringContainsString((string) $creative->getKey(), $section);
        $this->assertStringContainsString('leads', $section);
        $this->assertStringContainsString('card read grain=creative', $section);
        // And WHY the product did not take them: the ads cover one day against the card's two.
        $this->assertStringContainsString('days: card 2, other 1 — fewer, so not filled', $section);
        $this->assertStringContainsString('card spend converted', $section);
        $this->assertStringNotContainsString((string) $creative->getKey(), $this->section('C'));
    }

    /** B — a card that can state Spend and has nothing to render beside it. */
    public function test_a_spend_only_creative_is_filed_under_b(): void
    {
        $creative = $this->spendOnlyCreative();

        $this->assertStringContainsString((string) $creative->getKey(), $this->section('B'));
        $this->assertStringNotContainsString((string) $creative->getKey(), $this->section('C'));
    }

    /** C — indicators on the card, and no Spend anywhere to put beside them. */
    public function test_results_without_spend_are_filed_under_c_with_the_rung(): void
    {
        $creative = $this->resultsWithoutSpendCreative();

        $section = $this->section('C');

        $this->assertStringContainsString((string) $creative->getKey(), $section);
        $this->assertStringContainsString('the platform sent no spend at this grain', $section);
        $this->assertStringNotContainsString((string) $creative->getKey(), $this->section('B'));
    }

    /** A — a creative the platform returned with no asset is listed, and one with an asset is not. */
    public function test_a_creative_with_no_asset_is_filed_under_a_and_one_with_an_asset_is_not(): void
    {
        $bare = $this->fetchedCreativeWithNoAsset();
        $drawn = $this->spendOnlyCreative();
        $drawn->forceFill(['asset_url' => 'https://cdn.test/secret-signature-abc123.jpg'])->save();

        $section = $this->section('A');

        $this->assertStringContainsString((string) $bare->getKey(), $section);
        /*
         * The RUNG, not the word. A first draft asserted «unavailable», and emptying the absence-state
         * list left it passing — the fallback branch files the same creative as «state=unavailable
         * (unrecognised)», which says the census does not know the state it is reporting.
         */
        $this->assertStringContainsString('state=unavailable — fetched, platform exposed no asset', $section);
        $this->assertStringNotContainsString('(unrecognised)', $section);
        $this->assertStringNotContainsString((string) $drawn->getKey(), $section);
    }

    /** A healthy creative is filed nowhere — the census is not a list of everything. */
    public function test_a_creative_that_shows_its_media_spend_and_verdict_is_filed_nowhere(): void
    {
        $healthy = $this->healthyLeadCreative();

        $output = $this->census();

        foreach (['A', 'B', 'C', 'D'] as $category) {
            $this->assertStringNotContainsString((string) $healthy->getKey(), $this->section($category, $output));
        }
    }

    /** No url, no creative name, no account name reaches a log readable by everyone with repo access. */
    public function test_the_census_prints_no_url_and_no_name(): void
    {
        $creative = $this->fetchedCreativeWithNoAsset();
        $creative->forceFill([
            'video_url' => 'https://cdn.test/secret-signature-def456.mp4',
            'name' => 'Creative Name Must Not Print',
        ])->save();
        $this->spendOnlyCreative()->forceFill(['asset_url' => 'https://cdn.test/secret-signature-abc123.jpg'])->save();

        $output = $this->census();

        $this->assertStringNotContainsString('secret-signature', $output);
        $this->assertStringNotContainsString('Must Not Print', $output);
    }

    /**
     * E — the load-time blank the stored envelope cannot see.
     *
     * A preview can say `available` and hand the browser a link that answers 403, or a page instead of
     * a picture. Only loading it tells the two apart, and the census does that on the server — for the
     * PROMOTED creatives a reader is looking at, never printing the address it loaded.
     */
    public function test_fetch_names_the_promoted_creatives_whose_asset_does_not_load(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        Http::fake([
            'cdn.test/ok-signature.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'cdn.test/forbidden-signature.jpg' => Http::response('denied', 403, ['Content-Type' => 'text/plain']),
            'cdn.test/page-signature.jpg' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'cdn.test/film-signature.mp4' => Http::response('....', 206, ['Content-Type' => 'video/mp4']),
            '*' => Http::response('unexpected', 500),
        ]);

        $ok = $this->spendOnlyCreative();
        $ok->forceFill(['asset_url' => 'https://cdn.test/ok-signature.png', 'video_url' => 'https://cdn.test/film-signature.mp4'])->save();

        $forbidden = $this->spendOnlyCreative();
        $forbidden->forceFill(['asset_url' => 'https://cdn.test/forbidden-signature.jpg'])->save();

        $page = $this->spendOnlyCreative();
        $page->forceFill(['asset_url' => 'https://cdn.test/page-signature.jpg'])->save();

        // Never delivered in the window: not what a reader is looking at, so not loaded at all.
        $idle = $this->creative(['asset_url' => 'https://cdn.test/idle-signature.jpg']);

        Artisan::call('content:census', ['--project' => (string) $this->project->getKey(), '--fetch' => true]);
        $output = Artisan::output();
        $section = $this->section('E', $output);

        $this->assertStringContainsString('still  http 403', $section);
        $this->assertStringContainsString((string) $forbidden->getKey(), $section);
        $this->assertStringContainsString('not an image (content type text/html)', $section);
        $this->assertStringContainsString((string) $page->getKey(), $section);
        $this->assertStringNotContainsString((string) $ok->getKey(), $section, 'a loaded image and a playable film were reported as broken');
        // Four assets: the working creative's still AND its film, and one still each for the two broken ones.
        $this->assertStringContainsString('4 asset(s), 2 loaded', $output);

        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->url(), 'idle-signature'));
        $this->assertStringNotContainsString('signature', $output);
    }

    /** Without the flag nothing is loaded — the default census stays a pure read of the database. */
    public function test_the_census_loads_nothing_unless_asked(): void
    {
        Http::fake();

        $this->spendOnlyCreative()->forceFill(['asset_url' => 'https://cdn.test/ok-signature.png'])->save();

        $output = $this->census();

        Http::assertNothingSent();
        $this->assertStringNotContainsString('E — ', $output);
    }

    /**
     * The 12 zero-original creatives on Production: is that zero Snapchat's, or ours?
     *
     * `SnapchatConnector::entityPointToRow()` skips a MISSING key and casts a PRESENT one with
     * `(float)`, so a JSON null arrives as 0. Only the provider's own retained body can tell a reported
     * zero from a fabricated one, and `--raw` reads it — counting how spend arrived, never the amount.
     */
    public function test_raw_evidence_says_how_the_provider_sent_a_zero_original_spend(): void
    {
        $creative = $this->creative(['provider' => 'snapchat', 'campaign_id' => $this->unified('sales')->getKey()]);
        $this->adRow($creative, [
            'spend' => null, 'spend_original' => 0.0, 'original_currency' => 'USD',
            'impressions' => 500, 'clicks' => 9, 'conversions' => 3,
        ]);
        $adId = (string) ExternalAd::withoutGlobalScopes()->where('creative_id', $creative->getKey())->value('external_id');

        IntegrationRawPayload::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'external_account_id' => $this->account->getKey(),
            'provider' => 'snapchat',
            'resource' => 'insights',
            'window_start' => Carbon::today()->subDays(7)->toDateString(),
            'window_end' => Carbon::today()->toDateString(),
            'normalised_rows' => 0,
            'fetched_at' => now(),
            'payload' => ['timeseries_stats' => [['timeseries_stat' => [
                'id' => $adId, 'type' => 'AD',
                'timeseries' => [
                    ['start_time' => Carbon::today()->subDay()->toDateString().'T00:00:00.000+03:00', 'stats' => ['spend' => null, 'impressions' => 500]],
                    ['start_time' => Carbon::today()->subDays(2)->toDateString().'T00:00:00.000+03:00', 'stats' => ['spend' => 0, 'impressions' => 0]],
                ],
            ]]]],
        ]);

        $this->assertStringContainsString('original of ZERO', $this->section('C'));

        Artisan::call('content:census', ['--project' => (string) $this->project->getKey(), '--raw' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('C EVIDENCE', $output);
        $this->assertStringContainsString((string) $creative->getKey().'  ads in bodies 1, day-points 2 — spend: key absent 0, JSON null 1, zero 1, positive 0; delivered impressions on 1', $output);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────

    private function census(): string
    {
        Artisan::call('content:census', ['--project' => (string) $this->project->getKey()]);

        return Artisan::output();
    }

    /** The text under one category heading, up to the next. */
    private function section(string $category, ?string $output = null): string
    {
        $output ??= $this->census();

        if (preg_match('/^'.$category.' — .*?(?=^[A-E] — |\z)/ms', $output, $m) !== 1) {
            $this->fail("The census printed no section {$category}:\n".$output);
        }

        return $m[0];
    }

    private function unified(string $objective): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'U'.(++$this->seq),
            'objective' => $objective, 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function creative(array $attributes = []): ExternalCreative
    {
        $this->seq++;

        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'snapchat',
            'external_creative_id' => 'cr-'.$this->seq,
            'name' => 'Creative '.$this->seq,
            'format' => 'image',
            'status' => 'active',
            'source_type' => 'api',
            ...$attributes,
        ]);
    }

    /** @param array<string, float|int> $figures */
    private function creativeRow(ExternalCreative $creative, array $figures, ?Carbon $day = null): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => ($day ?? Carbon::today()->subDay())->toDateString(),
            ...$figures,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, float|int> $figures */
    private function adRow(ExternalCreative $creative, array $figures): void
    {
        $this->seq++;

        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'snapchat',
            'external_id' => 'ad-'.$this->seq,
            'name' => 'Ad',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'snapchat',
            'entity_type' => 'ad',
            'entity_id' => $ad->getKey(),
            'external_entity_id' => 'ad-'.$this->seq,
            'external_campaign_id' => $this->campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'attribution_window' => 'default',
            ...$figures,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mixedGrainLeadCreative(): ExternalCreative
    {
        $creative = $this->creative([
            'campaign_id' => $this->unified('leads')->getKey(),
            'asset_url' => 'data:image/png;base64,AAAA',
        ]);

        /*
         * The creative grain spans TWO days and its ads ONE, so the product correctly refuses to fill
         * the creative's figures from a grain covering fewer days — and the census must still report
         * the leads the card does not state. With equal coverage the product now fills them, which is
         * the fix this census exists to measure, and the creative correctly leaves D.
         */
        $this->creativeRow($creative, ['spend' => 60.0, 'impressions' => 4_500, 'clicks' => 70]);
        $this->creativeRow($creative, ['spend' => 60.0, 'impressions' => 4_500, 'clicks' => 70], Carbon::today()->subDays(2));
        $this->adRow($creative, ['spend' => 120.0, 'impressions' => 9_000, 'clicks' => 140, 'leads' => 12]);

        return $creative;
    }

    private function healthyLeadCreative(): ExternalCreative
    {
        $creative = $this->creative([
            'provider' => 'meta',
            'campaign_id' => $this->unified('leads')->getKey(),
            'asset_url' => 'data:image/png;base64,AAAA',
        ]);

        $this->adRow($creative, ['spend' => 80.0, 'impressions' => 5_000, 'clicks' => 90, 'leads' => 8]);

        return $creative;
    }

    /**
     * Spend with nothing else, at the AD grain — the only grain that can hold it.
     *
     * `creative_daily_metrics.impressions` and `.clicks` are NOT NULL DEFAULT 0, so a creative-grain
     * row always states two measured zeros beside its spend and cannot express this defect at all.
     * The first draft wrote the row there and the case failed for the fixture's reason, not the
     * product's.
     */
    private function spendOnlyCreative(): ExternalCreative
    {
        $creative = $this->creative(['provider' => 'meta', 'asset_url' => 'data:image/png;base64,AAAA']);

        $this->adRow($creative, ['spend' => 45.0]);

        return $creative;
    }

    private function resultsWithoutSpendCreative(): ExternalCreative
    {
        $creative = $this->creative([
            'provider' => 'meta',
            'campaign_id' => $this->unified('leads')->getKey(),
            'asset_url' => 'data:image/png;base64,AAAA',
        ]);

        $this->adRow($creative, ['impressions' => 7_000, 'clicks' => 60, 'leads' => 5]);

        return $creative;
    }

    private function fetchedCreativeWithNoAsset(): ExternalCreative
    {
        return $this->creative(['format' => 'image']);
    }

    /** @return array<string, string> */
    private function tableDigests(): array
    {
        $digests = [];

        foreach ([
            'external_creatives', 'external_ads', 'creative_daily_metrics', 'entity_daily_metrics',
            'daily_metrics', 'unified_campaigns', 'metric_sync_runs', 'integration_sync_runs', 'audit_logs',
        ] as $table) {
            $row = DB::table($table)
                ->selectRaw("COUNT(*) AS rows_found, MD5(COALESCE(string_agg(t::text, '' ORDER BY t::text), '')) AS digest")
                ->fromRaw("\"{$table}\" AS t")
                ->first();

            $digests[$table] = ((int) ($row->rows_found ?? 0)).':'.((string) ($row->digest ?? ''));
        }

        return $digests;
    }
}
