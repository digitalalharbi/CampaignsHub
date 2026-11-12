<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Reports\Services\ReportObjectiveLens;

/**
 * DIGESTS-VISUAL-V2-001 — one alert, as the mail draws it: the words of `AlertEventText`, the PROBLEM drawn, one button.
 *
 * `AlertEventText::compose()` stays the single source of the words. This adds what only a picture says (before → now bars,
 * spend against plan, a gauge against a limit), sends the button to the exact screen for the rule (leads, tracking, launch,
 * the platform's connection), and ranks a bundle by what matters most so three alerts are one mail in priority order.
 */
final class AlertMailItem
{
    private const SEVERITY = ['critical' => 0, 'warning' => 1, 'positive' => 2, 'info' => 3];

    /**
     * @param  array<string,mixed>  $item  an alert outbox item
     * @return array<string,mixed>
     */
    public static function build(array $item, bool $ar): array
    {
        // A website alert names no ad platform: its `provider` key is dropped before the words are composed.
        $websiteOwn = (($item['context']['provider'] ?? '') === 'ga'.'4') || str_starts_with((string) ($item['rule_type'] ?? ''), 'ga'.'4_');
        if ($websiteOwn) {
            unset($item['context']['provider']);
        }
        $c = AlertEventText::compose($item, $ar);
        $type = (string) ($item['rule_type'] ?? $item['notification_type'] ?? '');
        $ctx = (array) ($item['context'] ?? []);
        $projectId = isset($item['project_id']) && $item['project_id'] !== '' ? (string) $item['project_id'] : null;
        $recovered = str_starts_with((string) ($item['triggered_at'] ?? ''), 'recovered:');

        // DIGESTS-OBJECTIVE-KPIS-001 — the alert speaks the campaign's OBJECTIVE: a cost per lead on a lead campaign, a cost per order on a sales one,
        // and a return-on-spend alert on a campaign that was never bought for sales says so instead of judging it on ROAS.
        if (($item['entity_type'] ?? '') === UnifiedCampaign::class && ($item['entity_id'] ?? '') !== '' && ! $recovered) {
            $campaign = UnifiedCampaign::withoutGlobalScopes()->find((string) $item['entity_id']);
            $group = $campaign === null ? null : ReportObjectiveLens::groupOf((string) $campaign->objective);
            $c = self::forObjective($c, $type, $group, $ar);
        }

        $viz = $recovered || ! empty($c['no_viz']) ? null : self::viz($type, $ctx, $ar);
        $target = self::target($type, $projectId, $ar);
        if ($target !== null && ! $recovered && ($c['secondary'] ?? '') === '') {
            $c['url'] = $target[0];
            $c['button'] = $target[1];
        }

        // The drawing leads; the figures (amounts, currency, the limit, the date of the last figures) follow it as one small line.
        // The source is «الموقع» / «Website», never the analytics vendor's key: the website's own alerts carry no platform name at all.
        if ($websiteOwn) {
            $c['context'] = trim((string) $c['context'].' · '.($ar ? 'الموقع' : 'Website'), ' ·');
        }

        // DIGESTS-CADENCE-BUDGETS-001 — every card in the approved grammar: a lead count is drawn as blocks, and a finding with no
        // figure to draw shows the stages it is about (`AlertVisual::present()`), never an empty card or a paragraph.
        if ($viz === null && ! $recovered && str_starts_with($type, 'lead_') && is_numeric($ctx['count'] ?? null)) {
            $viz = AlertVisual::count((int) $ctx['count'], $ar ? 'مهتم' : 'leads', 'watch');
        }

        return AlertVisual::present(array_merge($c, ['viz' => $viz, 'rule' => $recovered ? 'recovered' : $type, 'ctx' => $ctx]), $ar);
    }

    /**
     * @param  array<string,mixed>  $c
     * @return array<string,mixed>
     */
    public static function forObjective(array $c, string $type, ?string $group, bool $ar): array
    {
        if ($group === null) {
            return $c;
        }
        $names = [
            'leads' => ['تكلفة العميل', 'Cost per lead'], 'sales' => ['تكلفة الطلب', 'Cost per order'], 'app_installs' => ['تكلفة التثبيت', 'Cost per install'],
        ];
        if ($type === 'cpa_increase' && isset($names[$group])) {
            $c['title'] = str_replace($ar ? 'تكلفة النتيجة' : 'Cost per result', $names[$group][$ar ? 0 : 1], (string) $c['title']);
        }
        if ($type === 'roas_drop' && $group !== 'sales') {
            $own = ['awareness' => ['الوصول والظهور وتكلفة الألف', 'reach, impressions and CPM'], 'traffic' => ['النقرات وتكلفة النقرة', 'clicks and cost per click'], 'leads' => ['العملاء وتكلفة العميل', 'leads and cost per lead'],
                'video' => ['المشاهدات ومعدل الإكمال', 'views and completion rate'], 'app_installs' => ['التثبيتات وتكلفة التثبيت', 'installs and cost per install']][$group] ?? null;
            if ($own !== null) {
                $c['detail'] = $ar ? 'هدف هذي الحملة مو المبيعات، فالعائد مو مقياسها.' : 'This campaign is not bought for sales, so return is not its measure.';
                $c['action'] = ($ar ? 'قيّمها على: ' : 'Judge it on: ').$own[$ar ? 0 : 1];
                $c['no_viz'] = true; // the return bars would judge it on the very figure it was not bought for
            }
        }

        return $c;
    }

    /**
     * Most urgent first: severity, then the money the finding is about, then name — never the order the sweep found them in.
     *
     * @param  list<array<string,mixed>>  $items  built by {@see build()}
     * @return list<array<string,mixed>>
     */
    public static function rank(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => (self::SEVERITY[$a['severity']] ?? 9) <=> (self::SEVERITY[$b['severity']] ?? 9)
            ?: strcmp((string) ($a['context'] ?? ''), (string) ($b['context'] ?? ''))
            ?: strcmp((string) $a['title'], (string) $b['title']));

        return $items;
    }

    /** @return array<string,mixed>|null */
    public static function viz(string $type, array $ctx, bool $ar): ?array
    {
        $fmt = static fn (float $v, string $key): string => $key === 'roas' ? number_format($v, 2).'×' : number_format($v, $v >= 100 ? 0 : 2);
        $thr = isset($ctx['threshold_pct']) && is_numeric($ctx['threshold_pct']) ? (float) $ctx['threshold_pct'] : null;

        // Spend against the plan, both as shares of the BUDGET: «spent 80%, planned 48%» — never a pace ratio beside a share.
        if ($type === 'budget_risk' && isset($ctx['spend'], $ctx['budget'], $ctx['ratio']) && (float) $ctx['budget'] > 0 && (float) $ctx['ratio'] > 0 && ($ctx['basis_class'] ?? 'measured') === 'measured') {
            $used = (int) round(min(1.0, (float) $ctx['spend'] / (float) $ctx['budget']) * 100);
            $plan = (int) round(min(100, $used / (float) $ctx['ratio']));

            return ['type' => 'pace', 'used' => $used, 'plan' => $plan, 'used_label' => ($ar ? 'الإنفاق' : 'Spent').' '.AlertEventText::ltr($used.'%'), 'plan_label' => ($ar ? 'الخطة' : 'Plan').' '.AlertEventText::ltr($plan.'%')];
        }
        if ($type === 'budget_risk' && isset($ctx['consumed'], $ctx['limit']) && (float) $ctx['limit'] > 0) {
            $used = (int) round(min(1.0, (float) $ctx['consumed'] / (float) $ctx['limit']) * 100);
            $cut = isset($ctx['threshold']) ? (int) round((float) $ctx['threshold'] > 1 ? (float) $ctx['threshold'] : (float) $ctx['threshold'] * 100) : 100;

            return ['type' => 'pace', 'used' => $used, 'plan' => $cut, 'used_label' => ($ar ? 'الإنفاق' : 'Spent').' '.AlertEventText::ltr($used.'%'), 'plan_label' => ($ar ? 'الحد' : 'Limit').' '.AlertEventText::ltr($cut.'%')];
        }
        foreach (['cpa', 'cpl', 'roas', 'ctr', 'cpc'] as $key) {
            if (isset($ctx[$key.'_previous'], $ctx[$key.'_current']) && is_numeric($ctx[$key.'_previous']) && is_numeric($ctx[$key.'_current'])) {
                return self::beforeNow((float) $ctx[$key.'_previous'], (float) $ctx[$key.'_current'], $fmt($ctx[$key.'_previous'] + 0.0, $key), $fmt($ctx[$key.'_current'] + 0.0, $key), $ar, $thr);
            }
        }
        $pt = (array) (($ctx['points'] ?? [])[0] ?? []);
        if (isset($pt['baseline'], $pt['value'])) {
            return self::beforeNow((float) $pt['baseline'], (float) $pt['value'], number_format((float) $pt['baseline'], 2), number_format((float) $pt['value'], 2), $ar, null);
        }
        if (isset($ctx['previous'], $ctx['current']) && is_numeric($ctx['previous']) && is_numeric($ctx['current'])) {
            return self::beforeNow((float) $ctx['previous'], (float) $ctx['current'], number_format((float) $ctx['previous'], 0), number_format((float) $ctx['current'], 0), $ar, null);
        }
        if ($type === 'ga4_tracking_loss' && isset($ctx['previous'], $ctx['sessions'])) {
            return self::beforeNow((float) $ctx['previous'], (float) $ctx['sessions'], number_format((float) $ctx['previous']), number_format((float) $ctx['sessions']), $ar, null);
        }
        if (in_array($type, ['ga4_unmatched_spend', 'ga4_attribution_gap'], true) && isset($ctx['share'])) {
            $s = (int) round(min(1.0, (float) $ctx['share']) * 100);

            return ['type' => 'pace', 'used' => $s, 'plan' => 0, 'used_label' => ($ar ? 'بدون قياس' : 'Unmeasured').' '.AlertEventText::ltr($s.'%'), 'plan_label' => ($ar ? 'المقيس' : 'Measured').' '.AlertEventText::ltr((100 - $s).'%'), 'plan_value' => 100 - $s];
        }
        if (isset($ctx['spend'], $ctx['days']) && $type === 'no_results') {
            return ['type' => 'pace', 'used' => 100, 'plan' => 0, 'used_label' => ($ar ? 'الإنفاق' : 'Spend').' '.number_format((float) $ctx['spend']), 'plan_label' => ($ar ? (string) ($ctx['result_label']['ar'] ?? 'النتائج') : (string) ($ctx['result_label']['en'] ?? 'Results')).' 0'];
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function beforeNow(float $was, float $now, string $wasLabel, string $nowLabel, bool $ar, ?float $threshold): array
    {
        $max = max($was, $now, 0.0001);

        return ['type' => 'before_now', 'before' => max(6, (int) round($was / $max * 100)), 'now' => max(6, (int) round($now / $max * 100)), 'before_label' => $wasLabel, 'now_label' => $nowLabel, 'threshold' => $threshold];
    }

    /** @return array{0: string, 1: string}|null url and button label for a rule that has its own screen */
    private static function target(string $type, ?string $projectId, bool $ar): ?array
    {
        $p = $projectId === null ? '' : '?project='.rawurlencode($projectId);

        return match (true) {
            in_array($type, ['lead_unassigned'], true) => [MailLinks::to('/app/leads'.$p), $ar ? 'افتح المهتمين' : 'Open the leads'],
            in_array($type, ['lead_no_contact', 'lead_follow_up_overdue'], true) => [MailLinks::to('/app/leads/workspace'.$p), $ar ? 'افتح المتابعات' : 'Open follow-ups'],
            in_array($type, ['tracking_health', 'ga4_tracking_loss', 'ga4_unmatched_spend'], true) => [MailLinks::to('/app/forms'.$p), $ar ? 'افتح التتبع' : 'Open tracking health'],
            $type === 'launch_advance' => [MailLinks::to('/app/launch'.$p), $ar ? 'افتح الإطلاق' : 'Open the launch page'],
            str_starts_with($type, 'ga4_') && $projectId !== null => [MailDeepLinks::analytics($projectId, 'performance'), $ar ? 'افتح الأداء' : 'Open performance'],
            $type === 'report_failed' => [MailLinks::to('/app/reports'), $ar ? 'افتح التقارير' : 'Open reports'],
            in_array($type, ['security', 'sample_alert'], true) => [MailLinks::to($type === 'security' ? '/app/account/security' : '/app/account/notifications'), $type === 'security' ? ($ar ? 'افتح الأمان' : 'Open security') : ($ar ? 'افتح الإشعارات' : 'Open notifications')],
            // DIGESTS-CADENCE-BUDGETS-001 — an alert about no single campaign still lands on its exact screen, never the generic alert page.
            in_array($type, ['budget_risk'], true) && $projectId !== null => [MailDeepLinks::analytics($projectId, 'budget'), $ar ? 'افتح الميزانية' : 'Open the budget'],
            in_array($type, ['cpa_increase', 'cpl_increase', 'roas_drop', 'no_results'], true) && $projectId !== null => [MailDeepLinks::platforms($projectId), $ar ? 'افتح المنصات' : 'Open the platforms'],
            in_array($type, ['sync_failure', 'sync_outage', 'measurement_outage', 'token_expiry'], true) => [MailDeepLinks::integrations($projectId), $ar ? 'افتح الربط' : 'Open the connections page'],
            in_array($type, ['performance', 'metric_anomaly'], true) && $projectId !== null => [MailDeepLinks::analytics($projectId, 'performance'), $ar ? 'افتح الأداء' : 'Open performance'],
            default => null,
        };
    }
}
