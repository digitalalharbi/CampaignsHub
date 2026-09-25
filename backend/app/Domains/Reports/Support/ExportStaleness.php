<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ReportExporter;

/**
 * REPORT-EXPORT-STALE-DEADEND-001 — one rule about whether a stored PDF may still be handed over.
 *
 * ## The dead end this closes
 *
 * `ReportDownloadController` refuses a stale export with 409 «This export is stale — regenerate it
 * before downloading», and it is right to: a file produced by a different renderer or a different
 * template is not the document the current pipeline would produce, and handing it to a client as
 * though it were is the lie the refusal exists to prevent.
 *
 * But the reports list drew that export as a plain `<a href>`. So the sequence a customer actually
 * met was: a PDF chip that looks ready, one click, and the browser navigating away to a JSON error
 * body. No regenerate button — that existed only for exports whose STATUS was `failed` — and no way
 * back. Which is precisely «PDF export does not work in the real product», reported as a fault in
 * the renderer when the renderer had done its job.
 *
 * And it is not an edge case. `renderer_version` is a CONFIGURED value, so any deploy that changes
 * it turns every export made before it into a dead link at once.
 *
 * ## Why this is a class and not a method on the controller
 *
 * It was a private method on the controller, which is why the listing could not ask the question and
 * ended up not asking it. Two places now need the same answer — the endpoint that refuses, and the
 * interface that must offer the way out BEFORE somebody clicks — and a second copy of the rule is
 * how they would come to disagree about which files are downloadable.
 *
 * Tabular exports are exempt, unchanged: a CSV carries no renderer, no template and no Arabic text
 * layer, so none of the three reasons below can apply to one.
 */
final class ExportStaleness
{
    /** The renderer produced this file, and the pipeline has moved on since. */
    public const RENDERER_CHANGED = 'renderer_changed';

    /** The document's own layout contract has moved on. */
    public const TEMPLATE_CHANGED = 'template_changed';

    /**
     * The Arabic text layer did not validate, or predates the check.
     *
     * Listed first because it is the one that says something about the FILE rather than about the
     * pipeline around it: a PDF whose text layer never passed is not searchable or readable by a
     * screen reader, whatever version produced it.
     */
    public const VALIDATION_FAILED = 'validation_failed';

    /**
     * Why this export may not be handed over, or null when it may.
     *
     * Returns a CODE rather than a sentence: the interface translates it, and the two languages this
     * product ships in are not the renderer's business.
     */
    public static function reason(ReportExport $export): ?string
    {
        if ($export->format !== 'pdf') {
            return null;
        }

        if (($export->validation_status ?? 'unknown') !== 'passed') {
            return self::VALIDATION_FAILED;
        }

        if ($export->renderer_version !== (string) config('reports.chromium.renderer_version', 'chromium-1228')) {
            return self::RENDERER_CHANGED;
        }

        if ($export->template_version !== ReportExporter::TEMPLATE_VERSION) {
            return self::TEMPLATE_CHANGED;
        }

        return null;
    }

    public static function isStale(ReportExport $export): bool
    {
        return self::reason($export) !== null;
    }
}
