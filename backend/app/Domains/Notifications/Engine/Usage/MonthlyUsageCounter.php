<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Usage;

use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-workspace monthly usage (spec §1) in the existing `usage_counters` table, one row per (tenant, metric, YYYY-MM).
 * The month is the workspace's month (its timezone). Increments are one atomic upsert, so two workers never lose a count.
 */
final class MonthlyUsageCounter implements UsageCounter
{
    public function __construct(private readonly WorkspaceSettingsRepository $settings) {}

    public function increment(string $tenantId, string $metric, int $by = 1, ?Carbon $at = null): int
    {
        $period = $this->period($tenantId, $at);
        $now = Carbon::now();

        DB::statement(
            'insert into usage_counters (tenant_id, metric, period, count, created_at, updated_at) values (?, ?, ?, ?, ?, ?)
             on conflict (tenant_id, metric, period) do update set count = usage_counters.count + excluded.count, updated_at = excluded.updated_at',
            [$tenantId, $metric, $period, $by, $now, $now],
        );

        return $this->current($tenantId, $metric, $at);
    }

    public function current(string $tenantId, string $metric, ?Carbon $at = null): int
    {
        return (int) DB::table('usage_counters')->where('tenant_id', $tenantId)->where('metric', $metric)
            ->where('period', $this->period($tenantId, $at))->value('count');
    }

    public function month(string $tenantId, ?Carbon $at = null): array
    {
        $rows = DB::table('usage_counters')->where('tenant_id', $tenantId)->where('period', $this->period($tenantId, $at))
            ->whereIn('metric', self::METRICS)->pluck('count', 'metric');

        $out = [];
        foreach (self::METRICS as $metric) {
            $out[$metric] = (int) ($rows[$metric] ?? 0);
        }

        return $out;
    }

    public function period(string $tenantId, ?Carbon $at = null): string
    {
        return ($at ?? Carbon::now())->copy()->setTimezone($this->settings->for($tenantId)->timezone)->format('Y-m');
    }
}
