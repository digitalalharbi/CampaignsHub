<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External\Support;

use App\Domains\Notifications\External\Digest\DigestDataSource;

/** Deterministic figures for the external-message tests (no metrics rows needed). */
final class FakeDigestData implements DigestDataSource
{
    /** @var array<string, mixed> */
    public array $override = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $calls = [];

    public function project(string $tenantId, string $projectId, string $from, string $to, string $displayCurrency): array
    {
        $this->calls[] = [$projectId, $from, $to];
        $days = [];
        $d = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $i = 0;
        while ($d <= $end && $i < 31) {
            $days[] = ['date' => $d->format('Y-m-d'), 'spend' => 100.0 + ($i * 7 % 40)];
            $d = $d->modify('+1 day');
            $i++;
        }

        return array_replace([
            'complete' => true,
            'missing' => [],
            'currency' => $displayCurrency,
            'spend' => 4200.0,
            'previous_spend' => 3900.0,
            'objectives' => [
                ['objective' => 'sales', 'result_key' => 'purchases', 'spend' => 3000.0, 'result' => 120.0, 'cost_per_result' => 25.0, 'revenue' => 15000.0, 'roas' => 5.0, 'previous_result' => 100.0, 'previous_cost_per_result' => 27.0],
                ['objective' => 'awareness', 'result_key' => 'reach', 'spend' => 1200.0, 'result' => 250000.0, 'cost_per_result' => 4.8, 'revenue' => null, 'roas' => null, 'previous_result' => 240000.0, 'previous_cost_per_result' => 5.0],
            ],
            'platforms' => [
                ['provider' => 'meta', 'spend' => 2500.0, 'previous_spend' => 2300.0],
                ['provider' => 'snapchat', 'spend' => 1200.0, 'previous_spend' => 1100.0],
                ['provider' => 'tiktok', 'spend' => 500.0, 'previous_spend' => 500.0],
            ],
            'series' => $days,
            'previous_series' => $days,
            'campaigns' => [
                ['name' => 'Ramadan Sale', 'objective' => 'sales', 'spend' => 1800.0, 'result' => 80.0, 'result_key' => 'purchases', 'cost_per_result' => 22.5],
                ['name' => 'Brand Reach', 'objective' => 'awareness', 'spend' => 1200.0, 'result' => 250000.0, 'result_key' => 'reach', 'cost_per_result' => 4.8],
            ],
            'budget' => ['has_budget' => true, 'currency' => $displayCurrency, 'original_currency' => 'SAR', 'approved' => 10000.0, 'spent' => 4200.0, 'remaining' => 5800.0, 'approved_original' => 10000.0, 'used_ratio' => 0.42, 'pace_per_day' => 300.0, 'run_out_date' => '2026-12-04', 'cover_days' => 19, 'period_end' => '2026-11-30'],
            'suggestions' => [['text' => 'توسيع جمهور الحملة الأفضل أداءً', 'approved' => true]],
            'achievements' => [],
            'fx' => $displayCurrency === 'SAR' ? [] : [['from' => 'SAR', 'to' => $displayCurrency, 'rate' => 0.266667, 'rate_date' => '1986-06-01', 'kind' => 'pegged', 'withheld' => false]],
            'data_project_ids' => [$projectId],
        ], $this->override);
    }
}
