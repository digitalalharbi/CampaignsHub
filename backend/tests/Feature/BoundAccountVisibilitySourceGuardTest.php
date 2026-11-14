<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — every reader asks ONE class which accounts a project may show.
 *
 * The rule «a row is this project's only while its account is selected for this project» is one
 * predicate in `BoundAccountVisibility`. A reader that restates it drifts from it; a reader that omits
 * it is the defect this whole unit closes — and the next surface written against `daily_metrics`,
 * `entity_daily_metrics`, `creative_daily_metrics` or `external_creatives` is the one nobody
 * remembers to check. So the guard is inverted: every file that reads those tables must reference the
 * class, unless it is named here with the reason it must see every row.
 */
final class BoundAccountVisibilitySourceGuardTest extends TestCase
{
    private const READS = '/DailyMetric::|DB::table\(\'daily_metrics\'\)|EntityDailyMetric::|DB::table\(\'entity_daily_metrics\'\)|DB::table\(\'creative_daily_metrics\'\)|ExternalCreative::|DB::table\(\'external_creatives\'\)/';

    /**
     * Files that read those tables and deliberately do NOT narrow, each with its reason.
     *
     * @var array<string, string>
     */
    private const SEES_EVERY_ROW = [
        // The cleanup acts on the rows the rule HIDES, so it must see them all; it refuses a selected account itself.
        'Domains/Integrations/Console/ScopeCleanupCommand.php' => 'the cleanup, which acts on hidden rows and refuses selected ones',
        'Domains/Integrations/Console/AccountOwnershipAuditCommand.php' => 'audit and quarantine of every stored row, including hidden ones; serves no reader',
        // Writers and the sync pipeline resolve ids against everything the account holds.
        'Domains/Metrics/Services/AccountMetricsSyncer.php' => 'writer',
        'Domains/Metrics/Actions/UpsertDailyMetrics.php' => 'writer',
        'Domains/Campaigns/Actions/UpsertCreativeDailyMetrics.php' => 'writer, scoped to the syncing account through its campaigns',
        'Domains/Campaigns/Actions/ImportExternalStructure.php' => 'writer',
        // META-MEDIA-RENEWAL-001 — runs only inside a structure sync, which has already proved the assignment. Rewrites links; returns no figure, for no viewer.
        'Domains/Campaigns/Actions/RenewExpiredCreativeMedia.php' => 'writer, scoped to the syncing account through its campaigns; reads and rewrites media links, never a figure',
        // Client-facing creative lists: every one starts a bare creative query and hands it to
        // `CreativeRows::applyFilters()`, which applies the rule — `BoundAccountVisibilityTest` proves
        // the live link and the snapshot agree with the dashboard about a deselected account.
        'Domains/Reports/Services/LiveReportService.php' => 'narrowed by CreativeRows::applyFilters()',
        'Domains/Reports/Services/ReportAds.php' => 'narrowed by CreativeRows::applyFilters()',
        'Domains/Reports/Services/SharedCreativeView.php' => 'narrowed by CreativeRows::applyFilters()',
        'Domains/Integrations/Providers/MetaConnector.php' => 'connector',
        // REACH-PERIOD-001 — decides whether an ad account's provider reach describes a scope, which
        // requires every campaign the account delivered in ANY project; a narrower read would let an
        // account figure stand for a scope that is only part of its audience. It returns no figure.
        'Domains/Metrics/Services/PeriodReachReader.php' => 'checks the whole account\'s delivery before an account reach may be used',
        // Maintenance and diagnostics that must see the whole table to report or repair it.
        'Domains/Metrics/Console/RenormaliseReportingCurrency.php' => 'renormalises every stored row',
        'Domains/Metrics/Rates/CurrencyRateFeed.php' => 'which currencies exist, not whose figures',
        'Domains/Integrations/Console/DiagnoseSyncCommand.php' => 'read-only diagnosis of what is STORED',
        'Domains/Integrations/Console/ProbeInsightsCommand.php' => 'read-only probe',
        // META-NO-DATA-READING-001 — the sync pipeline asks, for the account it is syncing, the last day that account holds
        // any stored figure, to say «no delivery since …» on its own run record. A date, never a figure, for the operator's
        // diagnosis and no project viewer; it must see every stored row of the account or «never delivered» would be a guess.
        'Domains/Metrics/Support/NoDataReading.php' => 'the last stored day of the syncing account, for its own run record; a date, never a figure',
        'Domains/Campaigns/Console/ReconcileContentMetricsCommand.php' => 'walks one creative through the product\'s own services, which apply the rule',
        'Domains/Campaigns/Console/ContentDefectCensusCommand.php' => 'lists through CreativeRows::present(), which applies the rule',
        'Domains/Campaigns/Console/SnapchatLpvProvenanceCommand.php' => 'counts stored rows by provenance',
        // TIKTOK-MEDIA-002 — the cover cache reads a creative's stored media URL to fetch a file; no figure, no viewer.
        // CREATIVE-PREVIEWS-001 — the pass moved to `creatives:backfill-covers`; the old name only calls it.
        'Domains/Campaigns/Console/BackfillCreativeCoversCommand.php' => 'caches media files for every creative that lacks one and counts cover states per platform; reads media URLs and cover columns, never a figure, for no viewer',
        'Domains/Campaigns/Jobs/CacheCreativeCoverJob.php' => 'fetches one creative\'s media file off the request path; reads its media URL, never a figure, for no viewer',
        'Console/Commands/DemoRemoveCommand.php' => 'removes seeded rows',
        // META-SAAS-APP-001 — the admin's evidence that the SaaS test workspace's OWN accounts received a metric row; an existence
        // check scoped to that workspace's connection, never a figure, for no project viewer.
        'Domains/Integrations/MetaCandidate/MetaSaasTestWorkspace.php' => 'existence check on the test workspace\'s own accounts; reads no figure',
        // Models, relations and policies — not readers of a project's figures.
        'Domains/Campaigns/Models/ExternalAd.php' => 'relation',
        'Domains/Campaigns/Models/CreativeGroup.php' => 'relation',
        'Domains/Campaigns/Services/CreativePresenter.php' => 'presents one creative it was handed',
        'Domains/Campaigns/Support/CreativeDemoPolicy.php' => 'the demo policy predicate, applied beside this one',
        'Domains/Reports/Services/ReportCreativeMedia.php' => 'resolves media for creatives a report already names',
        // REPORT-SOURCE-ISOLATION-001 — places creatives a STORED report already names inside or outside that report's own ad accounts,
        // to drop the ones outside. It can only remove rows, returns no figure, and deliberately does not apply today's selection: a
        // stored copy states what ran when it was made, and an account deselected since must not empty a document already sent.
        'Domains/Reports/Services/ReportSourceContainment.php' => 'narrows a stored report to its own ad accounts; removes rows only, reads no figure',
        // SEC-CLUSTER-4 — proves a Drive attachment target is a creative of this tenant inside the caller's client ceiling; reads the creative's project id only.
        // CLIENT-CONTENT-APPROVAL-001 — the model's relation, and the service that checks a creative an agency operator NAMED belongs to
        // the approval's own project and tenant, then hands the client that creative's format and https still. Never a figure.
        'Domains/ClientWorkspaces/Models/ContentApproval.php' => 'relation',
        'Domains/ClientWorkspaces/Services/ContentApprovals.php' => 'checks a named creative is inside the approval\'s own project and reads its format and still; reads no figure',
        'Domains/Drive/Services/DriveReach.php' => 'resolves which project a creative belongs to so an attach target can be checked against the caller\'s reach; returns no figure',
    ];

    public function test_every_reader_of_a_figure_table_asks_the_one_visibility_rule(): void
    {
        $root = app_path();
        $offenders = [];
        $seen = 0;

        foreach (File::allFiles($root) as $file) {
            $relative = str_replace($root.'/', '', $file->getPathname());
            $source = $file->getContents();

            if (preg_match(self::READS, $source) !== 1) {
                continue;
            }

            $seen++;

            if (array_key_exists($relative, self::SEES_EVERY_ROW)) {
                continue;
            }

            if (! str_contains($source, 'BoundAccountVisibility')) {
                $offenders[] = $relative;
            }
        }

        $this->assertGreaterThan(10, $seen, 'the pattern matched almost nothing — the guard is not looking at the readers');
        $this->assertSame([], $offenders, "these files read a figure table without asking BoundAccountVisibility:\n  ".implode("\n  ", $offenders));
    }

    public function test_the_exemption_list_names_only_files_that_exist_and_still_read(): void
    {
        foreach (array_keys(self::SEES_EVERY_ROW) as $relative) {
            $path = app_path($relative);

            $this->assertFileExists($path, "{$relative} is exempted and no longer exists");
            $this->assertMatchesRegularExpression(self::READS, File::get($path), "{$relative} is exempted and no longer reads a figure table — remove the exemption");
        }
    }
}
