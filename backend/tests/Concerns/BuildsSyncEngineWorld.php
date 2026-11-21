<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncCheckpoint;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Closure;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * INTEG-SYNC-ENGINE-001 — one Meta account with one campaign, one ad set and one ad, and a provider driven by a script, so the
 * checkpoint, budget and dead-letter tests share the world and the failures they stage. No request leaves the process.
 */
trait BuildsSyncEngineWorld
{
    use BuildsBoundaryWorld;

    protected Tenant $tenant;

    protected Project $project;

    protected ProviderConnection $connection;

    protected ExternalAccount $account;

    /** @var array<string,mixed> what the provider answers next */
    protected array $script = ['mode' => 'ok'];

    /** @var list<string> every URL the provider was asked for */
    protected array $asked = [];

    /** Runs once from inside a provider call, the way a customer's click lands during a sync. */
    protected ?Closure $duringCall = null;

    protected function buildSyncEngineWorld(string $now = '2026-08-02 12:00:00'): void
    {
        $this->seed(MetricDefinitionSeeder::class);
        $this->bwConfigure('meta');
        Sleep::fake();
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));

        $this->tenant = $this->bwTenant();
        $this->project = $this->bwProject($this->tenant, $this->bwWorkspace($this->tenant));
        $this->connection = $this->bwConnection($this->tenant, 'meta', expiresAt: Carbon::now()->addYears(2));
        $this->account = $this->bwAccount($this->connection, 'act_1');
        $this->bwBind($this->account, $this->project);
        $this->bwStructure($this->account, $this->project);

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));
    }

    protected function bwStructure(ExternalAccount $account, Project $project): void
    {
        $base = ['tenant_id' => $account->tenant_id, 'project_id' => $project->id, 'provider' => 'meta', 'status' => 'active'];
        $campaign = ExternalCampaign::withoutGlobalScopes()->create($base + ['external_account_id' => $account->id, 'external_id' => 'cmp-'.$account->external_id, 'name' => 'Campaign']);
        $adSet = ExternalAdSet::withoutGlobalScopes()->create($base + ['external_campaign_id' => $campaign->id, 'external_id' => 'set-'.$account->external_id, 'name' => 'Set']);
        ExternalAd::withoutGlobalScopes()->create($base + ['external_ad_set_id' => $adSet->id, 'external_campaign_id' => $campaign->id, 'external_id' => 'ad-'.$account->external_id, 'name' => 'Ad']);
    }

    /** The scripted provider. `mode` names the failure; everything else answers one row per grain. */
    protected function answer(Request $request): mixed
    {
        $url = urldecode($request->url());
        $this->asked[] = $url;

        if ($this->duringCall !== null) {
            $call = $this->duringCall;
            $this->duringCall = null;
            $call();
        }

        $mode = (string) ($this->script['mode'] ?? 'ok');
        $headers = (array) ($this->script['headers'] ?? []);

        if (! str_contains($url, '/insights')) {
            return Http::response(['data' => []], 200, $headers);
        }

        preg_match('#/(act_\d+)/insights#', $url, $m);
        $id = $m[1] ?? 'act_1';
        $row = ['campaign_id' => 'cmp-'.$id, 'date_start' => (string) ($this->script['date'] ?? '2026-08-01'), 'spend' => (string) ($this->script['spend'] ?? '1200'), 'impressions' => '40000', 'clicks' => '800'];

        if (str_contains($url, 'level=adset')) {
            if ($mode === 'adset_token_timeout') {
                throw new ConnectionException('cURL error 28: Operation timed out for https://graph.facebook.com/v21.0/'.$id.'/insights?access_token=SEKRET-TOKEN&level=adset');
            }

            return $mode === 'adset_500'
                ? Http::response(['error' => ['message' => 'Service unavailable']], 503)
                : Http::response(['data' => [[...$row, 'adset_id' => 'set-'.$id]]], 200, $headers);
        }
        if (str_contains($url, 'level=ad')) {
            return Http::response(['data' => [[...$row, 'ad_id' => 'ad-'.$id, 'adset_id' => 'set-'.$id]]], 200, $headers);
        }

        if ($mode === 'endless' && str_contains($url, 'after=AGAIN')) {
            return Http::response(['data' => [['date_start' => Carbon::parse('2026-01-01')->addDays(count($this->asked))->toDateString()] + $row], 'paging' => ['next' => "https://graph.facebook.com/v21.0/{$id}/insights?after=AGAIN&level=campaign"]], 200);
        }

        if (str_contains($url, 'after=PAGE2')) {
            return match ($mode) {
                'page2_500' => Http::response(['error' => ['message' => 'Internal error', 'code' => 2]], 500),
                'page2_429' => Http::response(['error' => ['message' => 'Too many calls', 'code' => 4]], 429, $headers),
                'page2_timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                'page2_token_timeout' => throw new ConnectionException('cURL error 28: Operation timed out for https://graph.facebook.com/v21.0/'.$id.'/insights?access_token=SEKRET-TOKEN&after=PAGE2'),
                default => Http::response(['data' => []], 200, $headers),
            };
        }

        return match ($mode) {
            'first_503' => Http::response(['error' => ['message' => 'Service unavailable']], 503),
            'first_401' => Http::response(['error' => ['message' => 'Permissions error', 'code' => 10]], 403),
            'unmapped' => Http::response(['data' => [$row, [...$row, 'campaign_id' => 'never-discovered']]], 200, $headers),
            'no_rows' => Http::response(['data' => []], 200, $headers),
            'endless' => Http::response(['data' => [['date_start' => Carbon::parse('2026-01-01')->addDays(count($this->asked))->toDateString()] + $row], 'paging' => ['next' => "https://graph.facebook.com/v21.0/{$id}/insights?after=AGAIN&level=campaign"]], 200, $headers),
            'page2_500', 'page2_429', 'page2_timeout', 'page2_token_timeout' => Http::response(['data' => [$row], 'paging' => ['next' => "https://graph.facebook.com/v21.0/{$id}/insights?after=PAGE2&level=campaign"]], 200),
            default => Http::response(['data' => [$row]], 200, $headers),
        };
    }

    protected function checkpoint(string $grain = 'campaign', ?ExternalAccount $account = null): ?MetricSyncCheckpoint
    {
        return MetricSyncCheckpoint::withoutGlobalScopes()
            ->where('external_account_id', ($account ?? $this->account)->id)
            ->where('grain', $grain)
            ->first();
    }

    /** @return array<string,mixed> */
    protected function checkpointFacts(string $grain = 'campaign'): array
    {
        $row = $this->checkpoint($grain);

        return $row === null ? [] : [
            'from' => $row->window_from->toDateString(),
            'to' => $row->window_to->toDateString(),
            'completed_at' => $row->completed_at?->toIso8601String(),
            'run' => $row->metric_sync_run_id,
        ];
    }

    protected function lastRun(): MetricSyncRun
    {
        return MetricSyncRun::withoutGlobalScopes()->orderByDesc('created_at')->orderByDesc('started_at')->firstOrFail();
    }

    protected function storedSpend(): float
    {
        return (float) DB::table('daily_metrics')->where('metric_key', 'spend')->sum('value');
    }

    /** Asked for insights since this date, at the campaign grain. */
    protected function askedSince(string $date): bool
    {
        foreach ($this->asked as $url) {
            if (str_contains($url, 'level=campaign') && str_contains($url, '"since":"'.$date.'"')) {
                return true;
            }
        }

        return false;
    }
}
