<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SHARED-PDF-HIDE-FLAGS-001 — a shared link that hides spend or revenue never prints them in its PDF.
 *
 * The CSV and XLSX a client downloads are built from the share-filtered document. The PDF was not:
 * the download handed Chromium a print token naming only the REPORT, and the print route served the
 * report's stored, unfiltered data — so `hide_spend` held on the page and in the spreadsheet and not
 * in the file a client is most likely to forward.
 *
 * What is asserted is what Chromium would actually be served: the download runs for real up to the
 * point it mints its print context (Chromium itself cannot run here), and that exact context is then
 * read back through the print route, for both layouts. Figures come from a report GENERATED over
 * real metrics, so every money-bearing section the generator writes is exercised, not a hand-made one.
 */
final class SharedPdfHonoursHideFlagsTest extends TestCase
{
    use RefreshDatabase;

    private const SPEND = 4321.87;

    private const REVENUE = 98782.43;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'H', 'slug' => 'hide-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($project->id);
        $campaign = UnifiedCampaign::create(['project_id' => $project->id, 'name' => 'Sale', 'status' => 'active', 'objective' => 'sales']);

        $rows = [
            ['meta', 'spend', 3000.00, 1], ['snapchat', 'spend', 1321.87, 2],
            ['meta', 'revenue', 90017.00, 1], ['snapchat', 'revenue', 8765.43, 2],
            ['meta', 'impressions', 200000, 1], ['snapchat', 'impressions', 91000, 2],
            ['meta', 'clicks', 5000, 1], ['snapchat', 'clicks', 2100, 2],
            ['meta', 'conversions', 70, 1], ['snapchat', 'conversions', 17, 2],
        ];
        foreach ($rows as [$provider, $key, $value, $daysAgo]) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id, 'unified_campaign_id' => $campaign->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'provider' => $provider, 'metric_key' => $key, 'metric_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
                'value' => $value, 'original_amount' => in_array($key, ['spend', 'revenue'], true) ? $value : null,
                'original_currency' => 'SAR', 'project_currency' => 'SAR', 'exchange_rate' => 1,
            ]);
        }

        $this->report = Report::create([
            'project_id' => $project->id, 'name' => 'Monthly', 'type' => 'performance', 'status' => 'draft',
            'audience' => 'client', 'currency' => 'SAR', 'campaign_objective' => 'sales',
            'period_start' => Carbon::today()->subDays(7)->toDateString(), 'period_end' => Carbon::today()->toDateString(),
        ]);
        $data = app(ReportGenerator::class)->generate($this->report);
        $this->report->update(['data' => $data, 'status' => 'completed', 'generated_at' => now()]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /**
     * Run a shared-link PDF download for real, capture the print context it mints, and read back what
     * the print route would serve Chromium for it.
     *
     * @param  array<string, mixed>  $share
     * @return array<string, mixed>
     */
    private function printedFor(array $share, string $layout): array
    {
        $this->report->forceFill(['config' => ['pdf_type' => $layout]])->saveQuietly();
        config(['reports.chromium.enabled' => true, 'reports.chromium.node_bin' => '/nonexistent/node-that-cannot-run']);

        [, $raw] = app(ShareService::class)->create($this->report, ['allow_download' => true] + $share, null);

        $minted = null;
        Cache::spy();
        $this->get("/api/v1/reports/shared/{$raw}/download/pdf");
        Cache::shouldHaveReceived('put')->withArgs(function ($key, $value) use (&$minted): bool {
            if (is_string($key) && str_starts_with($key, 'report-print:') && is_array($value)) {
                $minted = $value;

                return true;
            }

            return false;
        });
        $this->assertIsArray($minted, 'the download minted no print context');

        // The spy replaced the store; restore a real one and hand the print route the same context.
        Cache::clearResolvedInstance('cache');
        Cache::swap(app('cache'));
        $token = 'captured-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), $minted, 300);

        return $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data');
    }

    /**
     * Every value under a hidden key, at any depth.
     *
     * @param  list<string>  $hidden
     * @return list<string>
     */
    private function leaks(array $node, array $hidden, string $path = ''): array
    {
        $found = [];
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";
            if (is_string($key) && in_array($key, $hidden, true) && is_numeric($value) && (float) $value !== 0.0) {
                $found[] = "{$here}={$value}";
            }
            if (is_array($value)) {
                $found = [...$found, ...$this->leaks($value, $hidden, $here)];
            }
        }

        return $found;
    }

    /**
     * A hidden figure in any spelling a document could print it: raw, grouped, two decimals.
     *
     * @param  list<float>  $figures
     */
    private function assertNoFigure(array $data, array $figures, string $layout): void
    {
        $text = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '';
        foreach ($figures as $figure) {
            foreach (array_unique([(string) $figure, number_format($figure, 2), number_format($figure, 2, '.', '')]) as $spelling) {
                $at = preg_match('/(?<![\d.,])'.preg_quote($spelling, '/').'(?![\d])/', $text, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
                $this->assertFalse($at !== false, "{$layout}: «{$spelling}» is in the file: ".($at === false ? '' : substr($text, max(0, $at - 160), 220)));
            }
        }
    }

    public function test_the_fixture_prints_money_when_nothing_is_hidden(): void
    {
        $data = $this->printedFor([], 'document');

        $this->assertNotSame([], $this->leaks($data, ['spend']), 'the unhidden PDF carries no spend, so the hidden cases prove nothing');
        $this->assertNotSame([], $this->leaks($data, ['revenue']), 'the unhidden PDF carries no revenue, so the hidden cases prove nothing');
    }

    public function test_a_link_hiding_spend_prints_no_spend_in_either_layout(): void
    {
        $hidden = [...CreativeVisibility::COST_METRICS, ...CreativeVisibility::MONEY_COMPANIONS['spend']];

        foreach (['document', 'presentation'] as $layout) {
            $data = $this->printedFor(['hide_spend' => true], $layout);

            $this->assertSame([], $this->leaks($data, $hidden), "{$layout}: the PDF of a link hiding spend prints it");
            $this->assertNoFigure($data, [self::SPEND, 3000.00, 1321.87], $layout);
            $this->assertSame([], $this->leaks($data, ['funnel_spend', 'cost_per']), "{$layout}: the funnel's spend is in the file");
            $this->assertNotSame([], $this->leaks($data, ['impressions']), "{$layout}: the file lost its unhidden figures too");
        }
    }

    public function test_a_link_hiding_revenue_prints_no_revenue_in_either_layout(): void
    {
        $hidden = [...CreativeVisibility::REVENUE_METRICS, ...CreativeVisibility::MONEY_COMPANIONS['revenue']];

        foreach (['document', 'presentation'] as $layout) {
            $data = $this->printedFor(['hide_revenue' => true], $layout);

            $this->assertSame([], $this->leaks($data, $hidden), "{$layout}: the PDF of a link hiding revenue prints it");
            $this->assertNoFigure($data, [self::REVENUE, 90017.00, 8765.43], $layout);
        }
    }

    /**
     * The spreadsheet a link hiding spend delivers exists, and carries no spend.
     *
     * The readiness gate judged the link's REDACTED copy, read its null spend as «results with zero
     * spend», and refused: every file of a link hiding spend answered 422.
     */
    public function test_a_link_hiding_spend_still_delivers_its_csv_without_spend(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, ['allow_download' => true, 'hide_spend' => true], null);

        $response = $this->get("/api/v1/reports/shared/{$raw}/download/csv");
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertMatchesRegularExpression('/291,?000/', $csv, 'the CSV lost its unhidden figures');
        foreach ([self::SPEND, 3000.00, 1321.87] as $figure) {
            $this->assertDoesNotMatchRegularExpression('/(?<![\d.,])'.preg_quote(number_format($figure, 2, '.', ''), '/').'(?![\d])/', $csv);
            $this->assertStringNotContainsString(number_format($figure, 2), $csv);
        }
    }

    /** The snapshot page of the same link is held to the same rule — the file must not say more than the page. */
    public function test_the_snapshot_page_of_a_hiding_link_carries_neither(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, ['hide_spend' => true, 'hide_revenue' => true], null);
        $data = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');

        $hidden = [...CreativeVisibility::COST_METRICS, ...CreativeVisibility::REVENUE_METRICS, 'spend_original', 'revenue_original'];
        $this->assertSame([], $this->leaks($data, $hidden));
        $this->assertStringNotContainsString((string) self::SPEND, json_encode($data));
        $this->assertStringNotContainsString((string) self::REVENUE, json_encode($data));
    }

    /** A context minted for a link revoked (or closed to downloads) before Chromium fetches it serves nothing. */
    public function test_a_print_context_re_checks_its_link(): void
    {
        $this->printedFor(['hide_spend' => true], 'document');
        $share = ReportShare::withoutGlobalScopes()->latest('created_at')->first();
        $context = ['report_id' => (string) $this->report->id, 'type' => 'document', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->id];

        $read = function (array $ctx) {
            $token = 'ctx-'.uniqid();
            Cache::put('report-print:'.hash('sha256', $token), $ctx, 300);

            return $this->getJson("/api/v1/reports/print/{$token}");
        };

        $read($context)->assertOk();

        $share->forceFill(['allow_download' => false])->save();
        $read($context)->assertNotFound();

        $share->forceFill(['allow_download' => true, 'revoked_at' => now()])->save();
        $read($context)->assertNotFound();

        $share->forceFill(['revoked_at' => null])->save();
        $other = $this->report->replicate();
        $other->save();
        $read(['report_id' => (string) $other->id] + $context)->assertNotFound();
    }
}
