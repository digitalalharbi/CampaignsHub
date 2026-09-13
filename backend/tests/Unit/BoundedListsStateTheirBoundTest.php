<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * OPS-LEDGER-001 — a list the server bounds says how much of itself it is showing.
 *
 * ## Why a source rule rather than a case per endpoint
 *
 * Each of these already has, or could have, a feature test proving its own count. What no feature
 * test can say is «and the NEXT one somebody writes will do this too» — and that is the failure
 * mode this defect keeps returning through: `SecurityController` was made honest, then
 * `CampaignAlertsController` shipped silent, then the notification centre, then four more at two
 * hundred. Every one of them was written by somebody who had not read the others.
 *
 * So this reads the controllers and asks the one question that generalises: if a method caps its
 * rows with `limit(N)`, does that same method also count what it capped?
 *
 * ## What it deliberately does not cover
 *
 * A console command sampling a few rows for a diagnostic is not a list anybody reads as complete —
 * `DiagnoseSyncCommand` takes three payloads and five creatives on purpose. Services that bound an
 * internal computation are not lists either. This is about HTTP controllers, which are the things
 * that hand a bounded set to a reader.
 */
final class BoundedListsStateTheirBoundTest extends TestCase
{
    /** Controllers whose bounded lists are known to state their bound. */
    private const COVERED = [
        'Settings/Http/Controllers/SecurityController.php',
        'Campaigns/Http/Controllers/CampaignAlertsController.php',
        'Notifications/Http/Controllers/NotificationController.php',
        'Billing/Http/Controllers/BillingController.php',
        'Campaigns/Http/Controllers/CampaignAnnotationController.php',
        'Drive/Http/Controllers/DriveController.php',
    ];

    public function test_every_covered_controller_counts_what_it_capped(): void
    {
        $missing = [];

        foreach (self::COVERED as $relative) {
            $path = dirname(__DIR__, 2).'/app/Domains/'.$relative;

            $this->assertFileExists($path, "a covered controller moved: {$relative}");

            $source = (string) file_get_contents($path);

            $caps = preg_match_all('/->limit\(/', $source);
            $counts = preg_match_all('/->count\(\)/', $source);

            if ($caps > 0 && $counts === 0) {
                $missing[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These controllers bound a list and never count it, so the reader cannot tell a\n"
            ."truncated set from a complete one:\n  ".implode("\n  ", $missing),
        );
    }

    /**
     * And the ones that state a bound say BOTH numbers.
     *
     * A `total` alone leaves the reader to subtract; `withheld` is what a surface renders when it
     * says «the oldest are not listed», and it is the field that makes «nothing was held back» a
     * statement rather than an absence.
     */
    public function test_the_bounded_lists_report_total_and_withheld(): void
    {
        $incomplete = [];

        foreach (self::COVERED as $relative) {
            $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Domains/'.$relative);

            if (! str_contains($source, '->limit(')) {
                continue;
            }

            $hasTotal = str_contains($source, "'total'") || str_contains($source, "'history_total'");
            $hasWithheld = str_contains($source, 'withheld');

            if (! $hasTotal || ! $hasWithheld) {
                $incomplete[] = $relative;
            }
        }

        $this->assertSame([], $incomplete, 'a bounded list reports one number where it owes two');
    }
}
