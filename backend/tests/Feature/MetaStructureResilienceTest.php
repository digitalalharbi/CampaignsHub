<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Support\PresentationAudience;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * META-RESILIENCE-001 — a dead token or a throttle stops the sweep asking; what the media reads did is
 * written on the run; an expired link no listing returns is renewed from its own node.
 */
final class MetaStructureResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const DEAD = ['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460, 'fbtrace_id' => 'T']];

    private const THROTTLED = ['error' => ['message' => 'User request limit reached', 'type' => 'OAuthException', 'code' => 17, 'error_subcode' => 2446079, 'fbtrace_id' => 'T']];

    private const NO_GRANT = ['error' => ['message' => '(#200) Ad account owner has NOT grant ads_management or ads_read permission', 'type' => 'OAuthException', 'code' => 200, 'fbtrace_id' => 'T']];

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $this->tenant = Tenant::create(['name' => 'R', 'slug' => 'r-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_a_dead_token_on_the_first_read_stops_the_other_structure_reads(): void
    {
        $account = $this->account('act_1');
        $asked = 0;
        Http::fake(function () use (&$asked) {
            $asked++;

            return Http::response(self::DEAD, 400);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(1, $asked, 'campaigns refused with 190: ad sets and ads must not be asked');
        $this->assertSame('failed', $run->status);
        $this->assertSame('error', ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id)?->status);
    }

    public function test_a_throttle_on_a_first_read_stops_the_others_and_leaves_the_connection_connected(): void
    {
        $account = $this->account('act_1');
        $asked = 0;
        Http::fake(function () use (&$asked) {
            $asked++;

            return Http::response(self::THROTTLED, 400);
        });

        app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(1, $asked);
        $this->assertSame('connected', ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id)?->status);
    }

    public function test_a_grant_refusal_still_lets_the_other_reads_try(): void
    {
        $account = $this->account('act_1');
        $asked = 0;
        Http::fake(function () use (&$asked) {
            $asked++;

            return Http::response(self::NO_GRANT, 403);
        });

        app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(3, $asked);
    }

    public function test_a_throttle_during_per_node_reads_stops_them_and_says_so_on_the_run(): void
    {
        $account = $this->account('act_1');
        $nodes = 0;
        Http::fake(function (Request $request) use (&$nodes) {
            $url = $request->url();
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'c1', 'name' => 'C', 'status' => 'ACTIVE']]]);
            }
            if (str_contains($url, '/adsets')) {
                return Http::response(['data' => [['id' => 's1', 'campaign_id' => 'c1', 'name' => 'S', 'status' => 'ACTIVE']]]);
            }
            if (str_contains($url, '/ads?') || str_ends_with($url, '/ads')) {
                return Http::response(['data' => $this->ads(40)]);
            }
            $nodes++;

            return Http::response(self::THROTTLED, 400);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(1, $nodes, 'forty creatives, and the first answered «slow down»');
        $media = (array) ($run->fresh()?->meta['media'] ?? []);
        $this->assertSame(40, $media['asked']);
        $this->assertSame(0, $media['resolved']);
        $this->assertSame(39, $media['not_asked']);
        $this->assertSame('rate_limited', $media['stopped_by']);
        $this->assertStringContainsString('code 17', (string) $media['error']);
    }

    public function test_consecutive_refusals_of_one_kind_stop_the_per_node_reads(): void
    {
        $account = $this->account('act_1');
        $nodes = 0;
        Http::fake(function (Request $request) use (&$nodes) {
            $url = $request->url();
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'c1', 'name' => 'C', 'status' => 'ACTIVE']]]);
            }
            if (str_contains($url, '/adsets')) {
                return Http::response(['data' => [['id' => 's1', 'campaign_id' => 'c1', 'name' => 'S', 'status' => 'ACTIVE']]]);
            }
            if (str_contains($url, '/ads?') || str_ends_with($url, '/ads')) {
                return Http::response(['data' => $this->ads(40)]);
            }
            $nodes++;

            return Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 403);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(10, $nodes);
        $this->assertSame('permission_missing', $run->fresh()?->meta['media']['stopped_by'] ?? null);
    }

    public function test_the_run_names_the_ad_account_it_read_and_the_usage_meta_reported(): void
    {
        $account = $this->account('act_1');
        Http::fake(fn () => Http::response(['data' => []], 200, [
            'X-Business-Use-Case-Usage' => json_encode(['1' => [['call_count' => 42, 'total_cputime' => 7, 'total_time' => 9, 'estimated_time_to_regain_access' => 0]]]),
        ]));

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('act_1', $run->fresh()?->meta['external_account_id'] ?? null);
        $this->assertSame(42, $run->fresh()?->meta['usage']['max_call_count'] ?? null);
    }

    public function test_usage_meta_says_is_exhausted_stops_per_node_reads_before_any_refusal(): void
    {
        $account = $this->account('act_1');
        $nodes = 0;
        Http::fake(function (Request $request) use (&$nodes) {
            $url = $request->url();
            $spent = ['X-Business-Use-Case-Usage' => json_encode(['1' => [['call_count' => 100, 'estimated_time_to_regain_access' => 12]]])];
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'c1', 'name' => 'C', 'status' => 'ACTIVE']]]);
            }
            if (str_contains($url, '/adsets')) {
                return Http::response(['data' => [['id' => 's1', 'campaign_id' => 'c1', 'name' => 'S', 'status' => 'ACTIVE']]]);
            }
            if (str_contains($url, '/ads?') || str_ends_with($url, '/ads')) {
                return Http::response(['data' => $this->ads(3)], 200, $spent);
            }
            $nodes++;

            return Http::response(['thumbnail_url' => 'https://x.example/t.jpg']);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(0, $nodes);
        $this->assertSame(12, $run->fresh()?->meta['usage']['regain_minutes'] ?? null);
        $this->assertSame('rate_limited', $run->fresh()?->meta['media']['stopped_by'] ?? null);
    }

    public function test_a_page_too_large_is_asked_again_smaller_rather_than_lost(): void
    {
        $account = $this->account('act_1');
        $limits = [];
        Http::fake(function (Request $request) use (&$limits) {
            $url = $request->url();
            if (str_contains($url, '/campaigns')) {
                $limits[] = (int) ($request->data()['limit'] ?? 0);
                if (end($limits) > 100) {
                    return Http::response(['error' => ['message' => "Please reduce the amount of data you're asking for, then retry your request", 'code' => 1]], 500);
                }

                return Http::response(['data' => [['id' => 'c1', 'name' => 'C', 'status' => 'ACTIVE']]]);
            }

            return Http::response(['data' => []]);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame([500, 250, 125, 62], array_values(array_unique($limits)));
        $this->assertSame(1, ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $account->id)->count());
        $this->assertSame('success', $run->status);
    }

    public function test_the_size_retreat_has_a_floor(): void
    {
        $account = $this->account('act_1');
        $asked = 0;
        Http::fake(function (Request $request) use (&$asked) {
            if (str_contains($request->url(), '/campaigns')) {
                $asked++;

                return Http::response(['error' => ['message' => 'Please reduce the amount of data', 'code' => 1]], 400);
            }

            return Http::response(['data' => []]);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertLessThanOrEqual(6, $asked);
        $this->assertStringContainsString('reduce the amount of data', (string) $run->error);
    }

    public function test_an_expired_link_no_listing_returned_is_renewed_from_its_own_node(): void
    {
        [$account, $campaign] = $this->accountWithCampaign('act_1');
        $still = $this->creative($campaign, 'k1', ['format' => 'image', 'asset_url' => 'https://cdn.example/old.jpg', 'raw' => ['media_refs' => ['image_hash' => 'h1']]]);
        $film = $this->creative($campaign, 'k2', ['format' => 'video', 'video_url' => 'https://cdn.example/old.mp4', 'thumbnail_url' => 'https://cdn.example/old-p.jpg', 'raw' => ['media_refs' => ['video_id' => 'v2', 'poster' => true]]]);
        $thumb = $this->creative($campaign, 'k3', ['format' => 'catalog', 'thumbnail_url' => 'https://cdn.example/old-t.jpg']);
        $fresh = $this->creative($campaign, 'k4', ['format' => 'image', 'asset_url' => 'https://cdn.example/fresh.jpg', 'asset_expires_at' => now()->addDay()]);
        $future = dechex(now()->addDays(3)->getTimestamp());
        $asked = [];

        Http::fake(function (Request $request) use ($future, &$asked) {
            $url = $request->url();
            $asked[] = $url;

            return match (true) {
                str_contains($url, '/adimages') => Http::response(['data' => [['hash' => 'h1', 'url' => "https://cdn.example/new.jpg?oe={$future}"]]]),
                str_contains($url, '/v2?') => Http::response(['source' => "https://cdn.example/new.mp4?oe={$future}", 'picture' => "https://cdn.example/new-p.jpg?oe={$future}"]),
                str_contains($url, '/k3?') => Http::response(['thumbnail_url' => "https://cdn.example/new-t.jpg?oe={$future}"]),
                default => Http::response(['data' => []]),
            };
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertStringStartsWith('https://cdn.example/new.jpg', (string) $still->fresh()?->asset_url);
        $this->assertStringStartsWith('https://cdn.example/new.mp4', (string) $film->fresh()?->video_url);
        $this->assertStringStartsWith('https://cdn.example/new-p.jpg', (string) $film->fresh()?->thumbnail_url);
        $this->assertStringStartsWith('https://cdn.example/new-t.jpg', (string) $thumb->fresh()?->thumbnail_url);
        $this->assertTrue($still->fresh()?->asset_expires_at?->isFuture());
        $this->assertSame('https://cdn.example/fresh.jpg', $fresh->fresh()?->asset_url);
        $this->assertSame(['expired' => 3, 'asked' => 3, 'renewed' => 3, 'still_expired' => 0, 'stopped_by' => null], $run->fresh()?->meta['media_renewal'] ?? null);
        $this->assertNotContains(true, array_map(static fn (string $u): bool => str_contains($u, '/k4?'), $asked));
    }

    public function test_a_refused_renewal_keeps_the_stored_link_and_counts_it(): void
    {
        [$account, $campaign] = $this->accountWithCampaign('act_1');
        $creative = $this->creative($campaign, 'k1', ['format' => 'catalog', 'thumbnail_url' => 'https://cdn.example/old-t.jpg']);

        Http::fake(fn (Request $r) => str_contains($r->url(), '/k1?')
            ? Http::response(self::NO_GRANT, 403)
            : Http::response(['data' => []]));

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('https://cdn.example/old-t.jpg', $creative->fresh()?->thumbnail_url);
        $this->assertTrue($creative->fresh()?->asset_expires_at?->isPast());
        $this->assertSame(1, $run->fresh()?->meta['media_renewal']['still_expired'] ?? null);
        $this->assertNotNull($creative->fresh()?->raw['media_renewal_attempted_at'] ?? null);
    }

    public function test_renewal_is_bounded_and_the_oldest_attempt_goes_first(): void
    {
        [$account, $campaign] = $this->accountWithCampaign('act_1');
        for ($i = 1; $i <= 3; $i++) {
            $this->creative($campaign, "k{$i}", ['format' => 'catalog', 'thumbnail_url' => "https://cdn.example/{$i}.jpg", 'raw' => ['media_renewal_attempted_at' => now()->subHours($i)->toIso8601String()]]);
        }
        $this->creative($campaign, 'k9', ['format' => 'catalog', 'thumbnail_url' => 'https://cdn.example/9.jpg']);
        config()->set('ad_platforms.platforms.meta.media_renewal_per_sweep', 2);
        $asked = [];

        Http::fake(function (Request $request) use (&$asked) {
            if (preg_match('#/(k\d)\?#', $request->url(), $m) === 1) {
                $asked[] = $m[1];

                return Http::response(['id' => $m[1]]);
            }

            return Http::response(['data' => []]);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertEqualsCanonicalizing(['k9', 'k3'], $asked, 'never tried first, then the longest ago');
        $this->assertSame(4, $run->fresh()?->meta['media_renewal']['expired'] ?? null);
    }

    public function test_renewal_never_runs_when_the_sweep_was_told_to_stop(): void
    {
        [$account, $campaign] = $this->accountWithCampaign('act_1');
        $this->creative($campaign, 'k1', ['format' => 'catalog', 'thumbnail_url' => 'https://cdn.example/old-t.jpg']);
        $asked = 0;
        Http::fake(function () use (&$asked) {
            $asked++;

            return Http::response(self::DEAD, 400);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(1, $asked);
        $this->assertArrayNotHasKey('media_renewal', (array) $run->fresh()?->meta);
    }

    public function test_an_expired_link_under_a_dead_session_names_the_refusal_not_a_fresh_sync(): void
    {
        [$account, $campaign] = $this->accountWithCampaign('act_1');
        $creative = $this->creative($campaign, 'k1', ['format' => 'image', 'asset_url' => 'https://cdn.example/old.jpg']);
        Http::fake(fn () => Http::response(self::DEAD, 400));
        app(AccountStructureSyncer::class)->sync($account);
        app(PresentationAudience::class)->forOperator();

        $preview = app(CreativePresenter::class)->preview($creative->fresh());

        $this->assertSame('expired', $preview['state']);
        $this->assertTrue($preview['access_refused'] ?? false);
    }

    /** One authorisation holds many accounts with different answers: the refusal belongs to the account refused. */
    public function test_a_grant_refusal_on_one_account_does_not_speak_for_its_sibling(): void
    {
        [$refused, $refusedCampaign] = $this->accountWithCampaign('act_1');
        $connection = ProviderConnection::withoutGlobalScopes()->findOrFail($refused->provider_connection_id);
        [$healthy, $healthyCampaign] = $this->accountWithCampaign('act_2', $connection);
        $a = $this->creative($refusedCampaign, 'k1', ['format' => 'image', 'asset_url' => 'https://cdn.example/a.jpg']);
        $b = $this->creative($healthyCampaign, 'k2', ['format' => 'image', 'asset_url' => 'https://cdn.example/b.jpg']);

        Http::fake(fn (Request $r) => str_contains($r->url(), 'act_1') ? Http::response(self::NO_GRANT, 403) : Http::response(['data' => []]));
        app(AccountStructureSyncer::class)->sync($refused);
        $this->travel(1)->minutes();
        Http::fake(fn (Request $r) => str_contains($r->url(), 'act_1') ? Http::response(self::NO_GRANT, 403) : (str_contains($r->url(), 'k2') ? Http::response(self::NO_GRANT, 403) : Http::response(['data' => []])));
        app(AccountStructureSyncer::class)->sync($healthy);
        app(PresentationAudience::class)->forOperator();

        $this->assertTrue(app(CreativePresenter::class)->preview($a->fresh())['access_refused'] ?? false);
        $this->assertArrayNotHasKey('access_refused', app(CreativePresenter::class)->preview($b->fresh()));
    }

    public function test_a_dead_session_found_by_discovery_marks_the_connection_too(): void
    {
        $account = $this->account('act_1');
        $connection = ProviderConnection::withoutGlobalScopes()->findOrFail($account->provider_connection_id);
        Http::fake(fn () => Http::response(self::DEAD, 400));

        try {
            app(AccountDiscovery::class)->refresh($connection);
            $this->fail('discovery rethrows the refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('code 190', $e->getMessage());
        }

        $this->assertSame('error', $connection->fresh()?->status);
    }

    public function test_a_throttled_businesses_read_is_not_called_a_missing_business_management_grant(): void
    {
        $account = $this->account('act_1');
        $connection = ProviderConnection::withoutGlobalScopes()->findOrFail($account->provider_connection_id);
        Http::fake(fn (Request $r) => str_contains($r->url(), 'me/adaccounts')
            ? Http::response(['data' => [['id' => 'act_1', 'name' => 'A', 'account_status' => 1]]])
            : Http::response(self::THROTTLED, 400));

        $connector = app(\App\Domains\Integrations\Registry\AdvertisingConnectorRegistry::class)->get('meta')->withConnection($connection);
        $connector->listAdAccounts();
        $warnings = $connector->lastDiscoveryWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringNotContainsString('re-authorisation', $warnings[0]['reason']);
        $this->assertStringContainsString('code 17', $warnings[0]['reason']);
    }

    public function test_a_failed_media_read_logs_no_token(): void
    {
        $account = $this->account('act_1');
        $logged = [];
        Log::listen(function (MessageLogged $log) use (&$logged): void {
            $logged[] = json_encode($log->context);
        });
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/campaigns') || str_contains($url, '/adsets')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($url, '/ads?') || str_ends_with($url, '/ads')) {
                return Http::response(['data' => $this->ads(1)]);
            }

            return Http::response(['error' => ['message' => 'Bad call for https://graph.facebook.com/x?access_token=SECRETTOKEN', 'code' => 100]], 400);
        });

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertStringNotContainsString('SECRETTOKEN', implode(' ', $logged));
        $this->assertStringNotContainsString('SECRETTOKEN', (string) json_encode($run->fresh()?->meta));
    }

    /** @return list<array<string,mixed>> */
    private function ads(int $count): array
    {
        $ads = [];
        for ($i = 1; $i <= $count; $i++) {
            $ads[] = ['id' => "a{$i}", 'name' => "Ad {$i}", 'status' => 'ACTIVE', 'adset_id' => 's1', 'campaign_id' => 'c1', 'creative' => ['id' => "k{$i}", 'object_type' => 'SHARE', 'thumbnail_url' => "https://cdn.example/{$i}.jpg"]];
        }

        return $ads;
    }

    private function account(string $externalId, ?ProviderConnection $connection = null): ExternalAccount
    {
        $connection ??= app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta');

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true, 'campaign_management_enabled' => true,
        ]);

        return $account;
    }

    /** @return array{ExternalAccount, ExternalCampaign} */
    private function accountWithCampaign(string $externalId, ?ProviderConnection $connection = null): array
    {
        $account = $this->account($externalId, $connection);
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => "c-{$externalId}", 'name' => 'C', 'status' => 'active',
        ]);

        return [$account, $campaign];
    }

    /** @param array<string,mixed> $attributes */
    private function creative(ExternalCampaign $campaign, string $externalId, array $attributes): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_creative_id' => $externalId, 'name' => $externalId,
            'asset_expires_at' => now()->subDay(), 'source_type' => 'api', 'is_demo' => false,
            ...$attributes,
        ]);
    }
}
