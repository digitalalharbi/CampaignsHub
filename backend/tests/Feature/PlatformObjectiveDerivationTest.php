<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignObjectiveResolver;
use App\Domains\Campaigns\Services\PlatformObjectiveMap;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REPORT-OBJECTIVE-002 — the objective comes from the platform, and a person's word outranks it.
 *
 * Until this existed, `external_campaigns.objective` held exactly what each platform reported and
 * nothing ever copied it onto the unified campaign the reports read. Every imported campaign sat at
 * the column default, so the figure that decides whether a campaign's spend reaches a client's cost
 * per order was classified from a value nobody had set.
 *
 * The tests are weighted towards what the resolver REFUSES to do, because every refusal here is a
 * way the classification could silently become wrong: overwriting a correction, guessing at an
 * unrecognised value, or picking a winner when two linked campaigns disagree.
 */
final class PlatformObjectiveDerivationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Derive Co', 'slug' => 'derive-co', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── the map ───────────────────────────────────────────────────────────────────────────────

    /**
     * Every platform's real vocabulary, including Meta's from BOTH sides of the 2022 rename.
     *
     * Dropping the legacy names would leave every campaign older than the rename unclassified — a
     * gap that grows the further back a report's window reaches, and one that would look like an
     * absence of campaigns rather than an absence of mapping.
     */
    public function test_it_translates_each_platforms_own_vocabulary(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $this->assertSame(CampaignObjective::Sales, $map->resolve('meta', 'OUTCOME_SALES'));
        $this->assertSame(CampaignObjective::Sales, $map->resolve('meta', 'CATALOG_SALES'));
        $this->assertSame(CampaignObjective::Reach, $map->resolve('meta', 'REACH'));
        $this->assertSame(CampaignObjective::Leads, $map->resolve('google', 'LEADS'));
        $this->assertSame(CampaignObjective::VideoViews, $map->resolve('tiktok', 'RF_VIDEO_VIEWS'));
        $this->assertSame(CampaignObjective::StoreVisits, $map->resolve('snapchat', 'PROMOTE_PLACES'));
        $this->assertSame(CampaignObjective::Conversions, $map->resolve('x', 'WEBSITE_CONVERSIONS'));
        $this->assertSame(CampaignObjective::Traffic, $map->resolve('linkedin', 'WEBSITE_VISIT'));
    }

    /**
     * Every platform, on every path a report separates — REPORT-OBJECTIVE-002.
     *
     * One spot-check per platform proved the table was wired; it did not prove that each platform
     * can express all four paths, which is the property the reporting contract depends on. A
     * platform missing its sales objective silently sends every one of that merchant's sales
     * campaigns to the awareness path, and the only symptom is a cost per order that looks
     * suspiciously good.
     *
     * `null` entries are honest gaps rather than omissions: X has no lead-generation objective in
     * the API this connector reads, and LinkedIn has no sales objective at all — its conversion
     * ceiling is `WEBSITE_CONVERSION`. Asserting the absence keeps a later editor from inventing one.
     */
    public function test_every_platform_expresses_every_path_it_actually_has(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $expected = [
            // provider => [awareness, traffic, leads, sales]
            'meta' => ['OUTCOME_AWARENESS', 'OUTCOME_TRAFFIC', 'OUTCOME_LEADS', 'OUTCOME_SALES'],
            'google' => ['BRAND_AWARENESS_AND_REACH', 'WEBSITE_TRAFFIC', 'LEADS', 'SALES'],
            'tiktok' => ['REACH', 'TRAFFIC', 'LEAD_GENERATION', 'PRODUCT_SALES'],
            'snapchat' => ['AWARENESS', 'DRIVE_TRAFFIC_TO_WEBSITE', 'LEAD_GENERATION', 'CATALOG_SALES'],
            'x' => ['REACH', 'WEBSITE_CLICKS', null, null],
            'linkedin' => ['BRAND_AWARENESS', 'WEBSITE_VISIT', 'LEAD_GENERATION', null],
        ];

        foreach ($expected as $provider => [$awareness, $traffic, $leads, $sales]) {
            $this->assertSame(MarketingPath::Awareness, $map->resolve($provider, $awareness)?->path(), "{$provider} awareness");
            $this->assertSame(MarketingPath::Traffic, $map->resolve($provider, $traffic)?->path(), "{$provider} traffic");

            if ($leads !== null) {
                $resolved = $map->resolve($provider, $leads);
                $this->assertSame(CampaignObjective::Leads, $resolved, "{$provider} leads");
                // A lead is a conversion and is NOT revenue — it must never reach ROAS.
                $this->assertFalse($resolved->isSales(), "{$provider} leads must not count as sales");
            }

            if ($sales !== null) {
                $resolved = $map->resolve($provider, $sales);
                $this->assertTrue($resolved->isSales(), "{$provider} sales");
                $this->assertSame(MarketingPath::Conversion, $resolved->path(), "{$provider} sales path");
            }
        }
    }

    /** Every platform refuses the same two ways: a value it does not know, and no value at all. */
    public function test_every_platform_refuses_an_unknown_and_a_missing_objective(): void
    {
        $map = app(PlatformObjectiveMap::class);

        foreach ($map->providers() as $provider) {
            $this->assertNull($map->resolve($provider, 'NOT_A_REAL_OBJECTIVE'), "{$provider} unknown");
            $this->assertNull($map->resolve($provider, null), "{$provider} missing");
            $this->assertNull($map->resolve($provider, '   '), "{$provider} blank");
        }

        // And a provider with no table of its own classifies nothing rather than borrowing one.
        $this->assertNull($map->resolve('pinterest', 'AWARENESS'));
    }

    /** Awareness, traffic, leads and sales never collapse into one another. */
    public function test_the_four_paths_stay_apart(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $awareness = $map->resolve('meta', 'OUTCOME_AWARENESS');
        $traffic = $map->resolve('meta', 'OUTCOME_TRAFFIC');
        $leads = $map->resolve('meta', 'OUTCOME_LEADS');
        $sales = $map->resolve('meta', 'OUTCOME_SALES');

        $this->assertNotSame($awareness->path(), $traffic->path());
        $this->assertNotSame($traffic->path(), $leads->path());
        // Leads and sales share the conversion PATH — that is correct, they are both conversions —
        // and they are still told apart by the only distinction that touches ROAS.
        $this->assertSame($leads->path(), $sales->path());
        $this->assertFalse($leads->isSales());
        $this->assertTrue($sales->isSales());
    }

    /** `google` and `google_ads` are one platform; they have drifted apart in this codebase before. */
    public function test_provider_aliases_resolve_to_the_same_table(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $this->assertSame($map->resolve('google', 'SALES'), $map->resolve('google_ads', 'SALES'));
        $this->assertSame($map->resolve('x', 'REACH'), $map->resolve('twitter', 'REACH'));
    }

    /** APIs and exports disagree about case and separators for the same objective. */
    public function test_it_reads_the_same_objective_however_it_is_spelled(): void
    {
        $map = app(PlatformObjectiveMap::class);

        foreach (['OUTCOME_SALES', 'outcome_sales', 'Outcome Sales', ' outcome-sales '] as $spelling) {
            $this->assertSame(CampaignObjective::Sales, $map->resolve('meta', $spelling), $spelling);
        }
    }

    /**
     * An unrecognised value is null — never a guess.
     *
     * Google's `advertising_channel_type` is the trap this pins: SEARCH is where an ad runs, not
     * what it is for, and it serves lead campaigns and shopping campaigns alike. Accepting it would
     * classify both the same way.
     */
    public function test_an_unrecognised_value_yields_nothing_rather_than_a_guess(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $this->assertNull($map->resolve('google', 'SEARCH'));
        $this->assertNull($map->resolve('meta', 'SOMETHING_NEW_IN_2027'));
        $this->assertNull($map->resolve('meta', null));
        $this->assertNull($map->resolve('meta', '   '));
        $this->assertNull($map->resolve('a_platform_we_do_not_support', 'SALES'));
    }

    // ── adoption ──────────────────────────────────────────────────────────────────────────────

    /** Linking is the moment the platform's answer becomes knowable, so it is when it is adopted. */
    public function test_linking_a_platform_campaign_adopts_its_objective(): void
    {
        $unified = $this->unified('Roll-up');
        $this->external($unified, 'meta', 'OUTCOME_SALES');

        $this->assertTrue(app(CampaignObjectiveResolver::class)->sync($unified));

        $unified->refresh();
        $this->assertSame(CampaignObjective::Sales->value, $unified->objective);
        $this->assertSame('platform', $unified->objective_source);
        // The platform's own string is kept, so «the platform is wrong» stays distinguishable from
        // «the platform never said».
        $this->assertSame('OUTCOME_SALES', $unified->objective_platform_value);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.objective.derived',
            'entity_id' => (string) $unified->id,
        ]);
    }

    /**
     * A person's correction outranks the platform, permanently.
     *
     * The correction exists precisely because the platform's answer was wrong, so letting a later
     * sweep overwrite it would make every fix last until the next sync and no longer.
     */
    public function test_a_manual_correction_is_never_overwritten_by_a_later_sync(): void
    {
        $unified = $this->unified('Corrected');
        $external = $this->external($unified, 'meta', 'OUTCOME_SALES');
        app(CampaignObjectiveResolver::class)->sync($unified);

        // A person reviews it: this roll-up is really a brand buy.
        $unified->refresh()->forceFill([
            'objective' => CampaignObjective::Awareness->value,
            'objective_source' => 'manual',
        ])->save();

        // The platform re-reports its own answer on the next sweep.
        $external->forceFill(['objective' => 'OUTCOME_SALES'])->save();
        $this->assertFalse(app(CampaignObjectiveResolver::class)->sync($unified->refresh()));

        $unified->refresh();
        $this->assertSame(CampaignObjective::Awareness->value, $unified->objective);
        $this->assertSame('manual', $unified->objective_source);
        // …and the platform's word is still on the record beside the correction.
        $this->assertSame('OUTCOME_SALES', $unified->objective_platform_value);
    }

    /**
     * Two linked campaigns that disagree leave the classification unset for a person.
     *
     * There is no honest single answer to «what is this roll-up for» when it gathers a reach buy and
     * a sales buy, and picking either would be inventing one. Unset keeps its spend off the sales
     * path in the meantime, which is the safe direction to be wrong in.
     */
    public function test_disagreeing_platform_campaigns_leave_it_for_a_person(): void
    {
        $unified = $this->unified('Mixed roll-up');
        $this->external($unified, 'meta', 'OUTCOME_SALES');
        $this->external($unified, 'meta', 'REACH');

        $this->assertFalse(app(CampaignObjectiveResolver::class)->sync($unified));

        $unified->refresh();
        $this->assertSame('unset', $unified->objective_source);
        // Whatever it was classified as, it is not counted as a sales campaign while unreviewed.
        $this->assertSame(MarketingPath::Awareness, CampaignObjective::Other->path());
    }

    /** Agreement across several platforms is still one answer, and all their strings are kept. */
    public function test_agreeing_platforms_are_adopted_and_every_raw_value_kept(): void
    {
        $unified = $this->unified('Cross-platform sales');
        $this->external($unified, 'meta', 'OUTCOME_SALES');
        $this->external($unified, 'tiktok', 'PRODUCT_SALES');

        $this->assertTrue(app(CampaignObjectiveResolver::class)->sync($unified));

        $unified->refresh();
        $this->assertSame(CampaignObjective::Sales->value, $unified->objective);
        $this->assertStringContainsString('OUTCOME_SALES', (string) $unified->objective_platform_value);
        $this->assertStringContainsString('PRODUCT_SALES', (string) $unified->objective_platform_value);
    }

    /** A value nothing recognises leaves the campaign unclassified rather than mislabelled. */
    public function test_an_unrecognised_platform_value_leaves_the_campaign_unclassified(): void
    {
        $unified = $this->unified('Unknown');
        $this->external($unified, 'google', 'SEARCH');

        $this->assertFalse(app(CampaignObjectiveResolver::class)->sync($unified));
        $this->assertSame('unset', $unified->refresh()->objective_source);
    }

    /**
     * REPORT-OBJECTIVE-002b — «the platform said something we do not understand» must not read the
     * same as «the platform never said anything».
     *
     * `provenance()` promises `platform_value` is «kept whatever happens afterwards — so the platform
     * is wrong about this is distinguishable from the platform never said». It was not: `sync()`
     * returned before writing it whenever nothing mapped, which is exactly the case the column was
     * added for. Every Google Ads campaign lands here — the connector reports
     * `advertisingChannelType`, deliberately unmapped because a channel is where an ad runs and not
     * what it is for — so an operator asked to classify one had a blank where the platform's own
     * word should be, and nothing to correct FROM.
     */
    public function test_an_unrecognised_value_is_still_recorded_for_a_person_to_read(): void
    {
        $unified = $this->unified('Unknowable');
        $this->external($unified, 'google', 'SEARCH');

        app(CampaignObjectiveResolver::class)->sync($unified);

        $unified->refresh();

        // Still unclassified — the classification must not move.
        $this->assertSame('unset', $unified->objective_source);
        $this->assertSame(CampaignObjective::Other->value, $unified->objective);
        // But the platform's word is on the record.
        $this->assertSame('SEARCH', $unified->objective_platform_value);
    }

    /** And a campaign no platform ever described keeps a null, which is a different fact. */
    public function test_a_campaign_the_platform_never_described_keeps_a_null(): void
    {
        $unified = $this->unified('Silent');
        $this->external($unified, 'google', '');

        app(CampaignObjectiveResolver::class)->sync($unified);

        $this->assertNull($unified->refresh()->objective_platform_value);
    }

    /** The two are told apart in words a screen prints unchanged, not only in a column. */
    public function test_the_provenance_note_distinguishes_unrecognised_from_never_stated(): void
    {
        $unknown = $this->unified('Unknown value');
        $this->external($unknown, 'google', 'SEARCH');
        app(CampaignObjectiveResolver::class)->sync($unknown);

        $silent = $this->unified('Never stated');

        $unknownNote = app(CampaignObjectiveResolver::class)->provenance($unknown->refresh())['note_ar'];
        $silentNote = app(CampaignObjectiveResolver::class)->provenance($silent)['note_ar'];

        $this->assertStringContainsString('SEARCH', $unknownNote);
        $this->assertNotSame($unknownNote, $silentNote);
        // Both still say the spend is held back — that has not changed.
        $this->assertStringContainsString('لا يدخل إنفاقه في تكلفة الطلب', $unknownNote);
        $this->assertStringContainsString('لا يدخل إنفاقه في تكلفة الطلب', $silentNote);
    }

    /**
     * A raw value recorded for an unclassified campaign must never be mistaken for a classification.
     *
     * The dangerous shape is a later reader treating «we have a platform_value» as «the platform
     * classified this», which would put an unclassified Google campaign's spend on the sales path.
     */
    public function test_recording_the_raw_value_does_not_make_the_campaign_count_as_sales(): void
    {
        $unified = $this->unified('Unknowable');
        $this->external($unified, 'google', 'SEARCH');
        app(CampaignObjectiveResolver::class)->sync($unified);

        $p = app(CampaignObjectiveResolver::class)->provenance($unified->refresh());

        $this->assertFalse($p['counts_as_sales']);
        $this->assertSame('awareness', $p['marketing_path']);
        $this->assertSame('unset', $p['source']);
    }

    /** The provenance block says who decided, in words a screen can print unchanged. */
    public function test_provenance_states_who_classified_the_campaign(): void
    {
        $unified = $this->unified('Provenanced');
        $this->external($unified, 'meta', 'OUTCOME_AWARENESS');
        app(CampaignObjectiveResolver::class)->sync($unified);

        $p = app(CampaignObjectiveResolver::class)->provenance($unified->refresh());

        $this->assertSame('platform', $p['source']);
        $this->assertSame('awareness', $p['marketing_path']);
        $this->assertFalse($p['counts_as_sales']);
        $this->assertFalse($p['reviewed']);
        $this->assertSame('OUTCOME_AWARENESS', $p['platform_value']);
        $this->assertSame('مأخوذ من المنصة تلقائيًا.', $p['note_ar']);
    }

    /** An unclassified campaign says so, and says what that means for the figures. */
    public function test_an_unclassified_campaign_says_its_spend_is_held_back(): void
    {
        $p = app(CampaignObjectiveResolver::class)->provenance($this->unified('Untouched'));

        $this->assertSame('unset', $p['source']);
        $this->assertStringContainsString('لا يدخل إنفاقه في تكلفة الطلب', $p['note_ar']);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    private function unified(string $name): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => CampaignObjective::Other->value,
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
    }

    private function external(UnifiedCampaign $unified, string $provider, string $objective): ExternalCampaign
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => 'acct-'.uniqid(), 'name' => ucfirst($provider),
            'currency' => 'SAR', 'status' => 'active',
        ]);

        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $unified->id,
            'provider' => $provider, 'external_id' => 'ext-'.uniqid(),
            'name' => 'Platform campaign', 'status' => 'active', 'objective' => $objective,
        ]);
    }
}
