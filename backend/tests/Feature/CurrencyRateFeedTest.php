<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Contracts\CurrencyRateSource;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Rates\CurrencyRateFeed;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * FX-FEED-001 — the ENGINE is verified; the FEED is a separate question with a separate answer.
 *
 * FX-001 and COMMERCE-FX-001 convert money at ingest, at a dated rate, from a named source, and
 * withhold the figure when no rate can be vouched for. All of that works. What no install in this
 * repository has is a SUPPLY of rates: `currency_rates` is written by nothing automatic, because
 * which publisher a deployment trusts is a commercial decision and a default baked in here would make
 * it silently.
 *
 * These tests hold that line: an unconfigured feed reports itself as unconfigured, writes nothing,
 * and never invents a rate — while the hand-entry path stays fully usable, because an operator is a
 * legitimate and attributable source.
 */
final class CurrencyRateFeedTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $tenant = null;

    private ?ExternalAccount $store = null;

    private ?Project $project = null;

    // ── The state of the supply ───────────────────────────────────────────────────────────────

    /** With no driver chosen, the state is «awaiting configuration» — not «broken», and not «ready». */
    public function test_a_deployment_with_no_source_says_so_rather_than_failing(): void
    {
        config()->set('fx.rates.driver', null);

        $status = app(CurrencyRateFeed::class)->status();

        $this->assertSame('awaiting_configuration', $status['state']);
        $this->assertNull($status['driver']);
        $this->assertSame(0, $status['rates']);
    }

    /** A driver that IS chosen but cannot be used is a third state, because it needs a third action. */
    public function test_a_configured_but_unusable_source_is_distinguished_from_an_absent_one(): void
    {
        $this->bindSource(new UnusableRateSource);

        $this->assertSame('driver_not_configured', app(CurrencyRateFeed::class)->status()['state']);
    }

    /**
     * The command writes NOTHING when no source is configured, and reports what that costs.
     *
     * The temptation this rules out is a default publisher or a re-dated last-known rate. Either
     * would silently undo every fail-closed decision upstream — the withheld figures would come back
     * as numbers nobody chose the provenance of.
     */
    public function test_the_import_command_invents_nothing_when_no_source_is_configured(): void
    {
        config()->set('fx.rates.driver', null);
        $this->withheldAdSpend('USD', 'SAR');

        $this->artisan('fx:rates')
            ->expectsOutputToContain('No exchange-rate source is configured')
            ->expectsOutputToContain('USD→SAR')
            ->assertSuccessful();

        $this->assertSame(0, CurrencyRate::query()->count(), 'nothing may be written when nothing is configured');
    }

    // ── What the feed is asked for ────────────────────────────────────────────────────────────

    /**
     * The pairs come from the figures already withheld — from BOTH pipelines.
     *
     * A configured currency list would go stale the first time a client connected a shop in a
     * currency nobody had listed, and those are exactly the figures nobody notices are missing.
     */
    public function test_the_pairs_needed_are_read_from_the_figures_already_withheld(): void
    {
        $this->withheldAdSpend('USD', 'SAR', 3);
        $this->withheldOrder('KWD', 'SAR');

        $pairs = app(CurrencyRateFeed::class)->unmetPairs();

        $this->assertCount(2, $pairs);
        // Worst first: the pair costing the most figures is the one an operator should act on.
        $this->assertSame('USD', $pairs[0]['base']);
        $this->assertSame(3, $pairs[0]['withheld']);
        $this->assertSame(['advertising'], $pairs[0]['sources']);
        $this->assertSame('KWD', $pairs[1]['base']);
        $this->assertSame(['commerce'], $pairs[1]['sources']);
    }

    /** A configured source is asked only for those pairs, and what it answers is recorded. */
    public function test_a_configured_source_is_asked_for_the_missing_pairs_and_its_answer_is_stored(): void
    {
        $this->withheldAdSpend('USD', 'SAR');
        $this->bindSource(new StubRateSource(['USD|SAR' => 3.75]));

        $result = app(CurrencyRateFeed::class)->import(Carbon::parse('2026-06-01'));

        $this->assertSame('ready', $result['state']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['missing']);

        $rate = CurrencyRate::query()->firstOrFail();
        $this->assertSame('USD', $rate->base_currency);
        $this->assertEqualsWithDelta(3.75, (float) $rate->rate, 0.0001);
        // Traceable to the publisher rather than to «the system».
        $this->assertSame('stub-feed', $rate->source);
    }

    /**
     * A pair the source cannot answer stays missing, and the run SAYS it stayed missing.
     *
     * A partial import that reported itself as a success would look identical to a complete one, and
     * the figures it failed to unblock would stay withheld with nobody told why.
     */
    public function test_a_pair_the_source_cannot_answer_is_reported_rather_than_filled(): void
    {
        $this->withheldAdSpend('USD', 'SAR');
        $this->withheldOrder('KWD', 'SAR');
        $this->bindSource(new StubRateSource(['USD|SAR' => 3.75]));

        $result = app(CurrencyRateFeed::class)->import(Carbon::parse('2026-06-01'));

        $this->assertSame(1, $result['imported']);
        $this->assertSame(['KWD→SAR'], $result['missing']);
        $this->assertSame(1, CurrencyRate::query()->count());
    }

    /** A source answering with a nonsensical rate is ignored, not stored. */
    public function test_a_zero_rate_is_refused(): void
    {
        $this->withheldAdSpend('USD', 'SAR');
        $this->bindSource(new StubRateSource(['USD|SAR' => 0.0]));

        $result = app(CurrencyRateFeed::class)->import(Carbon::parse('2026-06-01'));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, CurrencyRate::query()->count(), 'a zero rate would convert a real amount into nothing');
    }

    /** A source that throws fails the run rather than being mistaken for «no rates today». */
    public function test_a_source_that_throws_is_reported_as_a_failure(): void
    {
        $this->withheldAdSpend('USD', 'SAR');
        $this->bindSource(new ThrowingRateSource);

        $this->artisan('fx:rates')->assertFailed();
    }

    /** Restating a day's rate replaces it — two rows for one pair and date would be a coin toss. */
    public function test_a_restated_rate_replaces_the_earlier_one_for_that_day(): void
    {
        $feed = app(CurrencyRateFeed::class);

        $feed->record('USD', 'SAR', 3.70, Carbon::parse('2026-06-01'), 'stub-feed');
        $feed->record('USD', 'SAR', 3.75, Carbon::parse('2026-06-01'), 'stub-feed');

        $this->assertSame(1, CurrencyRate::query()->count());
        $this->assertEqualsWithDelta(3.75, (float) CurrencyRate::query()->firstOrFail()->rate, 0.0001);
    }

    // ── The hand-entry path ───────────────────────────────────────────────────────────────────

    /**
     * An operator can always record a rate, and the rate remembers WHO.
     *
     * A treasury desk publishes rates long before anybody buys an API, so this is a first-class path
     * rather than a fallback. What makes it acceptable is attribution: a conversion made at this rate
     * can be traced to a person, which is why `rate_source` exists at all.
     */
    public function test_an_operator_can_record_a_rate_and_it_carries_their_name(): void
    {
        $this->actingAs($this->platformOwner())
            ->postJson('/api/v1/admin/fx/rates', [
                'base' => 'usd', 'quote' => 'sar', 'rate' => 3.75, 'rate_date' => '2026-06-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.base', 'USD')
            ->assertJsonPath('data.source', 'manual:owner@campaignshub.io');

        $this->assertSame(1, CurrencyRate::query()->count());
    }

    /** A rate for a day that has not happened would become the answer for every future conversion. */
    public function test_a_future_dated_rate_is_refused(): void
    {
        $this->actingAs($this->platformOwner())
            ->postJson('/api/v1/admin/fx/rates', [
                'base' => 'USD', 'quote' => 'SAR', 'rate' => 3.75,
                'rate_date' => Carbon::tomorrow()->toDateString(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, CurrencyRate::query()->count());
    }

    /** Zero and negative rates are refused at the edge as well as in the importer. */
    public function test_a_non_positive_rate_is_refused(): void
    {
        $owner = $this->platformOwner();

        foreach ([0, -1.5] as $rate) {
            $this->actingAs($owner)
                ->postJson('/api/v1/admin/fx/rates', [
                    'base' => 'USD', 'quote' => 'SAR', 'rate' => $rate, 'rate_date' => '2026-06-01',
                ])
                ->assertStatus(422);
        }

        $this->assertSame(0, CurrencyRate::query()->count());
    }

    /** The console shows the state, what it is costing, and the rates on file — in one answer. */
    public function test_the_console_reports_the_feed_state_beside_what_it_is_costing(): void
    {
        config()->set('fx.rates.driver', null);
        $this->withheldAdSpend('USD', 'SAR', 2);

        $body = $this->actingAs($this->platformOwner())
            ->getJson('/api/v1/admin/fx/rates')
            ->assertOk()
            ->json('data');

        $this->assertSame('awaiting_configuration', $body['feed']['state']);
        $this->assertSame('USD', $body['unmet_pairs'][0]['base']);
        $this->assertSame(2, $body['unmet_pairs'][0]['withheld']);
    }

    /** A rate is not one tenant's opinion: an ordinary user cannot reach this console at all. */
    public function test_a_tenant_user_cannot_read_or_write_platform_rates(): void
    {
        $user = User::create(['name' => 'T', 'email' => 't@fx.test', 'password' => 'secret123']);

        $this->actingAs($user)->getJson('/api/v1/admin/fx/rates')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/v1/admin/fx/rates', [
            'base' => 'USD', 'quote' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01',
        ])->assertStatus(403);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function bindSource(CurrencyRateSource $source): void
    {
        config()->set('fx.rates.driver', $source::class);
        $this->app->instance(CurrencyRateFeed::class, new CurrencyRateFeed($source));
    }

    private function platformOwner(): User
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'owner@campaignshub.io', 'password' => 'secret123',
        ]);

        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    /** One real tenant, because both tables' rows are tenant-scoped by a foreign key. */
    private function tenant(): Tenant
    {
        return $this->tenant ??= Tenant::create(['name' => 'FX', 'slug' => 'fx-feed', 'status' => 'active']);
    }

    /** A real project, for the same reason — both tables key their rows to one. */
    private function project(): Project
    {
        if ($this->project !== null) {
            return $this->project;
        }

        // A workspace takes its tenant from the context, the way every other creator of one does.
        app(TenantContext::class)->setTenantId((string) $this->tenant()->id);

        $workspace = ClientWorkspace::create([
            'name' => 'C', 'slug' => 'fx-feed-client', 'mode' => 'managed', 'default_currency' => 'SAR',
        ]);

        return $this->project = Project::create([
            'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    /** A store account for the commerce half, behind a real connection like every other one. */
    private function storeAccount(): ExternalAccount
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $credential = new IntegrationCredential([
            'provider' => 'salla', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'salla',
            'connection_name' => 'fx-feed', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $store = new ExternalAccount;
        $store->forceFill([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => $this->tenant()->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'salla',
            'account_type' => 'store',
            'external_id' => 'fx-shop',
            'name' => 'FX shop',
            'status' => 'active',
        ])->save();

        return $this->store = $store;
    }

    /** A withheld ad figure: a real original amount, no converted value, no rate. */
    private function withheldAdSpend(string $from, string $to, int $rows = 1): void
    {
        for ($i = 0; $i < $rows; $i++) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Uuid::uuid4(),
                'tenant_id' => $this->tenant()->id,
                'project_id' => $this->project()->id,
                'external_account_id' => (string) Uuid::uuid4(),
                'external_campaign_id' => (string) Uuid::uuid4(),
                'provider' => 'meta',
                'metric_key' => 'spend',
                'metric_date' => '2026-06-0'.($i + 1),
                'value' => null,
                'original_amount' => 100,
                'original_currency' => $from,
                'project_currency' => $to,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** A withheld store figure, in the other pipeline. */
    private function withheldOrder(string $from, string $to): void
    {
        DB::table('commerce_orders')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => $this->tenant()->id,
            'project_id' => $this->project()->id,
            'external_account_id' => $this->storeAccount()->getKey(),
            'provider' => 'salla',
            'external_id' => 'o-'.Uuid::uuid4(),
            'status' => 'completed',
            'placed_at' => '2026-06-10 12:00:00',
            'currency' => $to,
            'original_currency' => $from,
            'total' => null,
            'original_total' => 300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/** A source that answers the pairs it was given a rate for, and omits the rest. */
final class StubRateSource implements CurrencyRateSource
{
    /** @param array<string,float> $rates keyed «BASE|QUOTE» */
    public function __construct(private readonly array $rates = []) {}

    public function key(): string
    {
        return 'stub-feed';
    }

    public function label(): string
    {
        return 'Stub feed';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fetch(array $pairs, Carbon $date): array
    {
        $out = [];

        foreach ($pairs as $pair) {
            $key = $pair['base'].'|'.$pair['quote'];

            if (array_key_exists($key, $this->rates)) {
                $out[] = [
                    'base' => $pair['base'], 'quote' => $pair['quote'],
                    'rate' => $this->rates[$key], 'rate_date' => $date->toDateString(),
                ];
            }
        }

        return $out;
    }
}

/** Chosen as the driver, missing whatever it needs to be called. */
final class UnusableRateSource implements CurrencyRateSource
{
    public function key(): string
    {
        return 'unusable';
    }

    public function label(): string
    {
        return 'Unusable';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function fetch(array $pairs, Carbon $date): array
    {
        return [];
    }
}

/** Reachable, and answering with an error. */
final class ThrowingRateSource implements CurrencyRateSource
{
    public function key(): string
    {
        return 'throwing';
    }

    public function label(): string
    {
        return 'Throwing';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fetch(array $pairs, Carbon $date): array
    {
        throw new \RuntimeException('the rate service is down');
    }
}
