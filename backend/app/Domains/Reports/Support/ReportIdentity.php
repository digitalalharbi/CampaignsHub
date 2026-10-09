<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\Report;
use Illuminate\Support\Str;

/**
 * REPORT-TITLE-METADATA-001 — one name for a report, wherever it is written down.
 *
 * ## What a client actually receives
 *
 * `report.pdf`. Every time, from every project, for every period. A client who keeps four of them
 * has four files called `report.pdf` in one folder, and the only way to tell them apart is to open
 * each one. The same document arrives by email under a subject that does not say which report it is,
 * and sits in a browser tab titled with the product's name.
 *
 * The naming model is the one the requirement asks for — `نوع التقرير — العميل/المشروع — الفترة` —
 * and it is built ONCE here so the file, the subject and the title cannot drift into three different
 * answers about the same document.
 *
 * ## Why the filename is transliterated and the title is not
 *
 * A `Content-Disposition` filename crosses more broken software than any other string this product
 * emits: a client's mail server, their browser, their operating system, and whatever they forward it
 * with. Arabic survives most of that and not all of it, and the failure is silent — the file arrives
 * as `______.pdf` or refuses to save at all. So the FILE gets a conservative ASCII name and the
 * TITLE, which nothing has to encode, keeps the client's own language.
 */
final class ReportIdentity
{
    /**
     * The document's name, in the reader's language.
     *
     * `نوع التقرير — العميل/المشروع — الفترة`, with any part that is not known simply absent rather
     * than filled with a placeholder: «تقرير — — أغسطس» tells a reader that something is broken.
     */
    /**
     * REPORT BRANDING — the report's name ending with the product's, in the REPORT's own language.
     *
     * «<اسم التقرير> — كامبينز هب» for an Arabic report, «<Report name> — CampaignsHub» for an English
     * one. It is what the tab, the pasted-link card and the file's own title say; whose report it is
     * travels beside it (the header, the card's description), not in it. The SPA mirrors this in
     * `reportPageTitle()`, and `ReportPageTitleParityTest` holds the two to one format.
     */
    public static function pageTitle(Report $report): string
    {
        $locale = $report->reportLocale();
        $name = trim((string) ($report->name ?? ''));
        if ($name === '') {
            $name = $locale === 'en' ? 'Performance report' : 'تقرير الأداء';
        }

        return "{$name} — ".self::productName($locale);
    }

    /** The product's name in a language — `brand.name_ar` for Arabic, `brand.name` otherwise. */
    public static function productName(string $locale): string
    {
        return (string) ($locale === 'ar' ? config('brand.name_ar', 'كامبينز هب') : config('brand.name', 'CampaignsHub'));
    }

    public static function title(Report $report, string $locale = 'ar'): string
    {
        $parts = array_values(array_filter([
            self::kind($report, $locale),
            $report->name !== '' ? $report->name : null,
            self::period($report),
        ], static fn (?string $p): bool => $p !== null && trim($p) !== ''));

        return implode(' — ', $parts);
    }

    /*
     * There was a `documentTitle()` here, and deleting it is the point.
     *
     * It appended « · CampaignsHub» to the title — the Latin name, whatever language the rest of the
     * string was in — and it had exactly one caller in the world: its own unit test. The browser tab
     * for a report is set by the SPA: the client's shared report names itself «report · client», and
     * every authenticated section titles itself from the rail. A third answer computed on the server
     * and rendered by nobody can only drift, and this one already had — the owner's rule is that once
     * the locale is known the visible title follows it, and this named the product in Latin on an
     * Arabic document.
     *
     * The rest of this class stays because every one of its answers is USED: `title()` by the subject
     * and the filename, `subject()` by `ScheduledReportDispatcher`, `filename()` by
     * `ReportDownloadController` — and each of those is now asserted where it lands rather than only
     * where it is computed.
     */

    /**
     * The email subject.
     *
     * Identical to the title rather than a second phrasing: a subject line and the document it
     * carries disagreeing is how a client ends up asking which of the two is the real report.
     */
    public static function subject(Report $report, string $locale = 'ar'): string
    {
        return self::title($report, $locale);
    }

    /**
     * The file a client keeps, with its extension.
     *
     * ASCII, lower case, hyphenated. Built from the ENGLISH title, because `Str::slug` strips
     * non-ASCII entirely — slugging «تقرير تفصيلي — آساس الثبات» produces an empty string, and a
     * file called `.pdf` is one the operating system hides. The English kind always contributes
     * text, so the name can never come out empty however the report is configured, and the id is
     * appended so two reports of one kind over one period are still two different files.
     */
    public static function filename(Report $report, string $format): string
    {
        $slug = Str::slug(self::title($report, 'en'));
        $short = substr((string) $report->getKey(), 0, 8);

        // Cut before appending the id, so the part that makes the name UNIQUE is never the part lost.
        return Str::limit($slug, 70, '').'-'.$short.'.'.strtolower($format);
    }

    /** What kind of document this is, in the reader's language. */
    private static function kind(Report $report, string $locale): string
    {
        $ar = $locale === 'ar';
        $form = (string) ($report->form ?? 'detailed');

        return $form === 'executive_summary'
            ? ($ar ? 'ملخص تنفيذي' : 'Executive summary')
            : ($ar ? 'تقرير تفصيلي' : 'Detailed report');
    }

    /**
     * The window the report covers, as dates rather than as a phrase.
     *
     * «آخر ٣٠ يومًا» is meaningless on a document somebody opens in November. The dates are what make
     * two files in one folder distinguishable, which is the entire point of naming them.
     */
    private static function period(Report $report): ?string
    {
        /*
         * REPORT-TITLE-METADATA-001 — the COLUMNS first, and that is a defect being fixed.
         *
         * This read `config.period` and nothing else. Not one report in the estate stores a period
         * there: every row carries `period_start` / `period_end` and leaves the config key unset,
         * so this returned null for all of them — and the dates silently vanished from the two
         * surfaces that need them most.
         *
         * The result was an email subject reading «تقرير تفصيلي — آساس الثبات» for January and the
         * identical string for February, and an export filename distinguished only by eight
         * characters of a uuid. The docblock above has always said why that matters — «the dates are
         * what make two files in one folder distinguishable, which is the entire point of naming
         * them» — so the intent was recorded and simply unmet, which is exactly the shape of thing
         * «untested» hides.
         *
         * The config keys are kept as a fallback: a report built before the columns existed, or one
         * whose period is a config-only concept, still names itself.
         */
        $config = (array) ($report->config ?? []);

        $from = self::day($report->period_start) ?? self::stringOrNull($config['period']['from'] ?? $config['from'] ?? null);
        $to = self::day($report->period_end) ?? self::stringOrNull($config['period']['to'] ?? $config['to'] ?? null);

        if ($from === null || $to === null) {
            return null;
        }

        return $from === $to ? $from : $from.' → '.$to;
    }

    /** A date column as `Y-m-d`, or null where it is unset — never «now» by accident. */
    private static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = self::stringOrNull($value);

        return $text === null ? null : substr($text, 0, 10);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
