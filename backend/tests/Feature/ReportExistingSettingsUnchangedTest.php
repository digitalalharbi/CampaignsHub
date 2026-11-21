<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Sections\ReportAutoComposer;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * REPORT-GENERIC-LABELS-001 and REPORT-AUTO-COMPOSE-001 — a report or link whose stored settings never chose either renders what it
 * rendered before they existed.
 *
 * `tests/Fixtures/report_existing_settings_baseline.json` was written by the code as it stood BEFORE both (`3b834b972`, with their
 * source reverse-applied), over stored settings of the kinds real links carry: sections switched off, narrowed main sections, chosen
 * columns and cards, money hidden, the older campaign switch. Every payload here is held to it byte for byte, on the live page, a
 * drawer, the snapshot link and the data its files are built from.
 *
 * Re-write it only for an intended change: `WRITE_EXISTING_SETTINGS_BASELINE=1`, and say which paths moved.
 */
final class ReportExistingSettingsUnchangedTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private const BASELINE = __DIR__.'/../Fixtures/report_existing_settings_baseline.json';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->freezeWorld();
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    private function context(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        app(ProjectContext::class)->setProjectId((string) $this->project->id);
    }

    /** @param array<string, mixed> $attributes */
    private function storedLink(array $attributes, array $settings = [], array $scope = []): string
    {
        [$share, $raw] = $this->liveLink();
        $this->context();
        $share = ReportShare::query()->findOrFail($share->id);
        $share->forceFill([
            'settings' => $settings + (array) $share->settings,
            'scope' => $scope + (array) $share->scope,
        ] + $attributes)->save();
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $raw;
    }

    /** @return array<string, mixed> */
    private function publicData(string $path): array
    {
        $data = $this->getJson($path)->assertOk()->json('data');

        return self::normalised($data);
    }

    /** The per-read and per-record values, none of which is something a reader is shown as a figure. */
    private static function normalised(array $data): array
    {
        foreach (['report_trace', 'trace_mark', 'share_id', 'report_id', 'generated_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = 'X';
            }
        }
        if (isset($data['settings']['trace_mark'])) {
            $data['settings']['trace_mark'] = 'X';
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalised($value);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function snapshotReport(array $sectionSettings, array $config = [], string $audience = 'client'): Report
    {
        $this->context();

        return Report::create([
            'project_id' => $this->project->id, 'name' => 'Snap', 'type' => 'executive', 'status' => 'completed', 'audience' => $audience,
            'form' => 'detailed', 'campaign_objective' => 'awareness', 'currency' => 'SAR', 'period_start' => self::WORLD_FROM, 'period_end' => self::WORLD_TO,
            'generated_at' => now(), 'section_settings' => $sectionSettings, 'config' => $config,
            'data' => [
                'period' => ['from' => self::WORLD_FROM, 'to' => self::WORLD_TO],
                'currency' => 'SAR',
                'objective' => 'awareness',
                'kpis' => ['spend' => 100.0, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 2, 'revenue' => 50.0],
                'metric_set' => ['impressions', 'clicks', 'spend', 'cpm'],
                'platforms' => [['provider' => 'meta', 'spend' => 100.0, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 2, 'revenue' => 50.0]],
                'timeseries' => [['date' => '2026-10-01', 'impressions' => 500, 'clicks' => 5], ['date' => '2026-10-02', 'impressions' => 500, 'clicks' => 5]],
                'funnel' => [['key' => 'impressions', 'value' => 1000, 'reported' => true]],
                'budget' => [],
                'campaigns' => [['campaign_id' => 'c1', 'campaign_name' => 'Spring', 'provider' => 'meta', 'spend' => 100.0, 'conversions' => 2]],
                'ads' => [['name' => 'Spring Ad', 'provider' => 'meta', 'campaign_name' => 'Spring', 'metrics' => ['clicks' => 5]]],
                'accounts' => [['provider' => 'meta', 'account_name' => 'Acct One', 'spend' => 100.0]],
                'observations' => [['title' => 'A note', 'detail' => 'Reach rose', 'scope' => ['type' => 'platform', 'name' => 'meta']]],
            ],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function payloads(): array
    {
        $out = [];

        $plain = $this->storedLink([]);
        $out['live_plain'] = $this->publicData("/api/v1/reports/shared/{$plain}/live");

        $narrowed = $this->storedLink(
            ['hide_spend' => true, 'hide_revenue' => true],
            ['section_overrides' => ['budget_pacing', 'daily_records'], 'views' => ['summary', 'platforms'], 'columns' => ['platforms' => ['results', 'impressions']]],
            ['metrics' => ['impressions', 'clicks']],
        );
        $out['live_narrowed'] = $this->publicData("/api/v1/reports/shared/{$narrowed}/live");
        $out['live_narrowed_drawer'] = $this->publicData("/api/v1/reports/shared/{$narrowed}/live/platform/meta");

        $older = $this->storedLink(['hide_campaign_names' => true], ['sections' => ['account_names' => true]]);
        $out['live_older_switch'] = $this->publicData("/api/v1/reports/shared/{$older}/live");

        $report = $this->snapshotReport(['sections' => ['trends' => false, 'detailed_tables' => true], 'views' => ['summary', 'tables']], ['columns' => ['days' => ['impressions']]]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true, 'hide_spend' => true], null);
        $out['snapshot_link'] = $this->publicData("/api/v1/reports/shared/{$raw}");
        $this->context();
        $out['snapshot_file'] = self::normalised(app(ReportExporter::class)->exportData(app(ShareService::class)->documentReport($report, $share), $share));
        $out['saved_file'] = self::normalised(app(ReportExporter::class)->exportData($report->fresh()));

        $internal = $this->snapshotReport(['sections' => ['detailed_tables' => true, 'account_breakdown' => true]], [], 'internal');
        $out['internal_saved_file'] = self::normalised(app(ReportExporter::class)->exportData($internal));

        return $out;
    }

    public function test_stored_settings_that_never_chose_either_render_what_they_rendered_before(): void
    {
        $now = array_map(static fn (array $p): string => (string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->payloads());
        $this->assertNotEmpty(json_decode($now['live_plain'], true)['campaign_rows'], 'the world reached nothing, so identity proves nothing');
        $this->assertNotEmpty(json_decode($now['internal_saved_file'], true)['campaigns'], 'the file reached nothing, so identity proves nothing');

        if (getenv('WRITE_EXISTING_SETTINGS_BASELINE') === '1') {
            file_put_contents(self::BASELINE, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestSkipped('Baseline written.');
        }

        $stored = json_decode((string) file_get_contents(self::BASELINE), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys($stored), array_keys($now));
        foreach ($now as $name => $json) {
            $this->assertSame($stored[$name], $json, "«{$name}» no longer renders what it did before these two features");
        }
    }

    public function test_a_proposal_alone_leaves_the_stored_settings_as_they_were(): void
    {
        $report = $this->snapshotReport(['sections' => ['trends' => false]]);
        $before = $report->fresh()->only(['section_settings', 'config', 'data', 'campaign_objective']);

        app(ReportAutoComposer::class)->propose($report, null);

        $this->assertSame($before, $report->fresh()->only(['section_settings', 'config', 'data', 'campaign_objective']));
    }
}
