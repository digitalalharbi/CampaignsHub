<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Funding\Services\FundingPdf;
use App\Domains\Funding\Support\FundingDisclaimer;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * BUDGET-PDF-LAYOUT-001 — «البيان المالي» is laid out on ONE grid per table: the same fixed columns in the head, every row and the
 * totals row, numbers in a left-to-right island aligned to the column edge with one format per column, the currency named once in the
 * head, and the identity block and the disclaimer present. Structure only — the visual check is a render of the real PDF.
 */
final class FundingPdfLayoutTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(string $mode, array $extra = []): array
    {
        return $extra + [
            'client' => 'Client X', 'project' => 'Project '.$mode, 'project_id' => 'p-'.$mode, 'payment_method' => 'prepaid', 'payment_mode' => $mode,
            'approved' => 10_000.0, 'spent' => 1_234.5, 'remaining' => $mode === 'direct' ? 8_765.5 : null, 'ceiling' => $mode === 'credit' ? 5_000.0 : null,
            'to_invoice' => $mode === 'credit' ? 575.0 : null, 'this_month' => $mode === 'credit' ? 300.0 : null, 'used_ratio' => 0.1235, 'status' => $mode === 'direct' ? 'ok' : 'credit',
            'period_start' => '2026-11-01', 'period_end' => null, 'planned' => false, 'plan' => null,
            'by_platform' => [['provider' => 'snapchat', 'spent' => 1_000.0, 'spent_usd' => 266.67], ['provider' => 'meta', 'spent' => 234.5, 'spent_usd' => 62.53]],
            'invoice_months' => $mode === 'credit' ? [['month' => '2026-10', 'from' => '2026-10-01', 'to' => '2026-10-31', 'closed' => true, 'spent' => 575.0, 'unconverted_days' => 0, 'last_spend_day' => '2026-10-29', 'by_platform' => []]] : [],
        ];
    }

    private function dom(string $lang = 'ar'): DOMXPath
    {
        $planned = $this->row('direct', ['planned' => true, 'plan' => [
            ['provider' => 'snapchat', 'percent' => 60.0, 'planned' => 6_000.0, 'spent' => 1_000.0, 'share_used' => 0.1667, 'planned_usd' => 1_600.0, 'spent_usd' => 266.67, 'pace_per_day' => 142.86, 'projected' => 3_000.0, 'expected_to_date' => 1_000.0, 'variance' => 0.0, 'flag' => null, 'unconverted_days' => 0],
            ['provider' => 'meta', 'percent' => 40.0, 'planned' => 4_000.0, 'spent' => 234.5, 'share_used' => 0.0586, 'planned_usd' => 1_066.67, 'spent_usd' => 62.53, 'pace_per_day' => null, 'projected' => null, 'expected_to_date' => 1_000.0, 'variance' => -765.5, 'flag' => 'behind', 'unconverted_days' => 0],
        ]]);
        $html = app(FundingPdf::class)->html([$this->row('direct'), $this->row('credit'), $planned], [], '2026-11-13', $lang, [
            'agency' => ['name' => 'Agency X', 'logo' => null], 'client' => null, 'clients' => 1, 'project' => null, 'projects' => 3, 'mode' => null,
            'period' => 'start .. 2026-11-13', 'generated_at' => '2026-11-13 10:00', 'generated_by' => 'Finance User',
        ]);
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    public function test_every_table_is_one_grid_the_same_columns_in_head_rows_and_totals(): void
    {
        $x = $this->dom();
        $grids = $x->query('//table[@data-grid]');
        $this->assertGreaterThanOrEqual(3, $grids->length, 'summary, platforms and invoice months');
        foreach ($grids as $grid) {
            $name = $grid->getAttribute('data-grid');
            $cols = $x->query('.//colgroup/col', $grid);
            $head = $x->query('.//thead/tr/th', $grid);
            $this->assertSame($cols->length, $head->length, "{$name}: a column per head cell");
            $widths = 0;
            foreach ($cols as $c) {
                preg_match('/width:\s*(\d+)%/', $c->getAttribute('style'), $m);
                $this->assertNotEmpty($m, "{$name}: every column has a fixed width");
                $widths += (int) $m[1];
            }
            $this->assertSame(100, $widths, "{$name}: the widths add up to the table");
            foreach ($x->query('.//tbody/tr', $grid) as $i => $tr) {
                $this->assertSame($cols->length, $x->query('./td', $tr)->length, "{$name}: body row {$i} has the same columns");
            }
            foreach ($x->query('.//tfoot/tr', $grid) as $tr) {
                $this->assertSame($cols->length, $x->query('./td', $tr)->length, "{$name}: the totals row has the same columns");
            }
            // The numeric columns of the head are the numeric columns of every row and of the totals row.
            $numericHead = [];
            foreach ($head as $i => $th) {
                if (str_contains(' '.$th->getAttribute('class').' ', ' n ')) {
                    $numericHead[] = $i;
                }
            }
            foreach ($x->query('.//tbody/tr | .//tfoot/tr', $grid) as $tr) {
                $cells = $x->query('./td', $tr);
                foreach ($numericHead as $i) {
                    $this->assertStringContainsString('n', $cells->item($i)->getAttribute('class'), "{$name}: column {$i} is numeric in the body too");
                    if (! in_array(trim($cells->item($i)->textContent), ['', '—'], true)) {
                        $this->assertGreaterThan(0, $x->query('.//bdi', $cells->item($i))->length, "{$name}: a number sits in a direction-isolated island");
                    }
                }
            }
        }
    }

    public function test_numbers_use_latin_digits_one_format_and_a_short_currency_mark_beside_each(): void
    {
        $x = $this->dom();
        $summary = $x->query('//table[@data-grid="summary"]')->item(0);
        foreach ($x->query('.//tbody/tr', $summary) as $tr) {
            foreach ([3, 4, 5, 6] as $i) {
                $text = trim($x->query('./td', $tr)->item($i)->textContent);
                $this->assertMatchesRegularExpression('/^(—|-?\d{1,3}(,\d{3})*\.\d{2}\s*ر\.س)$/u', $text, 'money: thousands separators, two decimals, the mark ر.س beside it');
            }
        }
        $platforms = $x->query('//table[@data-grid="platforms"]')->item(0);
        foreach ($x->query('.//tbody/tr', $platforms) as $tr) {
            foreach ([2, 3] as $i) {
                $this->assertMatchesRegularExpression('/^(—|\d{1,3}(,\d{3})*\.\d{2}\s*\$)$/u', trim($x->query('./td', $tr)->item($i)->textContent), 'a platform figure is in USD: the mark is $');
            }
        }
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', (string) $summary->textContent, 'Latin digits only');
    }

    public function test_the_statement_carries_its_identity_block_and_the_disclaimer_in_the_footer_and_at_the_end(): void
    {
        $x = $this->dom();
        $this->assertSame('البيان المالي', trim($x->query('//title')->item(0)->textContent));
        $id = $x->query('//*[@data-block="statement-identity"]')->item(0)->textContent;
        foreach (['Agency X'] as $needle) {
            $this->assertStringContainsString($needle, $id);
        }
        $this->assertStringContainsString('2026-11-13 10:00', (string) $x->document->textContent);
        $this->assertStringContainsString('Finance User', (string) $x->document->textContent);
        $this->assertStringNotContainsString('الرقم الضريبي', (string) $x->document->textContent, 'a field the workspace does not hold is not invented');
        $this->assertSame(1, $x->query('//*[@data-block="financial-disclaimer"]')->length);
        $this->assertSame(2, substr_count((string) $x->document->textContent, FundingDisclaimer::text('ar')), 'on the footer of every page and at the end');

        $en = $this->dom('en');
        $this->assertSame('Financial statement', trim($en->query('//title')->item(0)->textContent));
        $this->assertSame(2, substr_count((string) $en->document->textContent, FundingDisclaimer::text('en')));
    }

    public function test_the_source_keeps_numbers_right_aligned_in_ltr_islands(): void
    {
        $css = File::get(resource_path('views/funding/document.blade.php'));
        $this->assertStringContainsString('table-layout: fixed', $css);
        $this->assertMatchesRegularExpression('/\.grid th\.n, \.grid td\.n \{ text-align: right; \}/', $css);
        $this->assertStringContainsString('direction: ltr', $css);
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $css);
    }
}
