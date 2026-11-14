<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Mail\OperationalDigestMail;
use App\Domains\Notifications\Support\AlertMailItem;
use App\Domains\Notifications\Support\DigestDemo;
use App\Domains\Notifications\Support\DigestStatus;
use App\Domains\Notifications\Support\MailDisclaimer;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Support\AdPlatforms;
use Tests\TestCase;

/**
 * DIGESTS-OBJECTIVE-KPIS-001 — tiles in ONE row, KPIs per OBJECTIVE group, one voice, the closing note, and parity with the report.
 */
final class DigestsObjectiveKpisTest extends TestCase
{
    private function visible(string $html): string
    {
        return html_entity_decode(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<(style|head)[^>]*>.*?</\1>#s', '', $html)))));
    }

    public function test_tiles_are_cells_of_one_row_with_the_label_above_the_number_and_never_stack(): void
    {
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(12, 6), 'ar', 'daily');
        $html = (new OperationalDigestMail($m, 'ar', 'م', 'daily', 'portfolio'))->render();
        // The four state tiles: one <tr>, four cells, each with its label before its number.
        $row = substr($html, (int) strpos($html, 'data-block="counts"'), 3000);
        $this->assertSame(4, substr_count($row, 'data-count='));
        $this->assertLessThan(strpos($row, 'dir="ltr">'), strpos($row, 'مشكلة'), 'label above, number under');
        // No class or style that collapses a tile on a phone.
        $this->assertDoesNotMatchRegularExpression('/class="ch-kpi[ "]/', $html);
        $this->assertStringContainsString('width="33%"', $html, 'three tiles to a row, each a third of the row');
        $this->assertStringNotContainsString('data-block="tiles" style="display:block', $html);
        // Every tile row is a table with all its cells in one <tr>.
        preg_match_all('#<table[^>]*data-block="tiles"[^>]*>\s*<tr>(.*?)</tr>\s*</table>#s', $html, $rows);
        $this->assertNotEmpty($rows[1]);
        foreach ($rows[1] as $cells) {
            $this->assertLessThanOrEqual(3, preg_match_all('/data-tile=/', $cells));
        }
    }

    public function test_a_mixed_project_has_one_tile_row_per_objective_and_no_roas_on_awareness(): void
    {
        $d = DigestDemo::payload(8, 6);
        $mixed = array_values(array_filter($d['projects'], static fn (array $b): bool => count($b['groups']) > 1))[0];
        $m = OperationalDigestModel::project($mixed, 'ar', 7);
        $this->assertTrue($m['mixed']);
        $objectives = array_column($m['groups'], 'objective');
        $this->assertContains('awareness', $objectives);
        $this->assertCount(2, $objectives);
        $aw = $m['groups'][array_search('awareness', $objectives, true)];
        $keys = array_column($aw['kpis'], 'key');
        $this->assertSame(['spend', 'reach', 'impressions', 'frequency', 'cpm'], $keys);
        $this->assertNotContains('roas', $keys);
        foreach ($aw['platforms'] as $pf) {
            $this->assertNotContains('roas', array_column($pf['figures'], 'key'), 'the platform row shows the objective\'s own figure');
        }
        // The project's own tile row never blends results: it is the total every objective shares (DIGESTS-YESTERDAY-BY-OBJECTIVE-001).
        $this->assertSame(['spend', 'impressions', 'clicks'], array_column($m['kpis'], 'key'));
        $html = (new OperationalDigestMail($m, 'ar', 'م', 'daily', 'project'))->render();
        $this->assertSame(2, substr_count($html, 'data-block="group"'));
        $awHtml = substr($html, (int) strpos($html, 'data-objective="awareness"'));
        // The group ends at the next group or, since DIGESTS-YESTERDAY-BY-OBJECTIVE-001 put the groups first, at the decisions.
        $ends = array_filter([strpos($awHtml, 'data-block="group"', 10), strpos($awHtml, 'يحتاج قرارك')]);
        $awHtml = substr($awHtml, 0, $ends === [] ? null : (int) min($ends));
        $this->assertStringNotContainsString('العائد على الإنفاق', $awHtml);
    }

    public function test_the_portfolio_has_a_tile_row_per_objective_and_never_a_figure_across_unlike_ones(): void
    {
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), 'ar', 'daily');
        $by = array_column($m['groups'], null, 'objective');
        $this->assertArrayHasKey('sales', $by);
        $this->assertArrayHasKey('awareness', $by);
        $this->assertContains('roas', array_column($by['sales']['kpis'], 'key'));
        foreach (['awareness', 'leads', 'traffic'] as $o) {
            $this->assertNotContains('roas', array_column($by[$o]['kpis'], 'key'));
        }
        $this->assertSame(['spend'], array_column($m['kpis'], 'key'), 'no generic «results» total across objectives');
        // A sales group's ratio is recomputed from its own sums.
        $revenue = 0.0;
        $spend = 0.0;
        foreach ($m['projects'] as $pr) {
            foreach ($pr['groups'] as $g) {
                if ($g['objective'] === 'sales') {
                    $revenue += (float) ($g['raw']['totals']['revenue'] ?? 0);
                    $spend += (float) ($g['raw']['totals']['spend'] ?? 0);
                }
            }
        }
        $roas = array_values(array_filter($by['sales']['kpis'], static fn (array $k): bool => $k['key'] === 'roas'))[0];
        $this->assertSame(number_format($revenue / $spend, 2).'×', $roas['value']);
    }

    public function test_a_cell_in_a_mixed_project_is_judged_per_objective(): void
    {
        // The same low return is a problem for sales and nothing for awareness (which has no ROAS to judge).
        $sales = DigestStatus::cell(['spend' => 500, 'headline_now' => 2.0, 'headline_prev' => 4.0, 'headline_lower_is_better' => false]);
        $awareness = DigestStatus::cell(['spend' => 500, 'headline_now' => null, 'headline_prev' => null, 'headline_lower_is_better' => true]);
        $this->assertSame('bad', $sales['state']);
        $this->assertSame('ok', $awareness['state']);
    }

    public function test_a_missing_metric_is_a_dash_never_zero(): void
    {
        $d = DigestDemo::payload(4, 4);
        $d['projects'][1]['groups'][0]['totals']['aov'] = null;
        $m = OperationalDigestModel::project($d['projects'][1], 'ar', 7);
        foreach ($m['groups'] as $g) {
            foreach ($g['kpis'] as $k) {
                if ($k['missing']) {
                    $this->assertNotSame('0', $k['value']);
                }
            }
        }
        $html = (new OperationalDigestMail($m, 'ar', 'م', 'daily', 'project'))->render();
        $this->assertStringContainsString('—', $html);
    }

    public function test_every_digest_and_alert_mail_carries_the_closing_note_merged_into_one_footer_box(): void
    {
        $d = DigestDemo::payload(6, 4);
        $note = config('disclaimers.sections.operational');
        $item = ['severity' => 'warning', 'title' => 't', 'detail' => 'd', 'context' => 'م', 'numbers' => '', 'action' => 'a', 'url' => 'https://x', 'button' => 'b', 'secondary' => '', 'viz' => null, 'figures' => null];
        foreach (['ar', 'en'] as $lang) {
            $mails = [];
            foreach (['daily', 'weekly'] as $kind) {
                $mails[] = new OperationalDigestMail(OperationalDigestModel::portfolio($d, $lang, $kind), $lang, 'x', $kind, 'portfolio');
                $mails[] = new OperationalDigestMail(OperationalDigestModel::project($d['projects'][0], $lang, 7), $lang, 'x', $kind, 'project');
            }
            $mails[] = new AlertBundleMail([$item], $lang, 'x');
            $mails[] = new AlertBundleMail([$item, $item, $item], $lang, 'x');
            foreach ($mails as $mail) {
                $html = $mail->render();
                $this->assertStringContainsString($note[$lang], html_entity_decode($html));
                $this->assertSame(1, substr_count($html, 'data-footer-line="note"'), 'one compact box, not two');
            }
        }
        // The results/freshness line the digest already carried is kept INSIDE the same box.
        $html = (new OperationalDigestMail(OperationalDigestModel::project($d['projects'][0], 'ar', 7), 'ar', 'x', 'daily', 'project'))->render();
        $this->assertStringContainsString('«لا بيانات»', html_entity_decode($html));
        $this->assertStringContainsString('مؤشرات استرشادية وغير مرجعية', html_entity_decode($html));
    }

    public function test_the_closing_note_can_be_overridden_and_switched_off_by_the_central_mechanism(): void
    {
        config(['disclaimers.enabled.operational' => true, 'disclaimers.sections.operational.ar' => 'نص مخصص']);
        $this->assertStringContainsString('نص مخصص', MailDisclaimer::note('ar'));
        $this->assertSame('خط', MailDisclaimer::note('ar', 'خط') === 'نص مخصص خط' ? 'خط' : 'x');
    }

    /** @return list<string> every Arabic literal of the copy layer */
    private function copyLayerStrings(): array
    {
        $files = ['AlertEventText', 'AlertRuleCopy', 'PlainFindings', 'AlertMailItem', 'OperationalDigestModel', 'DigestStatus'];
        $out = [];
        foreach ($files as $f) {
            $src = (string) file_get_contents(base_path('app/Domains/Notifications/Support/'.$f.'.php'));
            $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
            $src = (string) preg_replace('#^\s*//.*$#m', '', $src);
            preg_match_all('/(["\'])((?:(?!\1)[^\\\\]|\\\\.)*)\1/u', $src, $m);
            foreach ($m[2] as $lit) {
                if (preg_match('/\p{Arabic}/u', $lit)) {
                    $out[] = $f.': '.$lit;
                }
            }
        }
        $out[] = 'AlertEventMailer: '.(string) file_get_contents(base_path('app/Domains/Alerts/Services/AlertEventMailer.php'));

        return $out;
    }

    public function test_the_arabic_copy_is_in_one_plain_voice_with_no_stiff_phrases(): void
    {
        // Style guide: «لهجة بيضاء» — short, plain, friendly-professional. Say «سناب بدون نتائج», «الصرف زاد والنتيجة نزلت», «العائد نزل ٣٠٪»;
        // never the bureaucratic register. Each action is a short ordered step in the marketing manager's voice: «تحقق من التتبع ثم المحتوى، وأوقف الحملة إن لزم».
        $banned = ['يتطلب', 'يُنصح', 'يُنصَح', 'ينصح', 'تم رصد', 'تم اكتشاف', 'بلا نتائج', 'يلزمك', 'يرجى', 'ينبغي', 'لا يمكن مراقبتها', 'تحتاج مراجعتك', 'يحتاج مراجعتك', 'اتخذ قرارًا', 'قد تكون ناقصة', 'استهلكت الحملة'];
        $bad = [];
        foreach ($this->copyLayerStrings() as $line) {
            foreach ($banned as $w) {
                if (str_contains($line, $w)) {
                    $bad[] = $w.' ⟵ '.mb_substr($line, 0, 90);
                }
            }
        }
        $this->assertSame([], $bad, "stiff phrases in the copy layer:\n".implode("\n", $bad));
    }

    public function test_an_alert_speaks_the_campaigns_objective(): void
    {
        $c = ['title' => 'تكلفة النتيجة زادت على ميتا', 'detail' => 'x', 'action' => 'y'];
        $this->assertSame('تكلفة العميل زادت على ميتا', AlertMailItem::forObjective($c, 'cpa_increase', 'leads', true)['title']);
        $this->assertSame('تكلفة الطلب زادت على ميتا', AlertMailItem::forObjective($c, 'cpa_increase', 'sales', true)['title']);
        $aw = AlertMailItem::forObjective(['title' => 'العائد نزل على ميتا', 'detail' => 'x', 'action' => 'y'], 'roas_drop', 'awareness', true);
        $this->assertStringContainsString('مو المبيعات', $aw['detail']);
        $this->assertStringContainsString('الوصول والظهور', $aw['action']);
        $sales = AlertMailItem::forObjective(['title' => 'العائد نزل على ميتا', 'detail' => 'x', 'action' => 'y'], 'roas_drop', 'sales', true);
        $this->assertSame('x', $sales['detail'], 'a sales campaign is judged on its return as before');
    }

    // ---- parity with the report and the in-app board: one vocabulary --------------------------------------------------

    /** @return array<string, array{ar: string, en: string}> */
    private function reportLabels(): array
    {
        $src = (string) file_get_contents(base_path('../frontend/src/features/reports/live/liveMetrics.tsx'));
        preg_match_all("/^\s*([a-z_]+): \{ ar: '([^']*)', en: '([^']*)'/m", $src, $m, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $x): array => ['k' => $x[1], 'ar' => $x[2], 'en' => $x[3]], $m), null, 'k');
    }

    public function test_the_kpi_labels_are_the_reports_own(): void
    {
        $report = $this->reportLabels();
        $this->assertNotEmpty($report, 'the report label table could not be read');
        $fig = new \ReflectionClassConstant(OperationalDigestModel::class, 'FIG');
        $shared = 0;
        foreach ($fig->getValue() as $key => $spec) {
            if (isset($report[$key]) && $key !== 'ctr' || ($key === 'ctr' && isset($report['ctr']))) {
                $shared++;
                $this->assertSame($report[$key]['ar'], $spec[0], "«{$key}» is worded differently in the email and in the live report");
                $this->assertSame($report[$key]['en'], $spec[1], "«{$key}» (EN) differs from the live report");
            }
        }
        $this->assertGreaterThanOrEqual(14, $shared);
    }

    public function test_the_status_words_are_the_boards_and_the_platform_order_is_the_canonical_one(): void
    {
        $src = (string) file_get_contents(base_path('../frontend/src/features/alerts/decisionCopy.ts'));
        $words = ['healthy' => 'ok', 'watch' => 'watch', 'problem' => 'bad', 'no_data' => 'none'];
        foreach ($words as $board => $ours) {
            preg_match("/{$board}: \{ ar: '([^']*)', en: '([^']*)'/", $src, $m);
            $this->assertSame($m[1], DigestStatus::label($ours, true), "status «{$board}» (AR)");
            $this->assertSame($m[2], DigestStatus::label($ours, false), "status «{$board}» (EN)");
        }
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), 'ar', 'daily');
        $cols = array_column($m['heatmap']['columns'], 'provider');
        $this->assertSame(AdPlatforms::sort($cols), $cols, 'matrix columns follow AdPlatforms::ORDER');
    }

    public function test_a_digest_issue_links_to_the_report_view_with_its_period_and_platform(): void
    {
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), 'ar', 'daily');
        $withPlatform = array_values(array_filter($m['issues'], static fn (array $i): bool => $i['kind'] === 'cell' && ! in_array($i['reason'], ['sync_failed', 'stale'], true) && ! str_starts_with($i['reason'], 'alert_')));
        $this->assertNotEmpty($withPlatform);
        $this->assertStringContainsString('days=7', $withPlatform[0]['url']);
        $this->assertStringContainsString('platform='.$withPlatform[0]['provider'], $withPlatform[0]['url']);
        $this->assertStringContainsString('tab=platforms', $withPlatform[0]['url']);
    }

    public function test_the_mail_views_hold_no_label_literals_of_their_own(): void
    {
        // Figure labels come from the model (the report's table); a view that typed «ROAS» or «CPM» would drift from the report.
        foreach (glob(base_path('resources/views/mail/digest/*.blade.php')) as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/>\s*(ROAS|CPM|CPL|CPA|العائد على الإنفاق|تكلفة الألف ظهور|تكلفة العميل المحتمل)\s*</u', $src, basename($file));
        }
    }
}
