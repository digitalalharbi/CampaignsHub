<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\TikTokConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TIKTOK-CURRENCY-001 — a TikTok account's money is in the account's own currency.
 *
 * Production, 2026-10-12: after the 90-day backfill the TikTok account read
 * `currency=NOT CAPTURED`, and its spend sat in the project as USD — 36,000 a month that the
 * advertiser's own console states as roughly 33,783 SAR. `oauth2/advertiser/get/` answers with an id
 * and a name only, the report carries no currency, and the normaliser falls back to the project's
 * reporting currency, so a SAR number was stored under a USD label. The figures were right and the
 * unit was wrong, which is the more dangerous of the two because nothing looks broken.
 *
 * `advertiser/info/` states the currency and the timezone. It is asked only when discovery did not
 * already say, and once per account — an account discovered before this fix learns its currency on
 * its next sync and keeps it.
 */
final class TikTokAccountCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        foreach (PlatformCredentials::for('tiktok')->requires() as $key) {
            config()->set("ad_platforms.platforms.tiktok.{$key}", "test-{$key}");
        }
    }

    public function test_discovery_asks_the_account_its_currency_when_the_listing_does_not_say(): void
    {
        Http::fake([
            'business-api.tiktok.com/open_api/*/oauth2/advertiser/get*' => Http::response([
                'code' => 0,
                'data' => ['list' => [['advertiser_id' => '7194075886352662529', 'advertiser_name' => 'Store']]],
            ]),
            'business-api.tiktok.com/open_api/*/advertiser/info*' => Http::response([
                'code' => 0,
                'data' => ['list' => [[
                    'advertiser_id' => '7194075886352662529',
                    'currency' => 'SAR',
                    'timezone' => 'Etc/GMT-3',
                    'display_timezone' => 'Asia/Riyadh',
                ]]],
            ]),
        ]);

        $accounts = (new TikTokConnector)->discoverAdAccounts(new OAuthTokens('AT'));

        $this->assertCount(1, $accounts);
        $this->assertSame('SAR', $accounts[0]['currency']);
        $this->assertSame('Asia/Riyadh', $accounts[0]['timezone'], 'the IANA name, not the offset label');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'advertiser/info')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return json_decode((string) $query['advertiser_ids'], true) === ['7194075886352662529'];
        });
    }

    public function test_discovery_asks_nothing_more_when_the_listing_already_states_the_currency(): void
    {
        Http::fake([
            'business-api.tiktok.com/open_api/*/oauth2/advertiser/get*' => Http::response([
                'code' => 0,
                'data' => ['list' => [['advertiser_id' => '777', 'advertiser_name' => 'A', 'currency' => 'SAR']]],
            ]),
        ]);

        $accounts = (new TikTokConnector)->discoverAdAccounts(new OAuthTokens('AT'));

        $this->assertSame('SAR', $accounts[0]['currency']);
        Http::assertSentCount(1);
    }

    public function test_a_refused_settings_read_does_not_fail_discovery(): void
    {
        Http::fake([
            'business-api.tiktok.com/open_api/*/oauth2/advertiser/get*' => Http::response([
                'code' => 0,
                'data' => ['list' => [['advertiser_id' => '777', 'advertiser_name' => 'A']]],
            ]),
            'business-api.tiktok.com/open_api/*/advertiser/info*' => Http::response([
                'code' => 40001, 'message' => 'No permission', 'request_id' => 'r',
            ]),
        ]);

        $accounts = (new TikTokConnector)->discoverAdAccounts(new OAuthTokens('AT'));

        $this->assertCount(1, $accounts, 'an account with an unknown currency is still an account');
        $this->assertNull($accounts[0]['currency']);
    }

    /**
     * **The defect, pinned.** An account discovered before the fix learns its currency on the next
     * sync, every figure is stamped with it, and the account keeps it for every sync after.
     */
    public function test_a_sync_stamps_the_accounts_currency_on_every_row_and_records_it_once(): void
    {
        $this->fakeReport();
        [$connector, $account] = $this->bound(null);

        $rows = $connector->syncInsights('adv-9', '2026-10-01', '2026-10-02')->records;

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('SAR', $row['currency'], 'a SAR figure was left to be read as the project currency');
        }
        $this->assertSame('SAR', $account->fresh()->currency, 'the currency was not recorded on the account');
        $this->assertSame('Asia/Riyadh', $account->fresh()->timezone);

        // A second sync reads the recorded currency and does not ask again.
        $connector->syncInsights('adv-9', '2026-10-01', '2026-10-02');
        $asked = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'advertiser/info'));
        $this->assertCount(1, $asked);
    }

    public function test_a_known_currency_is_stamped_without_asking(): void
    {
        $this->fakeReport();
        [$connector] = $this->bound('SAR');

        $rows = $connector->syncInsights('adv-9', '2026-10-01', '2026-10-02')->records;

        $this->assertSame('SAR', $rows[0]['currency']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'advertiser/info'));
    }

    private function fakeReport(): void
    {
        Http::fake([
            'business-api.tiktok.com/open_api/*/advertiser/info*' => Http::response([
                'code' => 0,
                'data' => ['list' => [[
                    'advertiser_id' => 'adv-9', 'currency' => 'SAR', 'display_timezone' => 'Asia/Riyadh',
                ]]],
            ]),
            'business-api.tiktok.com/open_api/*/report/integrated/get*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [[
                        'dimensions' => ['campaign_id' => 'c1', 'stat_time_day' => '2026-10-01 00:00:00'],
                        'metrics' => ['spend' => '1200.00', 'impressions' => '50000', 'clicks' => '300'],
                    ]],
                    'page_info' => ['page' => 1, 'total_page' => 1],
                ],
            ]),
        ]);
    }

    /** @return array{ApiAdvertisingConnector, ExternalAccount} */
    private function bound(?string $currency): array
    {
        /** @var ProviderConnection $connection */
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'tiktok',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()),
            connectionName: 'tiktok',
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'tiktok',
            'external_id' => 'adv-9',
            'account_type' => 'ad_account',
            'name' => 'Store',
            'status' => 'active',
            'currency' => $currency,
        ]);

        return [app(AdvertisingConnectorRegistry::class)->get('tiktok')->withConnection($connection), $account];
    }
}
