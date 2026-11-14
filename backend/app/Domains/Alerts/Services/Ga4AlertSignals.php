<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Integrations\Measurement\Ga4AttributionMatch;
use App\Domains\Integrations\Measurement\Ga4StoreAnalytics;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;

/**
 * GA4-ALERTS-001 — the store / GA4 rules, as breaches the one alert engine already knows how to raise.
 *
 * Nothing here opens an alert, notifies, mails or deduplicates: it returns breaches in the shape every other
 * type returns, and `AlertEvaluator` applies its cooldown, snooze, dedup key, repeat ceiling, task option,
 * notification preferences and recovery to them. Rules are created by people, so a tenant that never creates
 * one gets none: these are off by default.
 *
 * The window is `threshold.days` (default 7) against the window before it. A figure the property did not
 * measure in either window is not a breach, and a zero denominator is not a drop. Messages carry shares and
 * counts, never a spend amount: alerts also reach people who are not allowed to read spend.
 *
 * @phpstan-type Breach array{entity_type: string, entity_id: string, project_id: string, title: string, message: string, context: array<string, mixed>}
 */
final class Ga4AlertSignals
{
    /** type => the share (percent) a rule uses when it names none */
    public const DEFAULT_PCT = [
        'ga4_sales_drop' => 30, 'ga4_bounce_spike' => 20, 'ga4_cvr_drop' => 30, 'ga4_aov_shift' => 25,
        'ga4_attribution_gap' => 50, 'ga4_tracking_loss' => 90, 'ga4_unmatched_spend' => 40,
    ];

    public const TYPES = ['ga4_sales_drop', 'ga4_bounce_spike', 'ga4_cvr_drop', 'ga4_aov_shift', 'ga4_attribution_gap', 'ga4_tracking_loss', 'ga4_unmatched_spend'];

    public function __construct(private readonly Ga4StoreAnalytics $store, private readonly Ga4AttributionMatch $match) {}

    /** @return list<array<string, mixed>> */
    public function breaches(AlertRule $rule, Carbon $now): array
    {
        $pct = (float) ($rule->threshold['pct'] ?? self::DEFAULT_PCT[$rule->type] ?? 30);
        $days = max(1, min(60, (int) ($rule->threshold['days'] ?? 7)));
        $to = $now->copy()->subDay()->startOfDay();
        $from = $to->copy()->subDays($days - 1);

        $out = [];
        foreach ($this->projects((string) $rule->tenant_id, $rule->project_id) as $project) {
            $b = match ($rule->type) {
                'ga4_sales_drop' => $this->kpiMove($project, $from, $to, 'transactions', -$pct, 'Sales dropped', 'المبيعات انخفضت'),
                'ga4_bounce_spike' => $this->kpiMove($project, $from, $to, 'bounce_rate', $pct, 'Bounce rate rose', 'ارتفع الارتداد'),
                'ga4_cvr_drop' => $this->kpiMove($project, $from, $to, 'conversion_rate', -$pct, 'Conversion dropped', 'انخفض التحويل'),
                'ga4_aov_shift' => $this->aovShift($project, $from, $to, $pct),
                'ga4_attribution_gap' => $this->attributionGap($project, $from, $to, $pct / 100),
                'ga4_tracking_loss' => $this->trackingLoss($project, $from, $to, $pct / 100),
                'ga4_unmatched_spend' => $this->unmatchedSpend($project, $from, $to, $pct / 100),
                default => null,
            };
            if ($b !== null) {
                $out[] = $b;
            }
        }

        return $out;
    }

    /** @return list<Project> the projects of the tenant that selected a GA4 property (and the one the rule names, if any) */
    private function projects(string $tenantId, ?string $only): array
    {
        $ids = ProjectIntegrationBinding::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider', 'ga4')->where('is_active', true)
            ->when($only !== null, fn ($q) => $q->where('project_id', $only))->distinct()->pluck('project_id')->all();

        return $ids === [] ? [] : Project::withoutGlobalScopes()->whereIn('id', $ids)->get()->all();
    }

    /** @return array<string, mixed>|null */
    private function kpiMove(Project $project, Carbon $from, Carbon $to, string $key, float $signedPct, string $en, string $ar): ?array
    {
        $k = $this->kpi($project, $from, $to, $key);
        if ($k === null || $k['change_pct'] === null) {
            return null;
        }
        $change = $k['change_pct'] * 100;
        if (($signedPct < 0 && $change > $signedPct) || ($signedPct >= 0 && $change < $signedPct)) {
            return null;
        }

        return $this->breach($project, "ga4:$key", "$en · ".abs((int) round($change)).'%', "$ar · ".abs((int) round($change)).'%',
            'The website measured a change of '.round($change, 1).'% against the previous period.', 'قاس الموقع تغيّرًا بنسبة '.round($change, 1).'% عن الفترة السابقة.',
            ['key' => $key, 'current' => $k['current'], 'previous' => $k['previous'], 'change_pct' => round($change, 1), 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
    }

    /** @return array<string, mixed>|null */
    private function aovShift(Project $project, Carbon $from, Carbon $to, float $pct): ?array
    {
        $k = $this->kpi($project, $from, $to, 'aov');
        if ($k === null || $k['change_pct'] === null || abs($k['change_pct'] * 100) < $pct) {
            return null;
        }
        $change = round($k['change_pct'] * 100, 1);

        return $this->breach($project, 'ga4:aov', 'Basket size shifted · '.abs($change).'%', 'تغيّر متوسط السلة · '.abs($change).'%',
            'Average order value moved '.$change.'% against the previous period.', 'تحرّك متوسط السلة '.$change.'% عن الفترة السابقة.',
            ['key' => 'aov', 'current' => $k['current'], 'previous' => $k['previous'], 'change_pct' => $change]);
    }

    /** @return array<string, mixed>|null */
    private function attributionGap(Project $project, Carbon $from, Carbon $to, float $threshold): ?array
    {
        $m = $this->match->build((string) $project->tenant_id, (string) $project->id, $from, $to, ['internal' => true]);
        $worst = null;
        foreach ((array) ($m['platforms'] ?? []) as $p) {
            $share = $p['campaigns'] === [] ? null : $this->claimShare($p);
            if ($share !== null && $share >= $threshold && ($worst === null || $share > $worst['share'])) {
                $worst = ['platform' => $p['platform'], 'share' => $share];
            }
        }
        if ($worst === null) {
            return null;
        }
        $pct = (int) round($worst['share'] * 100);

        return $this->breach($project, 'ga4:gap:'.$worst['platform'], 'Platform claims exceed the website · '.$pct.'%', 'ادّعاء المنصة يفوق الموقع · '.$pct.'%',
            ucfirst($worst['platform']).' claimed '.$pct.'% more conversions than the website credited to it.', 'ادّعت '.$worst['platform'].' تحويلات أكثر بنسبة '.$pct.'% مما نسبه الموقع لها.',
            ['platform' => $worst['platform'], 'share' => $worst['share']]);
    }

    /** @param  array<string, mixed>  $p */
    private function claimShare(array $p): ?float
    {
        $claimed = $p['claimed']['conversions'] ?? null;
        $ga4 = $p['ga4']['purchases'] ?? 0.0;

        return $claimed === null || $claimed <= 0 ? null : ($claimed - $ga4) / $claimed;
    }

    /** @return array<string, mixed>|null */
    private function trackingLoss(Project $project, Carbon $from, Carbon $to, float $threshold): ?array
    {
        $k = $this->kpi($project, $from, $to, 'sessions');
        if ($k === null || $k['previous'] === null || $k['previous'] <= 0 || $k['current'] === null) {
            return null;
        }
        $m = $this->match->build((string) $project->tenant_id, (string) $project->id, $from, $to, ['internal' => true]);
        // Platforms are still reporting activity (clicks or conversions claimed) while GA4 saw almost nothing.
        $active = false;
        foreach ((array) ($m['platforms'] ?? []) as $p) {
            $active = $active || (($p['claimed']['clicks'] ?? 0) > 0) || (($p['claimed']['conversions'] ?? 0) > 0) || (($p['claimed']['spend_usd'] ?? 0) > 0);
        }
        $drop = 1 - $k['current'] / $k['previous'];
        if (! $active || $drop < $threshold) {
            return null;
        }

        return $this->breach($project, 'ga4:tracking', 'Website visits fell while ads kept spending', 'زيارات الموقع نزلت والإعلانات مكمّلة صرف',
            'Website sessions fell '.(int) round($drop * 100).'% while the platforms still report activity.', 'انخفضت زيارات الموقع '.(int) round($drop * 100).'% بينما تواصل المنصات تسجيل نشاط.',
            ['sessions' => $k['current'], 'previous' => $k['previous'], 'drop' => round($drop, 4)], 'critical');
    }

    /** @return array<string, mixed>|null */
    private function unmatchedSpend(Project $project, Carbon $from, Carbon $to, float $threshold): ?array
    {
        $m = $this->match->build((string) $project->tenant_id, (string) $project->id, $from, $to, ['internal' => true]);
        $total = 0.0;
        $unmatched = 0.0;
        foreach ((array) ($m['platforms'] ?? []) as $p) {
            foreach ($p['campaigns'] as $c) {
                if ($c['spend_usd'] === null) {
                    continue; // a campaign whose spend is withheld is outside the share, in numerator and denominator alike
                }
                $total += $c['spend_usd'];
                $unmatched += $c['confidence'] === 'unmatched' ? $c['spend_usd'] : 0.0;
            }
        }
        if ($total <= 0 || $unmatched / $total < $threshold) {
            return null;
        }
        $share = round($unmatched / $total, 4);

        return $this->breach($project, 'ga4:unmatched', 'Clicks outside tracking · '.(int) round($share * 100).'%', 'نقرات خارج التتبع · '.(int) round($share * 100).'%',
            (int) round($share * 100).'% of ad spend is in campaigns whose clicks reached no measured visit (consent, blockers, untagged links).', (int) round($share * 100).'% من الإنفاق في حملات لم تصل نقراتها إلى زيارة مقاسة (الموافقة، مانعات الإعلانات، روابط دون وسم).',
            ['share' => $share, 'campaigns' => $m['unmatched']['campaigns'] ?? 0]);
    }

    /** @return array{current: ?float, previous: ?float, change_pct: ?float}|null */
    private function kpi(Project $project, Carbon $from, Carbon $to, string $key): ?array
    {
        $r = $this->store->build((string) $project->tenant_id, (string) $project->id, $from, $to, []);
        if (($r['state'] ?? null) !== 'ready') {
            return null;
        }
        foreach ((array) $r['kpis'] as $k) {
            if (($k['key'] ?? null) === $key) {
                return ['current' => $k['current'], 'previous' => $k['previous'], 'change_pct' => $k['change_pct']];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function breach(Project $project, string $entity, string $title, string $titleAr, string $message, string $messageAr, array $context, ?string $severity = null): array
    {
        return array_filter([
            'entity_type' => Project::class,
            'entity_id' => (string) $project->id.'|'.$entity,
            'project_id' => (string) $project->id,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'context' => $context + ['title_ar' => $titleAr, 'message_ar' => $messageAr, 'provider' => 'ga4', 'basis' => 'ga4'],
        ], static fn ($v): bool => $v !== null);
    }
}
