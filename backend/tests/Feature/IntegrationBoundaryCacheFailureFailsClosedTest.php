<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\Support\ScriptedCacheStore;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 (case 2) — a cache or store failure while resolving who may see what FAILS CLOSED.
 *
 * ## What the code actually does
 *
 * The visibility, binding and report-ceiling decisions (`BoundAccountVisibility`, `AccountAssignment`, `LiveReportService`'s
 * read memo, `AccountCampaignCeiling`) hold NO persistent cache: they are SQL against `project_integration_bindings`, and the
 * only memo is in-process and bound to one build (`LiveReportMemoIsolationTest`). So there is no cache entry in those paths
 * that could be stale or missing, and a broken cache store cannot widen them. This file pins that as behaviour — a store that
 * THROWS on every call and a store that LIES (answers `['*']` to every key) must leave every surface exactly as the bindings say —
 * so the day someone caches a ceiling, the cached path has to deny under both or this fails.
 *
 * The one cache-backed access decision on this path is the sign-out marker (`SessionRevocations`, ACCESS-EXIT-003): a session
 * that was signed out must stay signed out when the marker store is down. A 5xx is a denial; a 200 would be a session
 * resurrected by an outage.
 *
 * The limiter is moved to its own healthy store: this test is about the product's own cache use, not about the framework's
 * throttle, which would otherwise 500 every request before any boundary is reached.
 */
final class IntegrationBoundaryCacheFailureFailsClosedTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private const FROM = '2026-08-01';

    private const TO = '2026-08-31';

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    /** @var array<string, ExternalAccount> */
    private array $account = [];

    /** @var array<string, array{campaign: string, unified: string, ad: string, creative: string, name: string}> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

        $this->tenant = $this->bwTenant();
        $workspace = $this->bwWorkspace($this->tenant);
        $this->project = $this->bwProject($this->tenant, $workspace);
        $this->operator = $this->bwOperator($this->tenant);
        $connection = $this->bwConnection($this->tenant, 'snapchat', null, 'snap-token');

        foreach (['bound' => 100.0, 'deselected' => 10.0, 'unbound' => 1.0] as $kind => $spend) {
            $this->account[$kind] = $this->bwAccount($connection, 'act-'.$kind);
            $this->rows[$kind] = $this->bwFigures($this->account[$kind], $this->project, 'cmp-'.$kind, $spend, 'Zed'.strtoupper($kind).'Mark');
        }
        $this->bwBind($this->account['bound'], $this->project);
        $this->bwBind($this->account['deselected'], $this->project, 'advertising', false);
        // `unbound`: no binding record at all.

        app(\App\Domains\Tenancy\Context\TenantContext::class)->forget();
        app(\App\Domains\Projects\Context\ProjectContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function brokenStores(): array
    {
        return ['the store throws on every call' => ['throw'], 'the store answers every key with a wide value' => ['poison']];
    }

    /** Make the DEFAULT cache store misbehave; the rate limiter keeps a healthy one. */
    private function breakCache(string $mode): ScriptedCacheStore
    {
        $store = new ScriptedCacheStore($mode);
        Cache::extend('scripted', fn () => Cache::repository($store));
        config(['cache.stores.scripted' => ['driver' => 'scripted'], 'cache.limiter' => 'array']);
        app('cache')->setDefaultDriver('scripted');
        app('cache')->purge('scripted');
        app()->forgetInstance(\App\Domains\Identity\Support\SessionRevocations::class);

        return $store;
    }

    #[DataProvider('brokenStores')]
    public function test_every_read_surface_still_shows_exactly_the_bound_account_when_the_cache_is_broken(string $mode): void
    {
        // Control first, on a healthy cache: the figures this test compares against are the bound account's alone.
        $healthy = $this->asOperator('metrics/summary?from='.self::FROM.'&to='.self::TO);
        $this->assertEqualsWithDelta(200.0, (float) ($healthy['current']['spend'] ?? -1), 0.001);

        $store = $this->breakCache($mode);

        $summary = $this->asOperator('metrics/summary?from='.self::FROM.'&to='.self::TO);
        $this->assertEqualsWithDelta(200.0, (float) ($summary['current']['spend'] ?? -1), 0.001, 'a broken cache changed what the project may see');

        $campaigns = $this->asOperator('metrics/campaigns?from='.self::FROM.'&to='.self::TO);
        $this->assertEqualsWithDelta(200.0, (float) collect($campaigns)->sum(fn (array $r): float => (float) ($r['spend'] ?? 0)), 0.001);

        foreach (['campaigns' => 'unified', 'ads' => 'ad', 'creatives' => 'creative'] as $axis => $key) {
            $offered = array_column($this->asOperator('reports/scope/options?axis='.$axis)[$axis] ?? [], 'id');
            $this->assertContains($this->rows['bound'][$key], $offered, "{$axis}: the bound account's row vanished");
            $this->assertNotContains($this->rows['deselected'][$key], $offered, "{$axis}: a deselected account's row is offered under a broken cache");
            $this->assertNotContains($this->rows['unbound'][$key], $offered, "{$axis}: an unbound account's row is offered under a broken cache");
        }

        $analytics = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/app/clients/'.$this->project->client_workspace_id.'/analytics?from='.self::FROM.'&to='.self::TO)->assertOk()->json('data');
        $this->assertEqualsWithDelta(200.0, (float) ($analytics['totals']['spend'] ?? -1), 0.001);

        // The public live link and the files it hands out: the ceiling is rebuilt from the bindings, not remembered.
        $this->restoreHealthyCache();
        $token = $this->bwLiveLink($this->project, $this->operator, ['snapchat']);
        $this->breakCache($mode);

        $page = $this->getJson("/api/v1/reports/shared/{$token}/live?from=".self::FROM.'&to='.self::TO)->assertOk()->json('data');
        $this->assertEqualsWithDelta(200.0, (float) ($page['totals']['spend'] ?? -1), 0.001, 'the live link widened under a broken cache');
        $body = (string) json_encode($page, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($this->rows['deselected']['name'], $body);
        $this->assertStringNotContainsString($this->rows['unbound']['name'], $body);

        $csv = $this->get("/api/v1/reports/shared/{$token}/download/csv")->assertOk()->streamedContent();
        $this->assertMatchesRegularExpression('/^spend,200(\.0+)?$/m', $csv);
        $this->assertStringNotContainsString($this->rows['deselected']['name'], $csv);
        $this->assertStringNotContainsString($this->rows['unbound']['name'], $csv);

        $this->assertIsInt($store->touched); // the store was live for the whole block; whatever touched it, it changed nothing
    }

    #[DataProvider('brokenStores')]
    public function test_the_assignment_check_and_the_sweep_still_deny_the_unselected_accounts_when_the_cache_is_broken(string $mode): void
    {
        $this->breakCache($mode);
        $assignment = app(AccountAssignment::class);

        $this->assertTrue($assignment->isActivelyAssigned($this->account['bound']->refresh()));
        $this->assertFalse($assignment->isActivelyAssigned($this->account['deselected']->refresh()), 'a deselected account reads as assigned under a broken cache');
        $this->assertFalse($assignment->isActivelyAssigned($this->account['unbound']->refresh()), 'an unbound account reads as assigned under a broken cache');

        Queue::fake();
        $this->artisan('integrations:sync')->assertSuccessful();
        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
        Queue::assertPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job) => str_contains($job->uniqueId(), (string) $this->account['bound']->id));

        // A job already in the queue for an unselected account reaches no provider.
        Http::preventStrayRequests();
        Http::fake();
        SyncAccountMetricsJob::dispatchSync((string) $this->account['deselected']->id, self::FROM, self::TO);
        SyncAccountMetricsJob::dispatchSync((string) $this->account['unbound']->id, self::FROM, self::TO);
        Http::assertNothingSent();
    }

    public function test_a_signed_out_session_is_not_resurrected_when_the_revocation_store_is_down(): void
    {
        $user = User::create(['name' => 'Racer', 'email' => 'race-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $spa = ['Origin' => 'http://localhost:5173'];
        $browser = function (?string $id) use ($spa) {
            $this->app['auth']->forgetGuards();
            $this->app['session']->flush();
            $this->defaultCookies = [];
            $t = $this->withCredentials()->withHeaders($spa);

            return $id === null ? $t : $t->withCookie(config('session.cookie'), $id);
        };

        $browser(null)->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk();
        $id = $this->app['session']->getId();
        $payload = (string) $this->app['session']->getHandler()->read($id);
        $browser($id)->postJson('/api/v1/auth/logout')->assertOk();

        // A late write brings the signed-out payload back under the same id.
        $this->app['session']->getHandler()->write($id, $payload);

        // Control, healthy store: refused.
        $browser($id)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->app['session']->getHandler()->write($id, $payload);

        // The marker store goes down. The resurrected session must not authenticate.
        $this->breakCache('throw');
        $response = $browser($id)->getJson('/api/v1/auth/me');

        $this->assertNotSame(200, $response->status(), 'a signed-out session was accepted because the revocation store was down');
        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertStringNotContainsString($user->email, (string) $response->getContent(), 'the signed-out user\'s identity was returned');
    }

    private function restoreHealthyCache(): void
    {
        config(['cache.default' => 'array']);
        app('cache')->setDefaultDriver('array');
        app()->forgetInstance(\App\Domains\Identity\Support\SessionRevocations::class);
    }

    /** @return array<mixed> */
    private function asOperator(string $path): array
    {
        return $this->bwAsOperator($this->operator, $this->project, $path);
    }
}
