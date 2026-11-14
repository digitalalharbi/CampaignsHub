<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Mail\OperationalDigestMail;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Notifications\Support\AlertMailItem;
use App\Domains\Notifications\Support\AlertRuleCopy;
use App\Domains\Notifications\Support\DigestDemo;
use App\Domains\Notifications\Support\DigestStatus;
use App\Domains\Notifications\Support\OperationalDigestModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DIGESTS-VISUAL-V2-001 — the status rule, the global ranking, the inventory guarantee, the alert wording.
 * Arrays only: no database, nothing is sent.
 */
final class DigestsVisualV2Test extends TestCase
{
    /** @return array<string, array{0: array<string,mixed>, 1: string, 2: ?string}> */
    public static function cells(): array
    {
        return [
            'sync failed' => [['source' => 'failed', 'spend' => 100], 'bad', 'sync_failed'],
            'spend with no results' => [['spend' => 500, 'results_key' => 'leads', 'results' => 0, 'results_reported' => true], 'bad', 'no_results'],
            'no results but not reported is not a verdict' => [['spend' => 500, 'results_key' => 'leads', 'results' => 0, 'results_reported' => false], 'ok', null],
            'pace hot' => [['spend' => 500, 'pace' => 1.67], 'bad', 'pace_hot'],
            'pace slow' => [['spend' => 500, 'pace' => 0.4], 'watch', 'pace_slow'],
            'cost +45% is bad' => [['spend' => 500, 'headline_now' => 145, 'headline_prev' => 100, 'headline_lower_is_better' => true], 'bad', 'worse'],
            'cost +25% is watch' => [['spend' => 500, 'headline_now' => 125, 'headline_prev' => 100, 'headline_lower_is_better' => true], 'watch', 'worse'],
            'return -45% is bad (a rate worsens when it falls)' => [['spend' => 500, 'headline_now' => 2.2, 'headline_prev' => 4.0, 'headline_lower_is_better' => false], 'bad', 'worse'],
            'cost FALLING is not worse' => [['spend' => 500, 'headline_now' => 60, 'headline_prev' => 100, 'headline_lower_is_better' => true], 'ok', null],
            'stale' => [['spend' => 500, 'source' => 'stale'], 'watch', 'stale'],
            'results -35%' => [['spend' => 500, 'results' => 65, 'results_prev' => 100], 'watch', 'results_down'],
            'open P1/P2 alert' => [['spend' => 500, 'alert' => 'bad'], 'bad', 'alert_bad'],
            'open P1/P2 alert marks even a cell with no data' => [['spend' => null, 'alert' => 'bad'], 'bad', 'alert_bad'],
            'no data outranks an open P3 alert (the board does the same)' => [['spend' => null, 'alert' => 'watch'], 'none', 'no_data'],
            'open P3 / snoozed alert' => [['spend' => 500, 'alert' => 'watch'], 'watch', 'alert_watch'],
            'no spend figure is no data, never zero' => [['spend' => null], 'none', 'no_data'],
            'spend withheld is no data' => [['spend' => 900, 'spend_withheld' => true], 'none', 'no_data'],
            'all fine' => [['spend' => 500, 'pace' => 1.0, 'headline_now' => 100, 'headline_prev' => 100, 'headline_lower_is_better' => true], 'ok', null],
        ];
    }

    /** @param  array<string,mixed>  $in */
    #[DataProvider('cells')]
    public function test_the_cell_rule(array $in, string $state, ?string $reason): void
    {
        $r = DigestStatus::cell($in);
        $this->assertSame($state, $r['state']);
        $this->assertSame($reason, $r['reason']);
    }

    public function test_the_alert_overlay_is_the_boards_rule(): void
    {
        $this->assertSame('bad', DigestStatus::alertState([['priority' => 'P2', 'snoozed' => false]]));
        $this->assertSame('watch', DigestStatus::alertState([['priority' => 'P1', 'snoozed' => true]]), 'a snoozed P1 is a watch');
        $this->assertSame('watch', DigestStatus::alertState([['priority' => 'P3', 'snoozed' => false]]));
        $this->assertSame('bad', DigestStatus::alertState([['priority' => 'P3'], ['priority' => 'P2']]));
        $this->assertNull(DigestStatus::alertState([]));
    }

    public function test_the_project_is_its_worst_cell_and_none_only_when_nothing_has_figures(): void
    {
        $this->assertSame('bad', DigestStatus::project(['ok', 'watch', 'bad']));
        $this->assertSame('watch', DigestStatus::project(['ok', 'watch', 'none']));
        $this->assertSame('ok', DigestStatus::project(['ok', 'none']));
        $this->assertSame('none', DigestStatus::project(['none', 'none']));
        $this->assertSame('none', DigestStatus::project([]));
        $this->assertSame('bad', DigestStatus::project(['ok'], ['critical']));
        $this->assertSame('watch', DigestStatus::project(['ok'], ['warning']));
    }

    public function test_every_project_is_in_the_matrix_exactly_once_and_coverage_adds_up(): void
    {
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), 'ar', 'daily');
        $ids = array_column($m['heatmap']['rows'], 'id');
        $this->assertCount(30, $ids);
        $this->assertCount(30, array_unique($ids));
        $this->assertSame(30, $m['coverage']['total']);
        $this->assertSame(30, $m['coverage']['checked'] + count($m['coverage']['unchecked']));
        $this->assertSame(array_sum($m['health']), 30);
        // The projects with no figures at all are present as «–», named in the unchecked list with their reason.
        $none = array_filter($m['heatmap']['rows'], static fn (array $r): bool => $r['state'] === 'none');
        $this->assertNotEmpty($none);
        $names = array_column($m['coverage']['unchecked'], 'name');
        foreach ($none as $r) {
            $this->assertContains($r['name'], $names);
        }
        $html = (new OperationalDigestMail($m, 'ar', 'م', 'daily', 'portfolio'))->render();
        $this->assertSame(30, substr_count($html, 'data-block="portfolio-row"'));
        // DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — coverage now lives in «ملخص الربط».
        $this->assertStringContainsString('أرقامها محدّثة', $html);
    }

    public function test_the_ranking_is_global_and_nothing_is_dropped_silently(): void
    {
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), 'ar', 'daily');
        $issues = $m['issues'];
        $this->assertGreaterThan(OperationalDigestModel::TOP_ISSUES, count($issues));
        $sorted = $issues;
        usort($sorted, [DigestStatus::class, 'compare']);
        $this->assertSame($issues, $sorted, 'one list in the rule order, not grouped by project');
        $rank = array_map(static fn (array $i): int => DigestStatus::STATE_RANK[$i['state']], $issues);
        $this->assertSame($rank, (function (array $r): array {
            sort($r);

            return $r;
        })($rank), 'every problem before every watch');
        $this->assertCount(OperationalDigestModel::TOP_ISSUES, $m['issues_shown']);
        $this->assertSame(count($issues) - OperationalDigestModel::TOP_ISSUES, $m['issues_more']);
        $html = (new OperationalDigestMail($m, 'ar', 'م', 'daily', 'portfolio'))->render();
        $this->assertStringContainsString('+'.$m['issues_more'], $html);
        $this->assertSame(OperationalDigestModel::TOP_ISSUES, substr_count($html, 'data-block="issue"'));
        // Every issue's cell is a ! or ✕ in the matrix, so the capped detail hides nothing.
        $bad = array_sum(array_map(static fn (array $r): int => count(array_filter($r['cells'], static fn ($c): bool => $c !== null && in_array($c['state'], ['bad', 'watch'], true))), $m['heatmap']['rows']));
        $cellIssues = count(array_filter($issues, static fn (array $i): bool => $i['kind'] === 'cell'));
        $this->assertSame($cellIssues, $bad);
    }

    public function test_the_weekly_differs_from_the_daily_and_both_are_visual_not_prose(): void
    {
        $d = DigestDemo::payload(30, 6);
        $daily = (new OperationalDigestMail(OperationalDigestModel::portfolio($d, 'ar', 'daily'), 'ar', 'م', 'daily', 'portfolio'))->render();
        $weekly = (new OperationalDigestMail(OperationalDigestModel::portfolio($d, 'ar', 'weekly'), 'ar', 'م', 'weekly', 'portfolio'))->render();
        // DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — best and weakest per objective on EVERY rhythm (yesterday against the day before).
        $this->assertStringContainsString('data-block="movers"', $daily);
        $this->assertStringContainsString('data-block="movers"', $weekly);
        foreach ([$daily, $weekly] as $html) {
            $this->assertStringNotContainsString('هذا ملخص', $html, 'no filler intro');
            $this->assertStringNotContainsString('GA4', $html);
            $this->assertStringNotContainsString('Google Analytics', $html);
        }
        $project = OperationalDigestModel::project($d['projects'][3], 'ar', 7);
        $pw = (new OperationalDigestMail($project, 'ar', 'م', 'weekly', 'project'))->render();
        $pd = (new OperationalDigestMail($project, 'ar', 'م', 'daily', 'project'))->render();
        $this->assertGreaterThan(substr_count($pd, 'font-size:9px'), substr_count($pw, 'font-size:9px'), 'the weekly labels its 7 bars with weekday letters');
        $this->assertStringContainsString('data-block="platform-strip"', $pd);
    }

    public function test_a_per_project_ledger_key_fits_the_column(): void
    {
        $key = DigestDispatcher::projectKey('0192f3a4-5b6c-7d8e-9f01-23456789abcd');
        $this->assertSame(12, strlen($key));
        $this->assertLessThanOrEqual(24, strlen('2026-11-09~'.$key));
        $this->assertLessThanOrEqual(24, strlen('2026-W45~'.$key));
    }

    /** @return array<string, array{0: string, 1: array<string,mixed>}> */
    public static function ruleTypes(): array
    {
        return [
            'ga4_sales_drop' => ['ga4_sales_drop', ['key' => 'sales', 'current' => 41200, 'previous' => 60600, 'change_pct' => -32.0]],
            'ga4_bounce_spike' => ['ga4_bounce_spike', ['key' => 'bounce_rate', 'current' => 0.7, 'previous' => 0.5, 'change_pct' => 40.0]],
            'ga4_cvr_drop' => ['ga4_cvr_drop', ['key' => 'conversion_rate', 'current' => 0.01, 'previous' => 0.02, 'change_pct' => -50.0]],
            'ga4_aov_shift' => ['ga4_aov_shift', ['key' => 'aov', 'current' => 90, 'previous' => 120, 'change_pct' => -25.0]],
            'ga4_attribution_gap' => ['ga4_attribution_gap', ['platform' => 'meta', 'share' => 0.4]],
            'ga4_tracking_loss' => ['ga4_tracking_loss', ['sessions' => 3600, 'previous' => 10000, 'drop' => 0.64]],
            'ga4_unmatched_spend' => ['ga4_unmatched_spend', ['share' => 0.35, 'campaigns' => 4]],
            'lead_unassigned' => ['lead_unassigned', ['count' => 7, 'minutes' => 60]],
            'lead_no_contact' => ['lead_no_contact', ['count' => 3, 'minutes' => 240]],
            'lead_follow_up_overdue' => ['lead_follow_up_overdue', ['count' => 5]],
            'tracking_health' => ['tracking_health', ['title_ar' => 'نموذج لا يسجّل التحويل', 'message_ar' => 'لم يصل أي حدث خلال 3 أيام.']],
            'launch_advance' => ['launch_advance', ['from' => 'تعلّم', 'to' => 'تحسين', 'title_ar' => 'حساب ميتا صار مؤهلًا لمرحلة أفضل']],
            'performance' => ['performance', ['title_ar' => 'تغيّر في الأداء']],
            'security' => ['security', []],
            'sample_alert' => ['sample_alert', []],
            'report_failed' => ['report_failed', []],
        ];
    }

    /** @param  array<string,mixed>  $ctx */
    #[DataProvider('ruleTypes')]
    public function test_no_rule_type_falls_back_to_the_generic_arabic_line(string $type, array $ctx): void
    {
        $item = ['event_id' => 'E', 'project_id' => '00000000-0000-4000-8000-000000000001', 'project_name' => 'متجر', 'triggered_at' => '2026-11-09T09:40:00+03:00', 'entity_type' => '', 'entity_id' => '', 'rule_type' => $type, 'severity' => 'warning', 'context' => $ctx];
        foreach ([true, false] as $ar) {
            $c = AlertMailItem::build($item, $ar);
            $this->assertStringNotContainsString($ar ? 'تنبيه جديد يحتاج مراجعتك' : 'A new alert needs your review', $c['title']);
            $this->assertStringNotContainsString($ar ? 'افتح التنبيه لترى التفاصيل' : 'Open the alert for the details', $c['detail']);
            $this->assertNotSame('', $c['action']);
            $this->assertNotSame('', $c['url']);
            $this->assertStringNotContainsString('GA4', $c['title'].$c['detail'].$c['context']);
        }
        $this->assertContains($type, [...AlertRuleCopy::TYPES, 'x']);
    }

    public function test_the_budget_card_no_longer_prints_a_pace_beside_a_spend_share(): void
    {
        $item = ['event_id' => 'E', 'project_id' => null, 'project_name' => 'م', 'triggered_at' => '2026-11-09T09:40:00+03:00', 'entity_type' => '', 'entity_id' => '', 'rule_type' => 'budget_risk', 'severity' => 'critical',
            'context' => ['basis_class' => 'measured', 'spend' => 8000.0, 'budget' => 10000.0, 'ratio' => 1.67, 'budget_currency' => 'SAR', 'provider' => 'meta']];
        $html = (new AlertBundleMail([AlertMailItem::build($item, true)], 'ar', 'م'))->render();
        $this->assertStringContainsString('80%', $html);
        $this->assertStringContainsString('48%', $html, 'the plan, as a share of the same budget');
        $this->assertStringNotContainsString('167', $html);
        $this->assertStringNotContainsString('go to the platform', $html);
    }

    public function test_dates_inside_arabic_sentences_are_isolated_left_to_right(): void
    {
        $item = ['event_id' => 'E', 'project_id' => null, 'project_name' => 'م', 'triggered_at' => '2026-11-09T09:40:00+03:00', 'entity_type' => '', 'entity_id' => '', 'rule_type' => 'token_expiry', 'severity' => 'warning', 'context' => ['provider' => 'meta', 'expires_at' => '2026-11-14T00:00:00Z']];
        $c = AlertEventText::compose($item, true);
        $this->assertStringContainsString(AlertEventText::ltr('2026-11-14'), $c['numbers']);
        $refused = AlertEventText::compose(['rule_type' => 'token_expiry', 'context' => ['provider' => 'meta']] + $item, true);
        $this->assertStringNotContainsString('بعد الانتهاء', $refused['detail'], 'an already-refused connection does not «expire soon»');
    }

    public function test_three_or_more_alerts_are_one_ranked_mail_not_n_mails(): void
    {
        $mk = static fn (string $sev, string $title): array => ['severity' => $sev, 'title' => $title, 'detail' => 'd', 'context' => 'م', 'numbers' => '', 'action' => 'a', 'url' => 'https://x', 'button' => 'b', 'secondary' => '', 'viz' => null, 'figures' => null];
        $ranked = AlertMailItem::rank([$mk('info', 'c'), $mk('warning', 'b'), $mk('critical', 'a'), $mk('warning', 'a2')]);
        $this->assertSame(['critical', 'warning', 'warning', 'info'], array_column($ranked, 'severity'));
        $html = (new AlertBundleMail($ranked, 'ar', 'م'))->render();
        $this->assertSame(4, substr_count($html, 'data-block="steps"'));
        $this->assertStringContainsString('data-block="severity-strip"', $html);
    }
}
