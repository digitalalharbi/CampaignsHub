<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Metrics\Rates\OfficialPegs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BUDGET-FX-DEFAULT-001 — the workspace's default USD→SAR rate, used only where no dated rate exists.
 *
 * Read order for a missing day: dated `currency_rates` (CurrencyConverter) → this workspace's own row → the SAR peg
 * (3.75, a statute, see OfficialPegs). Only the USD/SAR pair is ever answered here; any other currency stays withheld,
 * so a floating currency is never given an invented rate.
 */
final class WorkspaceFxDefault
{
    public const SOURCE = 'workspace_default';

    /** 1 USD = ? SAR for this workspace: its own row, else the peg. */
    public function usdSar(string $tenantId): float
    {
        $row = DB::table('workspace_fx_defaults')->where('tenant_id', $tenantId)
            ->where('base_currency', 'USD')->where('quote_currency', 'SAR')->value('rate');

        return $row !== null ? (float) $row : (OfficialPegs::rate('USD', 'SAR') ?? 3.75);
    }

    /** True when the workspace changed it from the peg (so the UI can say «معدّل»). */
    public function isCustom(string $tenantId): bool
    {
        return DB::table('workspace_fx_defaults')->where('tenant_id', $tenantId)->exists();
    }

    public function set(string $tenantId, float $rate, ?string $userId): void
    {
        $now = now();
        $existing = DB::table('workspace_fx_defaults')->where('tenant_id', $tenantId)
            ->where('base_currency', 'USD')->where('quote_currency', 'SAR')->first();
        if ($existing === null) {
            DB::table('workspace_fx_defaults')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'base_currency' => 'USD',
                'quote_currency' => 'SAR', 'rate' => $rate, 'updated_by' => $userId !== null ? (int) $userId : null, 'created_at' => $now, 'updated_at' => $now]);

            return;
        }
        DB::table('workspace_fx_defaults')->where('id', $existing->id)->update(['rate' => $rate, 'updated_by' => $userId !== null ? (int) $userId : null, 'updated_at' => $now]);
    }

    /**
     * A rate for the pair when it is USD↔SAR; null for every other pair.
     *
     * @return array{rate: float, rate_date: string, source: string}|null
     */
    public function resolve(string $tenantId, string $from, string $to, string $date): ?array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $usdSar = $this->usdSar($tenantId);

        $rate = match (true) {
            $from === 'USD' && $to === 'SAR' => $usdSar,
            $from === 'SAR' && $to === 'USD' && $usdSar > 0 => 1 / $usdSar,
            default => null,
        };

        return $rate === null ? null : ['rate' => $rate, 'rate_date' => $date, 'source' => self::SOURCE];
    }
}
