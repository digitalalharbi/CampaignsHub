<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — WHY an export failed, in a word the product can translate.
 *
 * The owner clicked PDF in Production and nothing arrived. Three things were true at once: the
 * renderer could not run, the export row was marked `failed` with the reason in its `error` column,
 * and the reports table only ever looked for exports whose status was `completed` — so the button
 * silently redrew itself and the reason was never read by anybody.
 *
 * The stored message is the right thing to classify and the wrong thing to PRINT: it carries
 * renderer stderr, absolute paths and binary names, and this table is read by an operator who can
 * act on «the renderer is not enabled on this server» and can do nothing with a stack trace. So the
 * row carries a CODE, the interface owns the sentence in both languages, and nothing internal
 * crosses the wire.
 *
 * Classification mirrors `AccountDiscovery::classify()` rather than inventing a second vocabulary
 * for the same job — a failure the product can name is a failure it can tell somebody about.
 */
final class ExportFailureReason
{
    /**
     * The renderer refused because it is switched off for this environment.
     *
     * `REPORTS_CHROMIUM_ENABLED` defaults to false so that a box without Node or Chromium still
     * runs, and `ReportExporter::pdf()` throws rather than shipping a Dompdf fallback to a client.
     * Both are deliberate; what was missing is anyone being told.
     */
    public const RENDERER_DISABLED = 'renderer_disabled';

    /** Node, the print script or the Chromium binary could not be started. */
    public const RENDERER_UNAVAILABLE = 'renderer_unavailable';

    /** The Arabic text-layer pass could not be run or could not reach zero presentation forms. */
    public const TEXT_LAYER_FAILED = 'text_layer_failed';

    /** The snapshot and its narrative disagree, or the consistency gate refused the data. */
    public const DATA_NOT_READY = 'data_not_ready';

    /** The render took longer than the renderer's own deadline. */
    public const TIMED_OUT = 'timed_out';

    /** Something the product cannot yet name. The operator still learns that it failed. */
    public const UNKNOWN = 'export_failed';

    public static function classify(?string $raw): string
    {
        $message = trim((string) $raw);

        if ($message === '') {
            return self::UNKNOWN;
        }

        return match (true) {
            // The refusal text `ReportExporter::pdf()` throws for a client or executive report.
            (bool) preg_match('/Chromium renderer, which is disabled|REPORTS_CHROMIUM_ENABLED/i', $message) => self::RENDERER_DISABLED,
            (bool) preg_match('/text-layer/i', $message) => self::TEXT_LAYER_FAILED,
            (bool) preg_match('/timed out|timeout|ETIMEDOUT/i', $message) => self::TIMED_OUT,
            (bool) preg_match('/Chromium PDF render failed|ENOENT|Executable doesn.t exist|node: not found|spawn/i', $message) => self::RENDERER_UNAVAILABLE,
            (bool) preg_match('/snapshot|narrative|checksum|not ready|inconsisten/i', $message) => self::DATA_NOT_READY,
            default => self::UNKNOWN,
        };
    }
}
