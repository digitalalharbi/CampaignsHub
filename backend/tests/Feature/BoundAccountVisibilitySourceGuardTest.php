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
        // Writers and the sync pipeline resolve ids against everything the account holds.
        'Domains/Metrics/Services/AccountMetricsSyncer.php' => 'writer',
        'Domains/Metrics/Actions/UpsertDailyMetrics.php' => 'writer',
        'Domains/Campaigns/Actions/UpsertCreativeDailyMetrics.php' => 'writer, scoped to the syncing account through its campaigns',
        'Domains/Campaigns/Actions/ImportExternalStructure.php' => 'writer',
        'Domains/Metrics/Actions/UpsertEntityDailyMetrics.php' => 'writer',
        // Client-facing creative lists: every one starts a bare creative query and hands it to
        // `CreativeRows::applyFilters()`, which applies the rule — `BoundAccountVisibilityTest` proves
        // the live link and the snapshot agree with the dashboard about a deselected account.
        'Domains/Reports/Services/LiveReportService.php' => 'narrowed by CreativeRows::applyFilters()',
        'Domains/Reports/Services/ReportAds.php' => 'narrowed by CreativeRows::applyFilters()',
        'Domains/Reports/Services/SharedCreativeView.php' => 'narrowed by CreativeRows::applyFilters()',
        'Domains/Integrations/Providers/MetaConnector.php' => 'connector',
        // Maintenance and diagnostics that must see the whole table to report or repair it.
        'Domains/Metrics/Console/RenormaliseReportingCurrency.php' => 'renormalises every stored row',
        'Domains/Metrics/Rates/CurrencyRateFeed.php' => 'which currencies exist, not whose figures',
        'Domains/Integrations/Console/DiagnoseSyncCommand.php' => 'read-only diagnosis of what is STORED',
        'Domains/Integrations/Console/ProbeInsightsCommand.php' => 'read-only probe',
        'Domains/Campaigns/Console/ReconcileContentMetricsCommand.php' => 'walks one creative through the product\'s own services, which apply the rule',
        'Domains/Campaigns/Console/ContentDefectCensusCommand.php' => 'lists through CreativeRows::present(), which applies the rule',
        'Domains/Campaigns/Console/SnapchatLpvProvenanceCommand.php' => 'counts stored rows by provenance',
        'Console/Commands/DemoRemoveCommand.php' => 'removes seeded rows',
        // Models, relations and policies — not readers of a project's figures.
        'Domains/Campaigns/Models/ExternalAd.php' => 'relation',
        'Domains/Campaigns/Models/CreativeGroup.php' => 'relation',
        'Domains/Campaigns/Services/CreativePresenter.php' => 'presents one creative it was handed',
        'Domains/Campaigns/Support/CreativeDemoPolicy.php' => 'the demo policy predicate, applied beside this one',
        'Domains/Reports/Services/ReportCreativeMedia.php' => 'resolves media for creatives a report already names',
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
