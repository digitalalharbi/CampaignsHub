<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * SHARED-PDF-HIDE-FLAGS-001 — the permanent guard: a link that hides spend or revenue discloses
 * neither, nor anything derived from them, on ANY surface a client can reach.
 *
 * ## The oracle is the unhidden link, not a list of keys
 *
 * The same report is shared twice: once hiding nothing, once hiding the figure. Every money figure the
 * OPEN link carries — under any key this test calls money (spend, revenue, ROAS, CPA, cost per result,
 * cost per funnel stage, blended figures, budget spend, and the values of findings and notes that state
 * one) — is collected in every spelling a surface prints it: raw, two decimals, grouped, whole. None of
 * them may appear on the HIDING link's snapshot page, live payload, content drill-down, printed PDF
 * document (both layouts), CSV or XLSX. Numbers that also appear under a figure the link does NOT hide
 * are excluded, so a click count equal to a spend cannot fail it by coincidence.
 *
 * The key list below is written here on purpose rather than imported from the sanitizer: a guard that
 * reads the implementation's list cannot catch the key the implementation forgot.
 */
final class SharedLinkHiddenMoneyGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Everything spend is, or that gives spend back beside a figure the link still shows. */
    private const SPEND_DERIVED = [
        'spend', 'spend_original', 'cpc', 'cpm', 'cpa', 'cpl', 'cpi', 'cpe', 'cac', 'cost_per_view', 'cost_per_lpv',
        'cost_per_result', 'cost_per', 'funnel_spend', 'blended_cpa', 'roas', 'blended_roas', 'attributed_roas',
        'spent', 'remaining', 'daily_average', 'over_under', 'projected_spend', 'excluded_spend', 'includes_non_sales_spend',
    ];

    /** Everything revenue is, or that gives revenue back. */
    private const REVENUE_DERIVED = [
        'revenue', 'revenue_original', 'gross_revenue', 'attributed_revenue', 'roas', 'blended_roas', 'attributed_roas', 'aov',
    ];

    private Report $report;

    private Project $project;

    /** @var list<string> */
    private array $campaigns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'G', 'slug' => 'guard-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        // Three campaigns: two that sell, and one burning spend without results — the case whose
        // recommendation states a spend figure in prose.
        $plan = [
            'Sale meta' => ['meta', ['spend' => 3137.41, 'revenue' => 90017.00, 'impressions' => 200000, 'clicks' => 5000, 'conversions' => 70]],
            'Sale snap' => ['snapchat', ['spend' => 1321.87, 'revenue' => 8765.43, 'impressions' => 91000, 'clicks' => 2100, 'conversions' => 17]],
            'Burn tiktok' => ['tiktok', ['spend' => 3673.19, 'revenue' => 0, 'impressions' => 61000, 'clicks' => 900, 'conversions' => 1]],
        ];
        foreach ($plan as $name => [$provider, $figures]) {
            $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => $name, 'status' => 'active', 'objective' => 'sales']);
            $this->campaigns[] = $campaign->id;
            foreach ($figures as $key => $value) {
                DailyMetric::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'unified_campaign_id' => $campaign->id,
                    'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                    'provider' => $provider, 'metric_key' => $key, 'metric_date' => Carbon::today()->subDays(2)->toDateString(),
                    'value' => $value, 'original_amount' => in_array($key, ['spend', 'revenue'], true) ? $value : null,
                    'original_currency' => 'SAR', 'project_currency' => 'SAR', 'exchange_rate' => 1,
                ]);
            }
            foreach (['A', 'B'] as $i => $suffix) {
                $creative = ExternalCreative::create([
                    'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $campaign->id,
                    'provider' => $provider, 'external_creative_id' => 'cr-'.Str::random(8),
                    'name' => "{$name} {$suffix}", 'format' => 'image', 'status' => 'active', 'last_active_at' => Carbon::today()->subDays(2),
                ]);
                $share = $i === 0 ? 0.64 : 0.36;
                DB::table('creative_daily_metrics')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'project_id' => $this->project->id,
                    'creative_id' => $creative->id, 'metric_date' => Carbon::today()->subDays(2)->toDateString(),
                    'spend' => round($figures['spend'] * $share, 2), 'impressions' => (int) ($figures['impressions'] * $share),
                    'clicks' => (int) ($figures['clicks'] * $share), 'conversions' => (int) round($figures['conversions'] * $share),
                    'revenue' => round($figures['revenue'] * $share, 2), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'Monthly', 'type' => 'performance', 'status' => 'draft',
            'audience' => 'client', 'currency' => 'SAR', 'campaign_objective' => 'sales',
            'period_start' => Carbon::today()->subDays(7)->toDateString(), 'period_end' => Carbon::today()->toDateString(),
        ]);
        $this->report->update(['data' => app(ReportGenerator::class)->generate($this->report), 'status' => 'completed', 'generated_at' => now()]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param array<string, mixed> $flags @return array{0: string, 1: string} snapshot token, live token */
    private function links(array $flags): array
    {
        $base = ['allow_download' => true] + $flags;
        [, $snapshot] = app(ShareService::class)->create($this->report, $base + ['mode' => 'snapshot'], null);
        [, $live] = app(ShareService::class)->create($this->report, $base + ['mode' => 'live', 'scope' => [
            'project_id' => $this->project->id, 'campaign_ids' => $this->campaigns, 'providers' => ['meta', 'snapchat', 'tiktok'],
            'earliest' => Carbon::today()->subDays(30)->toDateString(), 'latest' => Carbon::today()->toDateString(),
        ]], null);

        return [$snapshot, $live];
    }

    /** Every surface a client of these links can reach, as text. @return array<string, string> */
    private function surfaces(string $snapshot, string $live): array
    {
        $json = static fn ($v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = [];

        $out['snapshot page'] = $json($this->getJson("/api/v1/reports/shared/{$snapshot}")->assertOk()->json());
        $out['live shell'] = $json($this->getJson("/api/v1/reports/shared/{$live}")->assertOk()->json());
        $payload = $this->getJson("/api/v1/reports/shared/{$live}/live")->assertOk()->json();
        $out['live payload'] = $json($payload);
        foreach (collect($payload['data']['ads_roster'] ?? [])->pluck('content_key')->filter()->take(3) as $key) {
            $out["live content {$key}"] = $json($this->getJson("/api/v1/reports/shared/{$live}/live/content/{$key}")->assertOk()->json());
        }

        foreach (['snapshot' => $snapshot, 'live' => $live] as $kind => $token) {
            foreach (['document', 'presentation'] as $layout) {
                $out["{$kind} PDF {$layout}"] = $json($this->printed($token, $layout));
            }
            $csv = $this->get("/api/v1/reports/shared/{$token}/download/csv");
            $csv->assertOk();
            $out["{$kind} CSV"] = $csv->streamedContent();
            $xlsx = $this->get("/api/v1/reports/shared/{$token}/download/xlsx");
            $xlsx->assertOk();
            $out["{$kind} XLSX"] = $this->xlsxText($xlsx->streamedContent());
        }

        return $out;
    }

    /** What Chromium is served for this link's PDF, in one layout. @return array<string, mixed> */
    private function printed(string $token, string $layout): array
    {
        $this->report->forceFill(['config' => ['pdf_type' => $layout]])->saveQuietly();
        config(['reports.chromium.enabled' => true, 'reports.chromium.node_bin' => '/nonexistent/node-that-cannot-run']);

        $minted = null;
        Cache::spy();
        $this->get("/api/v1/reports/shared/{$token}/download/pdf");
        Cache::shouldHaveReceived('put')->withArgs(function ($key, $value) use (&$minted): bool {
            if (is_string($key) && str_starts_with($key, 'report-print:') && is_array($value)) {
                $minted = $value;

                return true;
            }

            return false;
        });
        Cache::clearResolvedInstance('cache');
        Cache::swap(app('cache'));
        config(['reports.chromium.enabled' => false]);

        $this->assertIsArray($minted, "the {$layout} PDF download minted no print context");
        $captured = 'guard-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $captured), $minted, 300);

        return $this->getJson("/api/v1/reports/print/{$captured}")->assertOk()->json();
    }

    private function xlsxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'guard').'.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the XLSX is not a zip');
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with((string) $zip->getNameIndex($i), '.xml')) {
                $text .= (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($path);

        return $text;
    }

    /**
     * Collect every money figure under `$money` keys, and every number under any other key.
     *
     * Prose values count as money too: a finding's `value` and a platform note's sentence are the
     * figures written out, so their numbers are collected when they sit beside a money `kpi`/metric.
     *
     * @param  list<string>  $money
     * @param  array<string, float>  $figures
     * @param  array<string, true>  $others
     */
    private function collect(mixed $node, array $money, array &$figures, array &$others, bool $moneyContext = false): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            $isMoney = is_string($key) && in_array($key, $money, true);
            if (is_numeric($value) && ! is_string($value)) {
                if ($isMoney || $moneyContext) {
                    $figures[(string) (float) $value] = (float) $value;
                } else {
                    $others[(string) (float) $value] = true;
                }
            } elseif (is_string($value) && in_array($key, ['strengths', 'weaknesses', 'value', 'reason', 'detail'], true) && $this->proseStates($node, $value, $money)) {
                // Figures written into prose: «(5.21×)», «3,673 SAR». Collected only where the sentence
                // is about a figure this link hides — a spend sentence is not a leak on a revenue-hiding link.
                preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $value, $m);
                foreach ($m[0] as $n) {
                    $f = (float) str_replace(',', '', $n);
                    $figures[(string) $f] = $f;
                }
            }
            if (is_array($value)) {
                $this->collect($value, $money, $figures, $others, $moneyContext || ($isMoney && is_array($value)));
            }
        }
    }

    /**
     * Whether a sentence states a figure this link hides.
     *
     * A multiplier is a return (ROAS, in both lists). Otherwise the sentence's own KPI label decides —
     * «الإنفاق» is spend, «الإيرادات» revenue — and an amount in currency with no label is a cost.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $money
     */
    private function proseStates(array $node, string $text, array $money): bool
    {
        if (str_contains($text, '×')) {
            return in_array('roas', $money, true);
        }
        if (! str_contains($text, 'SAR')) {
            return false;
        }
        $kpi = is_string($node['kpi'] ?? null) ? $node['kpi'] : null;

        return match ($kpi) {
            'الإيرادات' => in_array('revenue', $money, true),
            null, 'الإنفاق' => in_array('spend', $money, true),
            default => in_array('cpa', $money, true),
        };
    }

    /** @return list<string> */
    private function spellings(float $v): array
    {
        $s = [number_format($v, 2, '.', ''), number_format($v, 2), rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')];
        if (abs($v) >= 100) {
            $s[] = number_format($v, 0, '.', '');
            $s[] = number_format($v, 0);
        }
        if (abs($v) >= 1000) {
            $s[] = rtrim(rtrim(number_format($v / 1000, 1, '.', ''), '0'), '.').'K';
        }

        // Too few significant digits to be evidence of anything: «1.5» is a CTR as easily as a ROAS.
        return array_values(array_unique(array_filter($s, static fn (string $x): bool => strlen(ltrim(preg_replace('/\D/', '', $x) ?? '', '0')) >= 3)));
    }

    /** @param list<string> $money */
    private function assertHides(array $flags, array $money): void
    {
        [$openSnapshot, $openLive] = $this->links([]);
        $figures = [];
        $others = [];
        foreach ($this->surfaces($openSnapshot, $openLive) as $name => $text) {
            if (str_contains($name, 'CSV') || str_contains($name, 'XLSX')) {
                continue;
            }
            $this->collect(json_decode($text, true), $money, $figures, $others);
        }
        $figures = array_filter($figures, static fn (float $f, string $k): bool => ! isset($others[$k]) && abs($f) >= 1, ARRAY_FILTER_USE_BOTH);
        $this->assertGreaterThan(10, count($figures), 'the open link carries almost no money, so the hidden link proves nothing');

        [$snapshot, $live] = $this->links($flags);
        $leaks = [];
        foreach ($this->surfaces($snapshot, $live) as $name => $text) {
            foreach ($figures as $figure) {
                foreach ($this->spellings($figure) as $spelling) {
                    if (preg_match('/(?<![\d.,])'.preg_quote($spelling, '/').'(?![\d]|\.\d)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                        $at = $m[0][1];
                        $leaks[] = "{$name}: «{$spelling}» … ".substr($text, max(0, $at - 90), 130);
                        break;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($leaks)), 'a hidden figure reached a client surface');
    }

    public function test_a_link_hiding_spend_discloses_no_spend_or_anything_derived_from_it_anywhere(): void
    {
        $this->assertHides(['hide_spend' => true], self::SPEND_DERIVED);
    }

    public function test_a_link_hiding_revenue_discloses_no_revenue_or_anything_derived_from_it_anywhere(): void
    {
        $this->assertHides(['hide_revenue' => true], self::REVENUE_DERIVED);
    }

    /**
     * A report generated before its sentences said what they reveal is held to the same rule.
     *
     * Every stored snapshot in production predates `reveals`, `neutral` and `reason_basis`. With no
     * statement of what a sentence reveals, a link hiding money does not print it — fail closed.
     */
    public function test_a_report_generated_before_the_reveals_tags_still_hides_everything(): void
    {
        $strip = function (mixed $node) use (&$strip): mixed {
            if (! is_array($node)) {
                return $node;
            }
            unset($node['reveals'], $node['neutral'], $node['reason_basis']);

            return array_map($strip, $node);
        };
        $this->report->forceFill(['data' => $strip($this->report->data)])->saveQuietly();
        $this->assertStringNotContainsString('reason_basis', (string) json_encode($this->report->fresh()->data));

        $this->assertHides(['hide_spend' => true], self::SPEND_DERIVED);
    }
}
