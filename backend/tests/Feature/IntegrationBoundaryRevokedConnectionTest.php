<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 (cases 6 and 8) — two authorisations of ONE provider in ONE tenant behave independently.
 *
 * C1 (client W1) carries act_1 -> P1 and act_3 -> P3; C2 (client W2) carries act_2 -> P2. When C1 is revoked, removed or its token
 * can no longer be refreshed, ONLY C1's jobs stop: act_2 keeps syncing with C2's own token, and the surface says «reconnect» for C1
 * (REAUTH_REQUIRED / REVOKED), never CONNECTED. Neighbours credited and not repeated: `SyncScopeReverificationTest` (one revoked
 * connection does no work), `ConnectionHubTest::test_two_authorisations_for_one_provider_are_two_rows`, `META-SESSION-190-001`.
 * What none of them did is run the SWEEP, the JOBS, the provider traffic and the hub together for the pair.
 */
final class IntegrationBoundaryRevokedConnectionTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private User $operator;

    private ProviderConnection $c1;

    private ProviderConnection $c2;

    /** @var array<string, ExternalAccount> */
    private array $account = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bwConfigure('meta');
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'UTC'));

        $this->tenant = $this->bwTenant();
        $this->operator = $this->bwOperator($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Builds the pair; `$c1Expired` gives C1 a token that is already past its expiry. */
    private function world(bool $c1Expired = false): void
    {
        $w1 = $this->bwWorkspace($this->tenant, 'W1');
        $w2 = $this->bwWorkspace($this->tenant, 'W2');
        $p1 = $this->bwProject($this->tenant, $w1, 'P1');
        $p3 = $this->bwProject($this->tenant, $w1, 'P3');
        $p2 = $this->bwProject($this->tenant, $w2, 'P2');

        $this->c1 = $this->bwConnection($this->tenant, 'meta', $w1, 'AT-ONE', $c1Expired ? Carbon::now()->subHour() : null);
        $this->c2 = $this->bwConnection($this->tenant, 'meta', $w2, 'AT-TWO');
        $this->assertNotSame($this->c1->id, $this->c2->id, 'two workspaces must give two connections of one provider');

        foreach (['act_1' => [$this->c1, $p1], 'act_3' => [$this->c1, $p3], 'act_2' => [$this->c2, $p2]] as $id => [$connection, $project]) {
            $this->account[$id] = $this->bwAccount($connection, $id, 'ad_account', ['client_workspace_id' => $project->client_workspace_id]);
            $this->bwBind($this->account[$id], $project);
            ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $this->account[$id]->id,
                'provider' => 'meta', 'external_id' => 'cmp-'.$id, 'name' => 'Campaign '.$id, 'status' => 'active',
            ]);
        }
    }

    /** The provider answers by the token it is shown; every request is recorded with the token that made it. */
    private function fakeMeta(bool $refreshFails = false): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($refreshFails) {
            $url = urldecode($request->url());

            if (str_contains($url, 'oauth/access_token')) {
                return $refreshFails
                    ? Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190, 'type' => 'OAuthException']], 400)
                    : Http::response(['access_token' => 'AT-REFRESHED', 'expires_in' => 5000000], 200);
            }

            if (! str_contains($url, '/insights') || ! preg_match('#/(act_\d)/#', $url, $m)) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['data' => [[
                'campaign_id' => 'cmp-'.$m[1], 'date_start' => '2026-08-01', 'spend' => '100', 'impressions' => '1000', 'clicks' => '10',
            ]]], 200);
        });
    }

    /** @return list<string> the bearer token of every recorded request that is NOT a token exchange */
    private function tokensSeen(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->reject(fn (Request $r): bool => str_contains($r->url(), 'oauth/access_token'))
            ->map(fn (Request $r): string => (string) ($r->header('Authorization')[0] ?? ($r->data()['access_token'] ?? 'query:'.parse_url($r->url(), PHP_URL_QUERY))))
            ->values()
            ->all();
    }

    private function hubRow(ProviderConnection $connection, bool $mustBeListed = true, bool $includeRemoved = false): ?array
    {
        $rows = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/integrations/hub'.($includeRemoved ? '?include_removed=1' : ''))->assertOk()->json('data');
        $rows = $rows['connections'] ?? $rows;
        $row = collect($rows)->firstWhere('id', (string) $connection->id);
        if ($mustBeListed) {
            $this->assertNotNull($row, 'the connection is missing from the hub');
        }

        return $row;
    }

    // ── Case 6: revoked / removed ─────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function withdrawn(): array
    {
        // A revoked connection stays on the hub saying so; a removed one leaves it (the hub lists no disconnected authorisation).
        return ['revoked' => ['revoked', IntegrationTruth::REVOKED], 'removed (disconnected)' => ['disconnected', null]];
    }

    #[DataProvider('withdrawn')]
    public function test_a_revoked_or_removed_connection_stops_only_its_own_accounts(string $status, ?string $truth): void
    {
        $this->world();
        $this->c1->forceFill(['status' => $status])->save();
        $this->fakeMeta();

        // Jobs already in the queue: the withdrawn connection's reach no provider; the live one's runs and writes.
        foreach (['act_1', 'act_3', 'act_2'] as $id) {
            SyncAccountMetricsJob::dispatchSync((string) $this->account[$id]->id, '2026-08-01', '2026-08-01');
        }

        $this->assertSame(['Bearer AT-TWO'], array_values(array_unique($this->tokensSeen())), 'a request was made with the withdrawn connection\'s token');
        $this->assertSame([(string) $this->account['act_2']->id], DB::table('daily_metrics')->pluck('external_account_id')->map(fn ($v) => (string) $v)->unique()->values()->all(), 'only the live connection\'s account stored figures');
        $this->assertSame(0, MetricSyncRun::withoutGlobalScopes()->whereIn('external_account_id', [$this->account['act_1']->id, $this->account['act_3']->id])->where('status', 'success')->count());

        // The sweep queues the live connection's account and none of the withdrawn one's.
        Queue::fake();
        $this->artisan('integrations:sync')->assertSuccessful();
        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
        Queue::assertPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $j) => str_contains($j->uniqueId(), (string) $this->account['act_2']->id));
        Queue::assertNotPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $j) => str_contains($j->uniqueId(), (string) $this->account['act_1']->id)
            || str_contains($j->uniqueId(), (string) $this->account['act_3']->id));

        // The surface: the withdrawn one says so, the live one still reads connected.
        if ($truth === null) {
            $this->assertNull($this->hubRow($this->c1, false), 'a removed connection is still listed as an authorisation');
        } else {
            // INTEG-SOURCE-REMOVAL-001 — a revoked source leaves the default list; under «show removed» it still says so.
            $this->assertNull($this->hubRow($this->c1, false), 'a revoked connection is still in the default hub list');
            $this->assertSame($truth, $this->hubRow($this->c1, includeRemoved: true)['connection_state']);
            $this->assertNull($this->hubRow($this->c1, includeRemoved: true)['next_sync_at'], 'a withdrawn connection promises a next sync');
        }
        $this->assertSame(IntegrationTruth::CONNECTED, $this->hubRow($this->c2)['connection_state']);
    }

    // ── Case 8: token refresh failure ─────────────────────────────────────────────────────────

    public function test_a_failed_token_refresh_stops_only_that_connection_and_the_surface_says_reconnect(): void
    {
        $this->world(c1Expired: true);
        $this->fakeMeta(refreshFails: true);

        $this->assertSame('connected', $this->c1->refresh()->status);

        // The job meets the expired token, tries to refresh, and is refused.
        SyncAccountMetricsJob::dispatchSync((string) $this->account['act_1']->id, '2026-08-01', '2026-08-01');

        $run = MetricSyncRun::withoutGlobalScopes()->where('external_account_id', $this->account['act_1']->id)->latest('started_at')->firstOrFail();
        $this->assertSame('failed', $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status);
        $this->assertStringContainsStringIgnoringCase('refresh', (string) $run->error);
        $this->assertNotSame('connected', $this->c1->refresh()->status, 'a connection whose token cannot be refreshed still reads connected');
        $this->assertSame(0, DB::table('daily_metrics')->where('external_account_id', $this->account['act_1']->id)->count());

        $exchanges = collect(Http::recorded())->filter(fn (array $p): bool => str_contains($p[0]->url(), 'oauth/access_token'))->count();
        $this->assertSame(1, $exchanges);

        // From now on: no further attempt for ANY account behind C1 (the refresh is not hammered), the sweep skips C1 …
        SyncAccountMetricsJob::dispatchSync((string) $this->account['act_1']->id, '2026-08-01', '2026-08-01');
        SyncAccountMetricsJob::dispatchSync((string) $this->account['act_3']->id, '2026-08-01', '2026-08-01');
        $this->assertSame($exchanges, collect(Http::recorded())->filter(fn (array $p): bool => str_contains($p[0]->url(), 'oauth/access_token'))->count(), 'the failed refresh was retried by a later job');
        // … and the OTHER connection and its project keep syncing with their own token.
        SyncAccountMetricsJob::dispatchSync((string) $this->account['act_2']->id, '2026-08-01', '2026-08-01');
        Queue::fake();
        $this->artisan('integrations:sync')->assertSuccessful();
        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
        Queue::assertPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $j) => str_contains($j->uniqueId(), (string) $this->account['act_2']->id));
        $this->assertGreaterThan(0, DB::table('daily_metrics')->where('external_account_id', $this->account['act_2']->id)->where('metric_key', 'spend')->count(), 'the healthy connection stopped syncing');
        $this->assertNotContains('Bearer AT-ONE', $this->tokensSeen(), 'the expired token was used to read');
        $this->assertSame(['Bearer AT-TWO'], array_values(array_unique($this->tokensSeen())));

        // The surface says reconnect for C1 — not CONNECTED, no promised next sync — and still says connected for C2.
        $failed = $this->hubRow($this->c1);
        $this->assertSame(IntegrationTruth::REAUTH_REQUIRED, $failed['connection_state'], 'the hub does not say reconnect');
        $this->assertNull($failed['next_sync_at']);
        $this->assertTrue($failed['needs_attention'], 'the failed connection is not flagged');
        $this->assertSame(IntegrationTruth::CONNECTED, $this->hubRow($this->c2)['connection_state']);
        $this->assertFalse($this->hubRow($this->c2)['needs_attention']);
    }
}
