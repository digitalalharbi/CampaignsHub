<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Services\InsightRowNormaliser;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-ATTRIB-001 / META-VERSION-001 — the window every Meta figure is measured over, and the
 * Marketing API version it is fetched from.
 *
 * ## The window was never asked for, never known, and reported as if it had been checked
 *
 * `daily_metrics.attribution_window` exists so that two numbers measured over different windows are
 * never added together without a word to the reader. `NormalizedMetric` defaults it to the string
 * `'default'`, nothing ever passed anything else, and so EVERY row from EVERY provider carried the
 * same literal — which is what makes `MetricsController`'s `groupBy('attribution_window')` and
 * `AttributionTransparency::platformRows()` return exactly one group, always. A panel built to warn
 * about mixed windows could not have warned about one, and the single group it displayed read as a
 * clean bill of health.
 *
 * Meta's own documentation is what makes this concrete rather than theoretical. On the Ad Account
 * Insights reference, `action_attribution_windows` has «القيمة الافتراضية: default» and «يشير الخيار
 * default إلى ["7d_click","1d_view"]» — the default is not «unset», it is a SPECIFIC window: seven
 * days after a click, one day after a view. Snapchat, TikTok, Google, X and LinkedIn each have their
 * own, different, unstated defaults. Storing the same word for all six stated that they agreed.
 *
 * So the connector now names the window in the request rather than inheriting it, and the row it
 * returns says which window it is. The word `default` keeps its meaning for providers whose own
 * audit has not run yet — «the provider's unstated default, whatever that is» — and because that is
 * a DIFFERENT string from Meta's, the mixed-window warning can finally fire.
 *
 * ## And the version those figures came from expired eleven months ago
 *
 * The Marketing API keeps its own version table, separate from the Graph API's. On it, v21.0 was
 * released 2 October 2024 and **expired 9 September 2025**. Everything here was pinned to v21.0.
 *
 * Meta does not answer an expired version with an error — the platform versioning guide states that
 * once a version is no longer usable, calls to it default to the next-oldest usable version. So the
 * figures kept arriving, from a version nobody chose, with nothing in any log to say so.
 */
final class MetaAttributionWindowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Meta's documented `default`, stated explicitly.
     *
     * Written out here rather than read from the connector so the test asserts the DOCUMENTED value
     * and not merely that the code agrees with itself.
     */
    private const WINDOW = '7d_click,1d_view';

    private Tenant $tenant;

    private ?Project $project = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Attr', 'slug' => 'attr-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    // ── The request ───────────────────────────────────────────────────────────────────────────

    /**
     * The window is ASKED FOR, so the figures are of a window we chose.
     *
     * Inheriting a provider default is not the same as knowing one. It is Meta's to change, it
     * differs per surface, and the moment it changes every stored figure changes meaning with no
     * edit on our side and no entry in any changelog we read.
     */
    public function test_meta_names_the_attribution_window_in_the_insights_request(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        $this->bound('meta')->syncInsights('act_1', '2026-08-01', '2026-08-02');

        Http::assertSent(function (Request $request): bool {
            $sent = $request->data();

            $this->assertArrayHasKey(
                'action_attribution_windows',
                $sent,
                'META-ATTRIB-001: the insights request never named a window, so every figure arrived '
                    .'under whichever one Meta happened to apply.',
            );

            $this->assertSame(
                ['7d_click', '1d_view'],
                json_decode((string) $sent['action_attribution_windows'], true, 512, JSON_THROW_ON_ERROR),
                'the window must be Meta\'s documented default, stated rather than inherited',
            );

            return true;
        });
    }

    /**
     * A JSON array, like the `time_range` beside it.
     *
     * Graph reads `list<enum>` parameters as JSON, and Laravel would otherwise serialise a PHP array
     * into `action_attribution_windows[0]=…`, which is not a shape Graph parses — it would be
     * ignored, and being ignored is indistinguishable from never having been sent.
     */
    public function test_the_window_is_sent_as_json_not_as_php_array_brackets(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        $this->bound('meta')->syncInsights('act_1', '2026-08-01', '2026-08-02');

        Http::assertSent(function (Request $request): bool {
            $this->assertStringNotContainsString('action_attribution_windows%5B0%5D', $request->url());
            $this->assertStringContainsString('7d_click', urldecode($request->url()));

            return true;
        });
    }

    // ── The row ───────────────────────────────────────────────────────────────────────────────

    /** Every mapped row says which window it is, so nothing downstream has to assume. */
    public function test_every_meta_insight_row_carries_the_window_it_was_measured_over(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [
                ['campaign_id' => 'c1', 'date_start' => '2026-08-01', 'spend' => '10.5', 'impressions' => '900'],
                ['campaign_id' => 'c2', 'date_start' => '2026-08-01', 'spend' => '4.0', 'impressions' => '80'],
            ],
        ])]);

        $rows = $this->bound('meta')->syncInsights('act_1', '2026-08-01', '2026-08-02')->records;

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(self::WINDOW, $row['attribution_window'] ?? null);
        }
    }

    // ── Storage ───────────────────────────────────────────────────────────────────────────────

    /**
     * The fail-first heart of it: the window reaches `daily_metrics`, instead of being replaced by
     * the constructor default on the way.
     */
    public function test_the_window_a_connector_reports_is_the_window_that_is_stored(): void
    {
        $account = $this->account('meta');
        $this->link($account, 'c1');

        [$metrics] = app(InsightRowNormaliser::class)->normalise($account, [[
            'campaign_id' => 'c1',
            'date' => '2026-08-01',
            'attribution_window' => self::WINDOW,
            'impressions' => 900.0,
        ]], ['impressions']);

        $this->assertCount(1, $metrics);
        $this->assertSame(
            self::WINDOW,
            $metrics[0]->attributionWindow,
            'META-ATTRIB-001: the normaliser dropped the connector\'s window and stored the literal '
                .'«default» for every row of every provider.',
        );
    }

    /**
     * A provider whose own audit has not run yet still says `default` — and that is the honest word
     * for «the provider's unstated default», not a claim that it matches anybody else's.
     */
    public function test_a_row_that_declares_no_window_is_still_recorded_as_default(): void
    {
        $account = $this->account('linkedin');
        $this->link($account, 'c1');

        [$metrics] = app(InsightRowNormaliser::class)->normalise($account, [[
            'campaign_id' => 'c1',
            'date' => '2026-08-01',
            'impressions' => 12.0,
        ]], ['impressions']);

        $this->assertSame('default', $metrics[0]->attributionWindow);
    }

    /**
     * And therefore the mixed-window warning can fire at all.
     *
     * This is the whole point of the defect. While every row said `default`, a grouping on that
     * column returned one bucket no matter how many genuinely different windows were mixed into the
     * same total — a warning that was structurally incapable of warning.
     */
    public function test_two_providers_on_different_windows_are_two_groups_not_one(): void
    {
        $meta = $this->account('meta');
        $this->link($meta, 'c1');
        $linkedin = $this->account('linkedin');
        $this->link($linkedin, 'c2');

        $normaliser = app(InsightRowNormaliser::class);

        [$fromMeta] = $normaliser->normalise($meta, [[
            'campaign_id' => 'c1', 'date' => '2026-08-01',
            'attribution_window' => self::WINDOW, 'impressions' => 900.0,
        ]], ['impressions']);

        [$fromLinkedIn] = $normaliser->normalise($linkedin, [[
            'campaign_id' => 'c2', 'date' => '2026-08-01', 'impressions' => 80.0,
        ]], ['impressions']);

        $windows = array_unique(array_map(
            static fn ($metric): string => $metric->attributionWindow,
            [...$fromMeta, ...$fromLinkedIn],
        ));

        $this->assertCount(
            2,
            $windows,
            'rows measured over different windows must be distinguishable, or the transparency panel '
                .'reports a uniformity it never verified',
        );
    }

    // ── The version ───────────────────────────────────────────────────────────────────────────

    /**
     * META-VERSION-001 — every Meta URL is on a Marketing API version that still exists.
     *
     * Marketing API expirations, from Meta's own versions table: v21.0 → 9 September 2025,
     * v22.0 → 19 February 2026, v23.0 → 9 June 2026, v24.0 → 6 October 2026, v25.0 → TBD. The floor
     * is asserted as a NUMBER rather than a literal string so that a later upgrade passes and only a
     * downgrade — or standing still until v25.0 is itself retired — fails.
     */
    public function test_no_meta_url_is_pinned_to_an_expired_marketing_api_version(): void
    {
        $versions = [];

        foreach (['authorize_url', 'token_url', 'api_base'] as $key) {
            $url = (string) config("ad_platforms.platforms.meta.{$key}");

            $this->assertSame(
                1,
                preg_match('#/v(\d+)\.(\d+)(/|$)#', $url, $m),
                "meta.{$key} must pin an explicit Graph version; an unversioned call uses whatever "
                    .'version the app dashboard happens to be set to',
            );

            $versions[$key] = (int) $m[1];
        }

        foreach ($versions as $key => $major) {
            $this->assertGreaterThanOrEqual(
                25,
                $major,
                "meta.{$key} is on v{$major}, and every Marketing API version below v25.0 has passed "
                    .'its expiration date. Meta answers an expired version by silently falling back to '
                    .'the next-oldest usable one, so this never surfaces as an error.',
            );
        }

        $this->assertCount(1, array_unique($versions), 'the three Meta URLs must agree on one version');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function bound(string $platform): ApiAdvertisingConnector
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $platform,
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: $platform,
        );

        return app(AdvertisingConnectorRegistry::class)->get($platform)->withConnection($connection);
    }

    private function account(string $provider): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        return ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => 'act_'.uniqid(), 'name' => 'Acct', 'status' => 'active',
        ]);
    }

    private function link(ExternalAccount $account, string $externalId): void
    {
        if ($this->project === null) {
            $workspace = ClientWorkspace::create([
                'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
                'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            ]);
            $this->project = Project::create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
                'name' => 'P', 'status' => 'active',
            ]);
        }

        // `unified_campaigns` is unique on (project_id, name), and this helper is called twice inside
        // one project when two providers are compared.
        $name = "Sales {$account->provider} {$externalId}";

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'objective' => 'sales', 'status' => 'active',
        ]);

        ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'unified_campaign_id' => $campaign->id, 'external_account_id' => $account->id,
            'provider' => $account->provider, 'external_id' => $externalId,
            'name' => $name, 'status' => 'active',
        ]);
    }
}
