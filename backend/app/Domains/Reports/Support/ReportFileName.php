<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use Illuminate\Support\Carbon;

/**
 * REPORT-TITLE-METADATA-001 — what the file is called when it lands in somebody's Downloads folder.
 *
 * ## The defect
 *
 * The download served `basename($export->path)`, and that path is built from `Str::slug($report->name)`.
 * `Str::slug` TRANSLITERATES: «تقرير أداء أغسطس» becomes `tkryr-adaaa-aghsts`, so an Arabic-speaking
 * client's report arrives as `tkryr-adaaa-aghsts-20260830-044500.pdf`.
 *
 * It is not a broken file and nothing about it is wrong except the only thing a filename is for.
 * Saved beside four others it is unidentifiable, forwarded to a colleague it says nothing, and the
 * timestamp — the one legible part — is when we rendered it rather than the period it covers.
 *
 * ## What replaces it
 *
 *     «تقرير أداء أغسطس — متجر الرياض — 2026-08-01 إلى 2026-08-31.pdf»
 *
 * The report's own name in its own script, the client it is about, and the period it covers. HTTP
 * carries this perfectly well: `Content-Disposition` has had a UTF-8 form since RFC 5987, and
 * Symfony emits an ASCII fallback beside it for anything that cannot read one.
 *
 * ## The storage path is deliberately NOT changed
 *
 * That key is an internal identifier — it is matched, listed and logged — and it stays ASCII on
 * purpose. What a client sees and what a bucket stores are different questions, and this answers the
 * first one only.
 */
final class ReportFileName
{
    /** Characters a filesystem or a shell would read as structure rather than as text. */
    private const UNSAFE = ['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0", "\n", "\r", "\t"];

    /**
     * Long enough for a real name plus a client plus a period; short enough for every filesystem.
     *
     * Windows' traditional limit is 255 for the whole path component, and a name that reaches it is
     * one somebody has to rename before they can save it.
     */
    private const MAX = 120;

    public static function for(Report $report, string $format): string
    {
        $parts = array_values(array_filter([
            self::clean((string) ($report->name ?? '')),
            self::clean(self::client($report)),
            self::period($report),
        ], static fn (string $part): bool => $part !== ''));

        $name = $parts === [] ? 'report' : implode(' — ', $parts);

        if (mb_strlen($name) > self::MAX) {
            // Trimmed from the END, so the report's own name — the part a reader recognises — survives.
            $name = rtrim(mb_substr($name, 0, self::MAX));
        }

        return $name.'.'.$format;
    }

    /** The client this report is about, or the empty string when it is not about one. */
    private static function client(Report $report): string
    {
        $workspaceId = Project::withoutGlobalScopes()
            ->whereKey($report->project_id)
            ->value('client_workspace_id');

        if ($workspaceId === null) {
            return '';
        }

        return (string) (ClientWorkspace::withoutGlobalScopes()->whereKey($workspaceId)->value('name') ?? '');
    }

    /**
     * The period the report COVERS, not the moment it was rendered.
     *
     * The old name carried `now()->format('Ymd-His')`, which answers a question nobody asks of a
     * report they have been sent: two exports of the same August are two files whose names differ by
     * the minute somebody pressed the button.
     */
    private static function period(Report $report): string
    {
        if ($report->period_start === null || $report->period_end === null) {
            return '';
        }

        return Carbon::parse($report->period_start)->toDateString()
            .' - '.Carbon::parse($report->period_end)->toDateString();
    }

    /** Strip what a filesystem would read as structure, and collapse the whitespace that is left. */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(self::UNSAFE, ' ', $value)));
    }
}
