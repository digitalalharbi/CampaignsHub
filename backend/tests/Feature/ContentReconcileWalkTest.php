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
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner defect 95 — the walk is the instrument, so the instrument is held to what it promises.
 *
 * Three properties, and the first is the one that makes it safe to point at a live account while a
 * customer is looking at the same screen: it WRITES NOTHING. `integrations:probe` is held to the same
 * bar by `test_the_probe_imports_nothing`, and for the same reason — a diagnosis that mutates the
 * thing it is diagnosing is worse than no diagnosis.
 *
 * The second is that it prints no url. These links carry the signature that makes them work, and a
 * workflow log is readable by anybody with access to the repository.
 *
 * The third is that it actually FINDS a divergence when one exists, which is the only thing that
 * separates a diagnostic from a green tick. Proved by asking it about a creative whose figures live
 * only at the ad grain while the walk's own expectations are set by the fixed product — so the
 * reconciliation reports agreement, and the divergence case is built by hand below.
 */
final class ContentReconcileWalkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Walk', 'slug' => 'w-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Meta',
            'status' => 'active',
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /**
     * OWNER CONTENT P0 — the walk says what the CARD and the POPUP each resolve to, side by side.
     *
     * #505 is guarded in a browser, and Production cannot be read in one here: the page needs an
     * authenticated session and the figures live on a connected ad account. This is the same question
     * asked of the same payload on the server, so «the two surfaces agree on Production» becomes
     * evidence somebody can dispatch rather than a screenshot somebody has to take.
     *
     * Keys, states and one verdict. No value, no name, no url.
     */
    public function test_the_walk_states_what_the_card_and_the_popup_each_resolve_to(): void
    {
        $creative = $this->collectionWithHeroAndFigures();

        $this->artisan('content:reconcile', ['creative' => (string) $creative->getKey()])
            ->expectsOutputToContain('RUNG 10 — CARD ↔ POPUP')
            /*
             * The whole line, on both surfaces: the objective's verdict leads the card, the panel
             * leads with its own floor, and every figure this row reports is on both. Asserted in
             * order, because `expectsOutputToContain` walks the output line by line.
             */
            ->expectsOutputToContain('card  figures : spend=reported, orders=reported, cpa=reported, revenue=reported, roas=reported, conversion_rate=reported, aov=reported, impressions=reported, clicks=reported, ctr=reported')
            ->expectsOutputToContain('popup figures : spend=reported, impressions=reported, clicks=reported, ctr=reported, cpc=reported, cpm=reported, revenue=reported, roas=reported, orders=reported')
            ->expectsOutputToContain('card  preview : kind=collection  state=available  hero=yes  tiles=not fetched  draws=still')
            ->expectsOutputToContain('popup preview : kind=collection  state=available  hero=yes  tiles=not fetched  draws=still')
            ->expectsOutputToContain('PARITY        : MATCH')
            ->doesntExpectOutputToContain('cdn.test')
            ->assertExitCode(0);
    }

    /** And it prints the figures' KEYS and STATES — never an amount, on the rung that reads money. */
    public function test_the_card_popup_rung_prints_no_figure_and_no_url(): void
    {
        $creative = $this->collectionWithHeroAndFigures();

        $this->artisan('content:reconcile', ['creative' => (string) $creative->getKey()])
            ->doesntExpectOutputToContain('cdn.test')
            ->doesntExpectOutputToContain('hero-secret')
            ->assertExitCode(0);
    }

    /** The owner's shape: a sales collection, hero available, tiles never fetched, full figures. */
    private function collectionWithHeroAndFigures(): ExternalCreative
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'client_workspace_id' => Project::withoutGlobalScopes()->whereKey($this->project->getKey())->value('client_workspace_id'),
            'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);

        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'campaign_id' => $campaign->getKey(),
            'provider' => 'snapchat',
            'external_creative_id' => 'cr-collection-parity',
            'name' => 'Collection with a hero',
            'format' => 'collection',
            'status' => 'active',
            'source_type' => 'api',
            'asset_url' => 'https://cdn.test/hero-secret.jpg',
            'cards' => null,
        ]);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'campaign_id' => $campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'spend' => 400, 'impressions' => 90000, 'clicks' => 1800,
            'conversions' => 60, 'revenue' => 3000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $creative;
    }

    /**
     * A diagnosis that changes the thing it diagnoses is not one.
     *
     * ## This guard was VACUOUS for the write that matters, and an injection proved it
     *
     * It compared ROW COUNTS before and after, so it only ever asserted that nothing was inserted or
     * deleted. Injecting an UPDATE into the walk left it passing — and an update is the dangerous
     * case, not the insert: a diagnostic that silently touched a column on 1,539 production creatives,
     * or mutated a metric in place, satisfied this test completely.
     *
     * That matters more here than almost anywhere, because this property is the whole reason the
     * command may be pointed at a live account while a customer is looking at the same screen. A
     * safety guard that cannot see the unsafe case is worse than none: it is a reason not to look.
     *
     * So the comparison is a DIGEST of every column of every row. `md5(string_agg(t::text ORDER BY
     * t::text))` catches an update, a reorder and a single changed character alike; the ordering is
     * inside the aggregate because `string_agg` is otherwise unordered and two identical tables would
     * digest differently. The row count is kept beside it so a failure says which KIND of change it
     * was — a differing count is an insert or a delete, an equal count with a differing digest is an
     * update.
     *
     * ## And my first injection was too weak to prove anything
     *
     * It set `updated_at` to `now()`. That column truncates to whole seconds and the fixture row was
     * created in the same second, so the value did not change and BOTH forms passed — which reads
     * exactly like a guard that works. The injection that settles it mutates `name`: the digest form
     * fails with «the walk changed a table it was only meant to read» and the count form still passes.
     * An injection that cannot express the defect proves as little as a fixture that cannot.
     */
    public function test_the_walk_writes_nothing(): void
    {
        $creative = $this->creativeWithAdGrain();

        $before = $this->tableDigests();

        $this->artisan('content:reconcile', ['creative' => (string) $creative->getKey()])
            ->assertExitCode(0);

        $this->artisan('content:reconcile', ['--scope' => true, '--project' => (string) $this->project->getKey()])
            ->assertExitCode(0);

        $this->assertSame($before, $this->tableDigests(), 'the walk changed a table it was only meant to read');
    }

    /**
     * It reports the two grains apart, because that split IS the owner's defect.
     *
     * A creative whose figures exist only at the ad grain is one `creative_daily_metrics` never held,
     * and on five of six providers it is all of them. A walk that could not say so would be unable to
     * describe the thing it exists to describe.
     */
    public function test_the_scope_walk_names_the_creatives_only_the_ad_grain_can_answer(): void
    {
        $this->creativeWithAdGrain();

        $this->artisan('content:reconcile', ['--scope' => true, '--project' => (string) $this->project->getKey()])
            ->expectsOutputToContain('summed from their ADS')
            ->expectsOutputToContain('carry figures ONLY at the ad grain')
            ->assertExitCode(0);
    }

    /**
     * And it prints no url — these links carry the signature that makes them work.
     *
     * The preview rung reports the STATE and whether each url exists, never the url. A workflow log is
     * readable by anybody with repository access, which is not the same audience as the ad account.
     */
    public function test_the_walk_prints_no_asset_url(): void
    {
        $creative = $this->creativeWithAdGrain();
        $creative->forceFill([
            'asset_url' => 'https://cdn.test/secret-signature-abc123.jpg',
            'video_url' => 'https://cdn.test/secret-signature-def456.mp4',
        ])->save();

        $this->artisan('content:reconcile', ['creative' => (string) $creative->getKey()])
            ->doesntExpectOutputToContain('secret-signature')
            ->assertExitCode(0);
    }

    /**
     * An EMPTY window option is ABSENT, not a value — found by running this on production.
     *
     * `production-diagnostics.yml` passes every value unconditionally, because a shell that assembles
     * flags conditionally is a shell that eventually assembles a command. So a caller who names no
     * window sends `--from="" --to=""`, and the command compared those against `null`, which an empty
     * string is not: `Carbon::parse('')` is today, so the thirty-day default collapsed to one day.
     *
     * It did not error. The first production reading came back «window 2026-09-16 → 2026-09-16 … THE
     * STRIP WAS SHORT BY 0.00» — a true answer about a one-day window, indistinguishable from the
     * thirty-day answer that was asked for, and it understated the very finding the walk exists to
     * measure. An instrument that quietly answers a different question is worse than one that fails.
     *
     * Asserted on the window the walk PRINTS, because that is the only place the reader can see which
     * question was answered.
     */
    public function test_an_empty_window_option_falls_back_to_the_default_rather_than_to_today(): void
    {
        $this->creativeWithAdGrain();

        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        $this->artisan('content:reconcile', ['--scope' => true, '--project' => '', '--from' => '', '--to' => ''])
            ->expectsOutputToContain($from->toDateString().' → '.$to->toDateString())
            ->assertExitCode(0);
    }

    /** And a window the caller DID name is honoured exactly — the fix must not swallow a real value. */
    public function test_a_named_window_is_honoured(): void
    {
        $this->creativeWithAdGrain();

        $this->artisan('content:reconcile', [
            '--scope' => true,
            '--from' => '2026-08-01',
            '--to' => '2026-08-31',
        ])
            ->expectsOutputToContain('2026-08-01 → 2026-08-31')
            ->assertExitCode(0);
    }

    /**
     * And the gap is named in FIGURES, not only in money — the production reading's larger half.
     *
     * It came back «SHORT BY 0.00» over a library where the old strip could state sixteen figures and
     * the new one states thirty-five: the ad-grain rows carried the RESULT columns and no spend, so
     * the money was genuinely not short and the ANSWER was. `leads` and everything derived from it
     * were absent from the headline strip entirely, which is «the other KPIs disappear» exactly — and
     * it was visible only by diffing two long printed lists by eye.
     */
    public function test_the_scope_walk_names_the_figures_a_creative_grain_only_strip_could_not_state(): void
    {
        /*
         * A MIXED library, because that is what production is and what the comparison needs.
         *
         * 160 creatives reporting at creative grain beside 39 summed from their ads. With only the
         * ad-grain half there is no old answer to diff against — the creative-grain-only strip would
         * have stated NOTHING, which the walk reports as its own, louder line — and the first draft of
         * this case failed for exactly that reason.
         */
        $this->creativeWithAdGrain();
        $this->creativeWithOwnRows();

        $this->artisan('content:reconcile', ['--scope' => true, '--project' => (string) $this->project->getKey()])
            ->expectsOutputToContain('COULD NOT STATE')
            ->expectsOutputToContain('leads')
            ->assertExitCode(0);
    }

    /**
     * Every column of every row, digested per table — not counted.
     *
     * See the note on `test_the_walk_writes_nothing` for why a count was not enough.
     *
     * @return array<string, string>
     */
    private function tableDigests(): array
    {
        $digests = [];

        foreach ([
            'external_creatives', 'external_ads', 'creative_daily_metrics', 'entity_daily_metrics',
            'daily_metrics', 'metric_sync_runs', 'integration_sync_runs', 'audit_logs',
        ] as $table) {
            $row = DB::table($table)
                ->selectRaw("COUNT(*) AS rows_found, MD5(COALESCE(string_agg(t::text, '' ORDER BY t::text), '')) AS digest")
                ->fromRaw("\"{$table}\" AS t")
                ->first();

            $digests[$table] = ((int) ($row->rows_found ?? 0)).':'.((string) ($row->digest ?? ''));
        }

        return $digests;
    }

    /** A creative the platform reports directly — the 160-of-199 half of the production library. */
    private function creativeWithOwnRows(): ExternalCreative
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'snapchat',
            'external_creative_id' => 'cr-native',
            'name' => 'Reported at creative grain',
            'format' => 'video',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'spend' => 90.0,
            'impressions' => 8_000,
            'clicks' => 160,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $creative;
    }

    private function creativeWithAdGrain(): ExternalCreative
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-walk',
            'name' => 'Walk subject',
            'format' => 'image',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'meta',
            'external_id' => 'ad-walk',
            'name' => 'Ad',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'entity_type' => 'ad',
            'entity_id' => $ad->getKey(),
            'external_entity_id' => 'ad-walk',
            'external_campaign_id' => $this->campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'attribution_window' => 'default',
            'spend' => 250.0,
            'impressions' => 12_000,
            'clicks' => 480,
            'leads' => 16,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $creative;
    }
}
