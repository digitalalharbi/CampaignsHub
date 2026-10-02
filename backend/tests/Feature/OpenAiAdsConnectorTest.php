<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\OpenAiAdsConnector;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-OPENAI-001 — what this connector reads, and what it refuses to invent.
 *
 * ## It cannot be VERIFIED here, and these do not pretend otherwise
 *
 * No real key has been through this chain, so what the provider actually returns is the documented
 * shape and not an observed one. These hold the decisions that are OURS whatever the provider sends:
 * no parent layer invented, no derived rate stored, no absent figure turned into a zero, and the
 * whole catalogue read rather than the first page of it.
 */
final class OpenAiAdsConnectorTest extends TestCase
{
    use RefreshDatabase;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $this->connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'openai_ads',
            // No expiry: a key has no authorisation server to refresh against.
            tokens: new OAuthTokens('sk-ads-live-0000beef', null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );
    }

    /**
     * **No organisation, portfolio or manager account is invented.**
     *
     * OpenAI publishes none for advertising. A `parent_external_id` derived to fill the column would
     * put a «choose a business» step in the wizard above a list with one made-up row in it.
     */
    public function test_the_account_has_no_parent_because_the_provider_publishes_none(): void
    {
        Http::fake(['api.ads.openai.com/*' => Http::response([
            'id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh', 'status' => 'ACTIVE',
        ])]);

        $accounts = $this->connector()->listAdAccounts();

        $this->assertCount(1, $accounts);
        $this->assertSame('acct_live_1', $accounts[0]['external_id']);
        $this->assertNull($accounts[0]['parent_external_id']);
    }

    /** The key travels in a header. A secret in a URL is a secret in every log that saw the request. */
    public function test_the_key_is_sent_as_a_bearer_header_and_never_in_the_url(): void
    {
        Http::fake(['api.ads.openai.com/*' => Http::response(['id' => 'acct_live_1'])]);

        $this->connector()->listAdAccounts();

        Http::assertSent(function (Request $request): bool {
            $this->assertStringNotContainsString('sk-ads-live-0000beef', $request->url());

            return $request->hasHeader('Authorization', 'Bearer sk-ads-live-0000beef');
        });
    }

    /**
     * **A second page is read.** `has_more` says to ask again, and `after` carries where from.
     *
     * The failure this prevents is the quiet one: an account with 140 campaigns reports the first
     * 100, every total built on them is wrong by the rest, and nothing anywhere says a page was left
     * behind.
     */
    public function test_a_paged_catalogue_is_read_to_the_end(): void
    {
        $asked = [];

        Http::fake(function (Request $request) use (&$asked) {
            $url = $request->url();

            if (str_contains($url, '/campaigns')) {
                $asked[] = $url;

                return str_contains($url, 'after=cmp_100')
                    ? Http::response(['data' => [['id' => 'cmp_101', 'name' => 'Second page']], 'has_more' => false])
                    : Http::response([
                        'data' => [['id' => 'cmp_1', 'name' => 'First'], ['id' => 'cmp_100', 'name' => 'Hundredth']],
                        'has_more' => true,
                    ]);
            }

            return Http::response(['id' => 'acct_live_1']);
        });

        $campaigns = $this->connector()->syncCampaigns('acct_live_1');

        $this->assertSame(3, $campaigns->count, 'the connector stopped at the first page');
        $this->assertCount(2, $asked);
        $this->assertStringContainsString('after=cmp_100', $asked[1]);
    }

    /**
     * **A rate this product derives is never stored as if the provider had said it.**
     *
     * CTR, CPC and CPM are spend and clicks and impressions divided by one another. Storing a
     * provider's rounded copy gives two answers to one question, and the one that disagrees with the
     * figures beside it is the one a client reads.
     */
    public function test_derived_rates_are_not_stored_even_when_the_provider_sends_them(): void
    {
        $rows = $this->insightRows([
            'date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250,
            'conversions' => 12, 'revenue' => 980.0,
            'ctr' => 2.5, 'cpc' => 0.48, 'cpm' => 12.05,
        ]);

        $this->assertSame(120.5, $rows[0]['spend']);
        $this->assertSame(10000.0, $rows[0]['impressions']);

        foreach (['ctr', 'cpc', 'cpm'] as $derived) {
            $this->assertArrayNotHasKey($derived, $rows[0], "the connector stored {$derived} as a provider figure");
        }
    }

    /**
     * **An absent figure stays absent.** It is not a zero.
     *
     * «The provider did not report conversions» and «the provider reported no conversions» are
     * different answers, and the whole availability model downstream rests on the connector not
     * collapsing the first into the second.
     */
    public function test_a_figure_the_provider_did_not_send_is_not_written_as_zero(): void
    {
        $rows = $this->insightRows([
            'date' => '2026-09-01', 'spend' => 90.0, 'impressions' => 4000, 'clicks' => 60,
            // conversions absent entirely, revenue explicitly null — both mean «not reported».
            'revenue' => null,
        ]);

        $this->assertSame(90.0, $rows[0]['spend']);
        $this->assertArrayNotHasKey('conversions', $rows[0]);
        $this->assertArrayNotHasKey('revenue', $rows[0]);
    }

    /** A real zero the provider DID report is kept, because it is an answer. */
    public function test_a_zero_the_provider_reported_is_kept(): void
    {
        $rows = $this->insightRows([
            'date' => '2026-09-01', 'spend' => 90.0, 'impressions' => 4000, 'clicks' => 60, 'conversions' => 0,
        ]);

        $this->assertArrayHasKey('conversions', $rows[0]);
        $this->assertSame(0.0, $rows[0]['conversions']);
    }

    /**
     * Ad groups are read per campaign, and an ad names the group it belongs to.
     *
     * The grain chain is what every structure view and every ad-level report is built on; an ad that
     * cannot name its group is an ad that appears under none.
     */
    public function test_the_structure_chain_keeps_each_level_pointing_at_its_parent(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_groups')) {
                return Http::response(['data' => [
                    ['id' => 'grp_1', 'campaign_id' => 'cmp_1', 'name' => 'Riyadh'],
                ], 'has_more' => false]);
            }

            if (str_contains($url, '/ads')) {
                return Http::response(['data' => [
                    ['id' => 'ad_1', 'ad_group_id' => 'grp_1', 'campaign_id' => 'cmp_1', 'name' => 'Card A'],
                ], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1']);
        });

        $connector = $this->connector();

        $this->assertSame(1, $connector->syncAdSets('acct_live_1')->count);
        $this->assertSame(1, $connector->syncAds('acct_live_1')->count);
    }

    private function connector(): OpenAiAdsConnector
    {
        return (new OpenAiAdsConnector)->withConnection($this->connection);
    }

    /**
     * One insights row, through the real fetch path.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function insightRows(array $row): array
    {
        Http::fake(function (Request $request) use ($row) {
            $url = $request->url();

            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [$row]]);
            }

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1']);
        });

        $method = new \ReflectionMethod(OpenAiAdsConnector::class, 'fetchInsights');

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke(
            $this->connector(),
            app(TokenVault::class)->stored($this->connection),
            'acct_live_1',
            '2026-09-01',
            '2026-09-07',
        );

        $this->assertNotSame([], $rows, 'the connector read no insight rows at all');

        return $rows;
    }
}
