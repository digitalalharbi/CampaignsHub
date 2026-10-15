<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\ReportIdentity;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-TITLE-METADATA-001 — one name for a report, wherever it is written down.
 *
 * A client received `report.pdf`. Every time, from every project, for every period. Four of them in
 * one folder are four files called `report.pdf`, and the only way to tell them apart is to open each
 * one. The same document arrived by email under a subject that did not say which report it was.
 *
 * The naming model is `نوع التقرير — العميل/المشروع — الفترة`, built once so the file, the subject
 * and the title cannot drift into three different answers about the same document.
 */
final class ReportIdentityTest extends TestCase
{
    private function report(array $attributes = []): Report
    {
        $report = new Report;
        $report->forceFill([
            'id' => '0f8c1a2b-3d4e-5f60-7182-93a4b5c6d7e8',
            'name' => 'آساس الثبات',
            'form' => 'detailed',
            'config' => ['period' => ['from' => '2026-08-01', 'to' => '2026-08-31']],
            ...$attributes,
        ]);

        return $report;
    }

    /**
     * REPORT-TITLE-EXACT-001 — the title is the one entered, in every place it appears.
     */
    public function test_the_title_is_exactly_the_entered_name(): void
    {
        $this->assertSame('آساس الثبات', ReportIdentity::title($this->report()));
        $this->assertSame('آساس الثبات', ReportIdentity::title($this->report(['form' => 'executive_summary']), 'en'));
        $this->assertSame('آساس الثبات', ReportIdentity::pageTitle($this->report()));
    }

    public function test_no_kind_client_or_period_is_composed_into_it(): void
    {
        $title = ReportIdentity::title($this->report());

        foreach (['تقرير تفصيلي', 'Detailed', 'Executive', '2026-08-01', '→', ' — '] as $needle) {
            $this->assertStringNotContainsString($needle, $title);
        }
    }

    public function test_an_unnamed_report_falls_back_to_a_neutral_label_never_an_empty_title(): void
    {
        $this->assertSame('تقرير الأداء', ReportIdentity::title($this->report(['name' => ''])));
        $this->assertSame('Performance report', ReportIdentity::title($this->report(['name' => '']), 'en'));
    }

    public function test_the_filename_is_the_entered_title_folded_to_ascii(): void
    {
        $file = ReportIdentity::filename($this->report(['name' => 'Henka WhatsApp — Aug 2026']), 'pdf');

        $this->assertMatchesRegularExpression('/^henka-whatsapp-aug-2026-[a-z0-9]{8}\.pdf$/', $file);
    }

    public function test_an_arabic_title_still_produces_a_distinct_findable_file(): void
    {
        $first = ReportIdentity::filename($this->report(), 'csv');
        $second = ReportIdentity::filename($this->report(['id' => 'ffffffff-0000-0000-0000-000000000000']), 'csv');

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.csv$/', $first);
        $this->assertStringNotContainsString('.csv', substr($first, 0, 1));
        $this->assertNotSame($first, $second, 'two reports with one title share a filename');
    }

    public function test_the_email_subject_is_the_documents_own_name(): void
    {
        $report = $this->report();

        $this->assertSame(ReportIdentity::title($report), ReportIdentity::subject($report));
    }
}
