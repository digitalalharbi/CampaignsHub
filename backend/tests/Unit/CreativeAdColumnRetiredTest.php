<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CREATIVE-COLUMN-RETIRE-001 — `external_creatives.external_ad_id` has no readers left, and this is
 * what stops one coming back.
 *
 * ## What the column was
 *
 * `creativeFor()` wrote it on every upsert, from whichever ad it happened to be processing. A
 * creative is carried by MANY ads — on the live Snapchat account four ads share each one — so the
 * column named the ad imported LAST. Nothing about that is visible from a row: it holds a real ad id
 * belonging to a real ad.
 *
 * Everything that read it was therefore quietly wrong, and each was found separately:
 *
 *   * `CreativeRows`' filter — `whereIn('external_ad_id', $ids)` — matched only the last ad, so
 *     filtering by any other ad on a creative returned nothing;
 *   * its options list counted `distinct('external_ad_id')`, which is a count of CREATIVES wearing
 *     an ad's name — 1,451 options on the live account for 5,706 ads;
 *   * `DiagnoseSyncCommand` read `whereNull('external_ad_id')` and called the result «creatives with
 *     no ad»;
 *   * `CreativePresenter` emitted a singular `ad_id` and the drill-down linked to whichever ad that
 *     happened to be.
 *
 * The canonical relation is `external_ads.creative_id` and always was — a `hasMany`, no association
 * table, no backfill needed.
 *
 * ## Why a test rather than a migration
 *
 * Dropping the column is a separate, deliberate act: it is irreversible in production and its
 * `down()` cannot restore what it deletes. This asserts the thing that actually matters — that no
 * code reads or writes it — so the drop, when somebody makes it, is a formality rather than a risk.
 *
 * The lead ingestion's own `external_ad_id` is a DIFFERENT column on a different table: a lead
 * genuinely arrives from one ad, and that domain is allowed.
 */
final class CreativeAdColumnRetiredTest extends TestCase
{
    /** Where a creative's ad relation is read, and therefore where the retired column must not be. */
    private const SCANNED = [
        'app/Domains/Campaigns',
        'app/Domains/Reports',
        'app/Domains/Metrics',
    ];

    /**
     * A lead carries the ad it came from, on `leads.external_ad_id`. One lead, one ad — the relation
     * this test is about does not exist there, and neither does the defect.
     */
    private const ALLOWED = [
        'app/Domains/CRM',
        'app/Domains/Integrations/Leads',
    ];

    public function test_no_code_reads_or_writes_the_retired_column(): void
    {
        $offenders = [];

        foreach (self::SCANNED as $dir) {
            foreach ($this->phpFiles(dirname(__DIR__, 2).'/'.$dir) as $path) {
                $relative = substr($path, strpos($path, 'app/') ?: 0);

                if ($this->allowed($relative)) {
                    continue;
                }

                foreach (explode("\n", $this->withoutComments(file_get_contents($path) ?: '')) as $i => $line) {
                    if (str_contains($line, 'external_ad_id')) {
                        $offenders[] = $relative.':'.($i + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The canonical relation is `external_ads.creative_id`.\n".
            "`external_creatives.external_ad_id` names whichever ad was imported LAST, and every reader of it\n".
            "has been a defect:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * The scanner reads something, and it can still see the identifier where it belongs.
     *
     * A scan that matched nothing anywhere would pass this file forever — the same failure mode as
     * the column it guards, one level up.
     */
    public function test_the_scanner_reads_code_and_can_still_find_the_identifier(): void
    {
        /*
         * EVERY directory, not the total.
         *
         * A total over three directories stays comfortably above any threshold when one of them is
         * misspelled or moved — so the first version of this assertion passed with the Campaigns
         * domain pointed at a path that does not exist, which is the whole area the test is for.
         */
        foreach (self::SCANNED as $dir) {
            $this->assertNotSame(
                [],
                $this->phpFiles(dirname(__DIR__, 2).'/'.$dir),
                "{$dir} produced no files — the scan is looking at the wrong place",
            );
        }

        $lead = file_get_contents(dirname(__DIR__, 2).'/app/Domains/CRM/Models/Lead.php') ?: '';
        $this->assertStringContainsString(
            'external_ad_id',
            $this->withoutComments($lead),
            'a lead carries the ad it came from; if that is gone, this test is scanning the wrong thing',
        );
    }

    private function allowed(string $relative): bool
    {
        foreach (self::ALLOWED as $dir) {
            if (str_starts_with($relative, $dir)) {
                return true;
            }
        }

        return false;
    }

    /** Comments may describe the retired column at length — that is documentation, not a reader. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // Keep the newlines so the line numbers above stay true.
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            $out = array_merge($out, is_dir($path) ? $this->phpFiles($path) : (str_ends_with($entry, '.php') ? [$path] : []));
        }

        return $out;
    }
}
