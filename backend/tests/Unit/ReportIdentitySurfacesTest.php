<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\ReportIdentity;
use Tests\TestCase;

/**
 * REPORT-TITLE-METADATA-001 — the two surfaces the row named as untested: the EMAIL SUBJECT and the
 * EXPORT FILENAME.
 *
 * The browser tab and the crawler card were already held. These two were not, and they are the two a
 * client keeps: a subject line sits in their inbox for years, and a filename sits in their downloads
 * folder beside every other report they have ever been sent. «report.pdf» and «Your report is
 * ready» are the versions of these that make a product look like a form-mailer.
 *
 * Both are pure functions of the model, so nothing here touches a database — but the app is booted
 * (`Tests\TestCase`, no `RefreshDatabase`) because an Eloquent model cannot be constructed at all
 * without a connection resolver, even when it is never saved. PHPUnit's bare `TestCase` fails on
 * «Call to a member function connection() on null» before reaching the first assertion.
 */
final class ReportIdentitySurfacesTest extends TestCase
{
    private function report(array $over = []): Report
    {
        $report = new Report;
        $report->forceFill(array_merge([
            'id' => '7f3c1a2b-9d4e-4f58-8a6b-0c1d2e3f4a5b',
            'name' => 'آساس الثبات',
            'form' => 'detailed',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'config' => ['locale' => 'ar'],
        ], $over));

        return $report;
    }

    // ── the subject ──────────────────────────────────────────────────────────────────────────

    /**
     * The subject says WHAT, WHICH and WHEN — in the reader's own language.
     *
     * An inbox shows about sixty characters. «Your report is ready» spends all of them saying
     * nothing, and a client with two agencies cannot tell two such mails apart.
     */
    public function test_the_email_subject_names_the_kind_the_report_and_the_period(): void
    {
        $subject = ReportIdentity::subject($this->report(), 'ar');

        $this->assertStringContainsString('تقرير تفصيلي', $subject);
        $this->assertStringContainsString('آساس الثبات', $subject);
        $this->assertStringContainsString('2026-07-01', $subject);
        $this->assertStringContainsString('2026-07-31', $subject);
    }

    /** An executive summary says so, because it is a different document for a different reader. */
    public function test_the_subject_distinguishes_a_summary_from_a_detailed_report(): void
    {
        $detailed = ReportIdentity::subject($this->report(), 'en');
        $summary = ReportIdentity::subject($this->report(['form' => 'executive_summary']), 'en');

        $this->assertStringContainsString('Detailed report', $detailed);
        $this->assertStringContainsString('Executive summary', $summary);
        $this->assertNotSame($detailed, $summary);
    }

    /**
     * The subject follows the READER's language, not the report's.
     *
     * A schedule carries its own locale because the recipient is not always the person who built
     * the report — an agency builds in Arabic and mails an English-speaking stakeholder.
     */
    public function test_the_subject_follows_the_language_it_is_asked_for(): void
    {
        $this->assertStringContainsString('تقرير تفصيلي', ReportIdentity::subject($this->report(), 'ar'));
        $this->assertStringContainsString('Detailed report', ReportIdentity::subject($this->report(), 'en'));
    }

    // ── the filename ─────────────────────────────────────────────────────────────────────────

    /**
     * ASCII, lower case, hyphenated — and never empty.
     *
     * `Str::slug` strips non-ASCII entirely, so slugging an Arabic title produces «» and a file
     * called «.pdf», which the operating system hides. The English KIND always contributes text,
     * which is why the name is built from the English title however the report is configured.
     */
    public function test_an_arabic_report_still_gets_a_real_filename(): void
    {
        $name = ReportIdentity::filename($this->report(), 'pdf');

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.pdf$/', $name);
        $this->assertStringStartsWith('detailed-report', $name);
        $this->assertStringNotEqualsFile(__FILE__, $name); // sanity: it is a name, not a path
    }

    /**
     * Two reports of one kind over one period are still two files.
     *
     * The id is appended for exactly that, and it is appended AFTER the length cut — so the part
     * that makes the name unique is never the part lost to the limit.
     */
    public function test_two_reports_of_one_kind_over_one_period_are_two_files(): void
    {
        $a = ReportIdentity::filename($this->report(), 'pdf');
        $b = ReportIdentity::filename($this->report(['id' => '0011aabb-ccdd-4eef-8a6b-0c1d2e3f4a5b']), 'pdf');

        $this->assertNotSame($a, $b);
    }

    /** A very long title does not cost the file its uniqueness. */
    public function test_a_long_title_keeps_the_part_that_makes_it_unique(): void
    {
        $name = ReportIdentity::filename($this->report(['name' => str_repeat('performance ', 40)]), 'xlsx');

        $this->assertStringEndsWith('-7f3c1a2b.xlsx', $name);
        $this->assertLessThanOrEqual(90, strlen($name));
    }

    /** The format is the extension, lower case, whatever case it arrived in. */
    public function test_the_format_becomes_a_lower_case_extension(): void
    {
        $this->assertStringEndsWith('.csv', ReportIdentity::filename($this->report(), 'CSV'));
        $this->assertStringEndsWith('.xlsx', ReportIdentity::filename($this->report(), 'XLSX'));
    }
}
