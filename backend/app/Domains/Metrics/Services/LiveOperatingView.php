<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\WebhookSupport;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Integrations\Support\NextScheduledSync;
use App\Domains\Ops\Models\ScheduledRun;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Unit\LiveViewCadenceGuardTest;

/**
 * LIVE-OPERATING-VIEW-001 — one screen per source: latest successful sync · latest source timestamp ·
 * next sync · state, and how the figures move (scheduled, incremental, manual, webhooks).
 *
 * Nothing here is a second freshness engine. The per-source state and timestamps are
 * {@see DataFreshnessService}'s rows, the next scheduled sweep is {@see NextScheduledSync} and the
 * account's own `next_sync_at`, the scheduler's view of itself is {@see ScheduledWorkStatus}, and
 * webhook support is the provider catalogue's word. This service only puts them on one row.
 *
 * And it is never «real-time». Every provider restates its figures after the fact — a platform's
 * spend for an hour is not final for that hour — so the honest name for this view is «live as of the
 * latest successful sync», and the response carries `realtime: false` so no surface can forget it.
 */
final class LiveOperatingView
{
    /** The verdict's window: the last seven days, the same span the half-hourly sweep re-pulls. */
    public const WINDOW_DAYS = 7;

    /** The scheduled command that moves each kind of source, as `routes/console.php` declares it. */
    private const COMMAND = ['ad_platform' => 'integrations:sync', 'store' => 'commerce:sync'];

    /**
     * The declared cadence of each command, exactly as `routes/console.php` schedules it.
     *
     * Declared here rather than read from the Schedule because an HTTP request never loads the console
     * routes — the schedule is empty there, and the first live preview showed «unknown schedule» on
     * every row. {@see LiveViewCadenceGuardTest} reads `routes/console.php` and holds these
     * two declarations together, so a cadence cannot change in one place only.
     */
    public const DECLARED_CADENCE = [
        'integrations:sync' => '*/30 * * * *',
        'commerce:sync' => '20 * * * *',
        'integrations:sync-structure' => '55 */6 * * *',
        'measurement:sync' => '25 */4 * * *',
    ];

    public function __construct(private readonly DataFreshnessService $freshness) {}

    /**
     * @param  list<string>|null  $providers  restrict the ad-platform rows; null = all
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $projectId, ?array $providers = null, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $from = $now->copy()->subDays(self::WINDOW_DAYS - 1)->startOfDay();
        $to = $now->copy()->endOfDay();

        $state = $this->freshness->state($tenantId, [$projectId], $from, $to, $providers, $now);
        $schedules = $this->schedules($now);
        [$accounts, $connections] = $this->boundAccounts($tenantId, $projectId);

        $sources = [];
        foreach ($state['sources'] as $source) {
            $sources[] = $this->row($source, $accounts, $connections, $schedules, $now);
        }

        return [
            'as_of' => $now->toIso8601String(),
            'realtime' => false,
            'realtime_statement' => [
                'ar' => 'هذه القراءة «حيّة حتى آخر مزامنة ناجحة» لا لحظية: المنصات تؤخّر أرقامها وتعيد تصحيحها بعد الحدث، فلا يُسمّى أي رقم هنا لحظياً.',
                'en' => 'This view is live as of the latest successful sync, not real-time: providers delay and restate their figures after the fact, so nothing here is called real-time.',
            ],
            'window_days' => self::WINDOW_DAYS,
            'verdict' => [
                'state' => $state['state'],
                'last_sync_at' => $state['last_sync_at'],
                'missing_days' => $state['missing_days'],
                'sync_failed' => $state['sync_failed'],
            ],
            'sources' => $sources,
            'scheduler' => array_values($schedules),
        ];
    }

    /**
     * @param  array<string, mixed>  $source  a {@see DataFreshnessService::sources()} row
     * @param  Collection<int, ExternalAccount>  $accounts
     * @param  Collection<string, ProviderConnection>  $connections
     * @param  array<string, array<string, mixed>>  $schedules
     * @return array<string, mixed>
     */
    private function row(array $source, $accounts, $connections, array $schedules, Carbon $now): array
    {
        $kind = (string) $source['kind'];
        $provider = (string) $source['provider'];

        $mine = $kind === 'store'
            ? $accounts->where('id', (string) $source['account_id'])
            : $accounts->where('provider', $provider)->where('account_type', '!=', 'store');

        $connected = $mine->contains(fn (ExternalAccount $a): bool => $this->connected($a, $connections));

        /*
         * «Next sync» is only stated when it is true.
         *
         * The account carries the time the sweep itself wrote (`next_sync_at`), which is the most exact
         * answer; a bound account whose sweep has not yet written one is on the half-hour schedule; and a
         * source with no connected authorisation has no next sync at all — «in 12 minutes» over a revoked
         * token is the most confident kind of wrong, so it is null and the reason is named.
         */
        $nextFromAccount = $mine
            ->filter(fn (ExternalAccount $a): bool => $this->connected($a, $connections) && $a->next_sync_at !== null && $a->next_sync_at->greaterThan($now))
            ->min(fn (ExternalAccount $a) => $a->next_sync_at);
        $command = self::COMMAND[$kind] ?? null;
        $nextSync = match (true) {
            ! $connected => null,
            $nextFromAccount !== null => Carbon::parse((string) $nextFromAccount),
            $kind === 'ad_platform' => NextScheduledSync::at(true, $now),
            default => isset($schedules[$command]['next_run_at']) ? Carbon::parse((string) $schedules[$command]['next_run_at']) : null,
        };

        $webhooks = ProviderCatalogue::has($provider) ? ProviderCatalogue::get($provider)->webhooks : WebhookSupport::PollingOnly;

        return [
            'kind' => $kind,
            'provider' => $provider,
            'account_id' => $source['account_id'],
            'name' => $source['name'],
            'state' => $source['state'],
            'missing_grain' => $source['missing_grain'] ?? null,
            'latest_successful_sync_at' => $source['succeeded_at'] ?? null,
            'latest_attempt_at' => $source['last_checked_at'],
            'latest_source_timestamp' => $source['data_as_of'],
            'latest_metric_date' => $source['latest_metric_date'],
            'last_sync_error' => $source['last_sync_error'],
            'next_sync_at' => $nextSync?->toIso8601String(),
            'next_sync_reason' => $connected ? null : 'not_connected',
            'connected' => $connected,
            'bound_accounts' => $mine->count(),
            'mechanisms' => [
                'scheduled' => $command === null ? null : [
                    'command' => $command,
                    'expression' => $schedules[$command]['expression'] ?? null,
                    'next_run_at' => $schedules[$command]['next_run_at'] ?? null,
                    'last_outcome' => $schedules[$command]['last_outcome'] ?? null,
                    'overdue' => $schedules[$command]['overdue'] ?? null,
                ],
                // The half-hourly ad-platform sweep re-pulls the last seven days; the store sweep walks the shop's own cursor.
                'incremental' => $kind === 'ad_platform' ? ['window_days' => 7] : ['cursor' => true],
                'manual' => true,
                'webhooks' => $webhooks->value,
            ],
            'realtime' => false,
        ];
    }

    /**
     * @param  Collection<string, ProviderConnection>  $connections
     */
    private function connected(ExternalAccount $account, $connections): bool
    {
        $connection = $connections->get((string) $account->provider_connection_id);

        // The stored status, as the connection row carries it — `connected`; everything else is not a sweep.
        return $connection !== null && (string) $connection->status === 'connected';
    }

    /**
     * The project's bound accounts and the authorisations behind them.
     *
     * @return array{0: Collection<int, ExternalAccount>, 1: Collection<string, ProviderConnection>}
     */
    private function boundAccounts(string $tenantId, string $projectId): array
    {
        $ids = BoundAccountVisibility::activeAccountIds($projectId);

        $accounts = $ids === []
            ? collect()
            : ExternalAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $ids)
                ->get(['id', 'provider', 'account_type', 'provider_connection_id', 'next_sync_at', 'last_synced_at']);

        $connectionIds = $accounts->pluck('provider_connection_id')->filter()->unique()->values()->all();
        $connections = $connectionIds === []
            ? collect()
            : ProviderConnection::withoutGlobalScopes()->whereIn('id', $connectionIds)->get(['id', 'provider', 'status'])
                ->keyBy(fn (ProviderConnection $c) => (string) $c->getKey());

        return [$accounts, $connections];
    }

    /**
     * Each scheduled command: its declared cadence, the next run that cadence implies, and what the
     * scheduler recorded the last time it ran (`scheduled_runs`, written by the scheduler itself).
     *
     * @return array<string, array<string, mixed>>
     */
    private function schedules(Carbon $now): array
    {
        $commands = array_keys(self::DECLARED_CADENCE);

        $latest = ScheduledRun::query()
            ->whereIn('command', $commands)
            ->orderByDesc('started_at')
            ->get(['command', 'outcome', 'started_at', 'finished_at'])
            ->unique('command')
            ->keyBy('command');

        $timezone = (string) config('app.timezone') ?: 'UTC';
        $out = [];
        foreach (self::DECLARED_CADENCE as $command => $expression) {
            $run = $latest->get($command);
            $cron = new CronExpression($expression);
            $next = Carbon::instance($cron->getNextRunDate($now->copy()->setTimezone($timezone)))->setTimezone($timezone);
            $lastStarted = $run?->started_at;
            // Overdue: the previous due moment passed with no run started since it.
            $previousDue = Carbon::instance($cron->getPreviousRunDate($now->copy()->setTimezone($timezone)));

            $out[$command] = [
                'command' => $command,
                'expression' => $expression,
                'next_run_at' => $next->toIso8601String(),
                'last_outcome' => $run?->outcome,
                'last_started_at' => $lastStarted?->toIso8601String(),
                'overdue' => $lastStarted === null ? null : $lastStarted->lessThan($previousDue->subMinutes(5)),
            ];
        }

        return $out;
    }
}
