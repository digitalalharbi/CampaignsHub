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
