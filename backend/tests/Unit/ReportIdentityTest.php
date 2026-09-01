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

    public function test_the_title_names_the_kind_the_client_and_the_period(): void
    {
        $this->assertSame(
            'تقرير تفصيلي — آساس الثبات — 2026-08-01 → 2026-08-31',
            ReportIdentity::title($this->report()),
        );
        $this->assertSame(
            'Executive summary — آساس الثبات — 2026-08-01 → 2026-08-31',
            ReportIdentity::title($this->report(['form' => 'executive_summary']), 'en'),
        );
    }

    /**
     * A part that is not known is ABSENT, never a placeholder.
     *
     * «تقرير — — أغسطس» tells a reader that something is broken, which is a worse message than a
     * shorter name.
     */
    public function test_a_missing_part_is_left_out_rather_than_filled_in(): void
    {
        $title = ReportIdentity::title($this->report(['config' => []]));

        $this->assertSame('تقرير تفصيلي — آساس الثبات', $title);
        $this->assertStringNotContainsString('—  —', $title);
    }

    /**
     * The period is DATES, not a phrase.
     *
     * «آخر ٣٠ يومًا» is meaningless on a document somebody opens in November, and the dates are
     * exactly what makes two files in one folder distinguishable.
     */
    public function test_the_period_is_dates(): void
    {
        $this->assertStringContainsString('2026-08-01', ReportIdentity::title($this->report()));
        // A single-day window says the day once rather than «X → X».
        $this->assertStringEndsWith(
            '2026-08-07',
            ReportIdentity::title($this->report(['config' => ['period' => ['from' => '2026-08-07', 'to' => '2026-08-07']]])),
        );
    }

    /**
     * The FILE is ASCII and the TITLE is not, deliberately.
     *
     * A `Content-Disposition` filename crosses more broken software than anything else this product
     * emits — a mail server, a browser, an operating system, whatever the client forwards it with —
     * and the failure is silent: the file arrives as `______.pdf` or refuses to save. The title has
     * nothing to encode, so it keeps the client's own language.
     */
    public function test_the_filename_is_conservative_and_the_title_is_not(): void
    {
        $file = ReportIdentity::filename($this->report(), 'pdf');

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.pdf$/', $file);
        $this->assertStringNotContainsString('report.pdf', $file);
        $this->assertStringContainsString('detailed-report', $file);
        // The Arabic client name is gone from the FILE and present in the TITLE.
        $this->assertStringNotContainsString('آساس', $file);
        $this->assertStringContainsString('آساس الثبات', ReportIdentity::title($this->report()));
    }

    /**
     * An Arabic-only report still produces a file somebody can find, and two of them differ.
     *
     * `Str::slug` strips non-ASCII entirely, so slugging the Arabic title yields an empty string and
     * a file called `.csv` — which the operating system hides. The English kind always contributes
     * text, and the id keeps two reports of one kind over one period apart.
     */
    public function test_an_arabic_report_still_produces_a_distinct_findable_file(): void
    {
        $first = ReportIdentity::filename($this->report(['name' => 'آساس الثبات', 'config' => []]), 'csv');
        $second = ReportIdentity::filename($this->report(['id' => 'ffffffff-0000-0000-0000-000000000000', 'name' => 'آساس الثبات', 'config' => []]), 'csv');

        $this->assertNotSame('.csv', $first);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.csv$/', $first);
        $this->assertNotSame($first, $second, 'two reports of one kind over one period share a filename');
    }

    /**
     * The subject is the title, not a second phrasing.
     *
     * A subject line and the document it carries disagreeing is how a client ends up asking which of
     * the two is the real report.
     */
    public function test_the_email_subject_is_the_documents_own_name(): void
    {
        $report = $this->report();

        $this->assertSame(ReportIdentity::title($report), ReportIdentity::subject($report));
        $this->assertStringEndsWith('· CampaignsHub', ReportIdentity::documentTitle($report));
    }
}
