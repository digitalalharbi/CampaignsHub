<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REPORT-PARITY-FOUR-SURFACES-001 / WD-PDF-001 — one saved configuration, four surfaces, one answer.
 *
 * The surfaces, as this test reaches them (all through the real routes and services, no stubs):
 *
 *   PREVIEW  the operator's own report: `GET projects/{p}/reports/{id}` (the payload `ReportPreview` draws) and
 *            `GET …/reports/{id}/sections` (the builder's «what the client gets» list).
 *   LINK     the client's shared link: `GET reports/shared/{token}` (and `/branding`).
 *   SAVED    the report's file export: `ReportExporter::exportData()` — the payload the CSV, the XLSX and the PDF are built from.
 *   PDF      what headless Chromium is served: `GET reports/print/{token}`, for the operator's own PDF (a token minted by the
 *            real `print-token` route) and for a link's PDF (the context the real download mints, captured and read back).
 *
 * One configuration is saved once — period, sections on and off, metrics chosen, branding, currency — and every surface is
 * asked about it. Nothing here decides a section set; it only compares what each surface decided.
 */
final class ReportFourSurfaceParityTest extends TestCase
{
    use RefreshDatabase;

    /** A campaign name the agency typed. It must reach no client surface — content names only. */
    private const CAMPAIGN = 'Zq-Internal-Burner-Campaign';

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private function world(string $currency = 'SAR'): void
    {
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency Co', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'Client Co', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => self::CAMPAIGN, 'status' => 'active', 'objective' => 'sales']);

        $rows = [
            ['meta', 'spend', 3000.00, 10], ['snapchat', 'spend', 1321.87, 11],
            ['meta', 'revenue', 90017.00, 10], ['meta', 'impressions', 200000, 10], ['snapchat', 'impressions', 91000, 11],
            ['meta', 'clicks', 5000, 10], ['snapchat', 'clicks', 2100, 11], ['meta', 'conversions', 70, 10], ['snapchat', 'conversions', 17, 11],
        ];
        foreach ($rows as [$provider, $key, $value, $day]) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'unified_campaign_id' => $campaign->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'provider' => $provider, 'metric_key' => $key, 'metric_date' => "2026-08-{$day}", 'value' => $value,
                'original_amount' => in_array($key, ['spend', 'revenue'], true) ? $value : null,
                'original_currency' => $currency, 'project_currency' => $currency, 'exchange_rate' => 1,
            ]);
        }
    }

    /**
     * One saved configuration: a fixed August window, the chosen metrics, sections off and on, a typed identity, a currency.
     *
     * @param  array<string, mixed>  $over  attributes of the report
     */
    private function saved(array $over = [], ?callable $mutate = null, string $currency = 'SAR', ?array $branding = ['prepared_for_name' => 'Nakheel Dates', 'prepared_by_name' => 'Studio Seven']): Report
    {
        $this->world($currency);
        $report = Report::create($over + [
            'project_id' => $this->project->id, 'name' => 'August', 'type' => 'performance', 'form' => 'detailed', 'status' => 'draft',
            'audience' => 'client', 'currency' => $currency, 'campaign_objective' => 'sales',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
            'section_settings' => ['sections' => ['trends' => false, 'recommendations' => false, 'budget_pacing' => false]],
            // The KPIs the operator picked, in the order picked (REPORT-KPI-CONTRACT-001).
            'scope' => ['metrics' => ['spend', 'clicks', 'impressions']],
        ]);
        $data = app(ReportGenerator::class)->generate($report);
        if ($mutate !== null) {
            $data = $mutate($data);
        }
        // The generator writes the deck into `config`; the identity the operator typed is saved beside it, as the names editor does.
        $config = (array) $report->fresh()->config;
        if ($branding !== null) {
            $config['branding'] = $branding;
        }
        $report->update(['data' => $data, 'status' => 'completed', 'generated_at' => now(), 'config' => $config]);

        return $report->fresh();
    }

    // ---------------------------------------------------------------- the four surfaces

    /** @return array{data: array<string, mixed>, sections: list<string>} */
    private function preview(Report $report): array
    {
        Sanctum::actingAs($this->owner);
        $page = $this->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}")->assertOk()->json('data');
        $builder = $this->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/sections")->assertOk()->json('data');

        return ['data' => $page['data'], 'sections' => $builder['visible']];
    }

    /** @return array{raw: string, share: ReportShare} */
    private function link(Report $report, array $opts = []): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        [$share, $raw] = app(ShareService::class)->create($report, $opts + ['allow_download' => true, 'hide_spend' => false], null);

        return ['raw' => $raw, 'share' => $share];
    }

    /** @return array<string, mixed> */
    private function linkPage(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function savedPayload(Report $report, ?ReportShare $share = null): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        // A link's file is built from the link's own document, exactly as the download route builds it.
        $source = $share === null ? $report->fresh() : app(ShareService::class)->documentReport($report->fresh(), $share);

        return app(ReportExporter::class)->exportData($source, $share);
    }

    /** The operator's own PDF: a token from the real print-token route, read back through the print route. @return array<string, mixed> */
    private function operatorPdf(Report $report): array
    {
        Sanctum::actingAs($this->owner);
        $token = $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/print-token", ['type' => 'presentation'])
            ->assertOk()->json('data.token');

        return $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data');
    }

    /** A link's PDF: run the real download up to the print context it mints (Chromium cannot run here), then read that back. @return array<string, mixed> */
    private function linkPdf(Report $report, string $raw): array
    {
        config(['reports.chromium.enabled' => true, 'reports.chromium.node_bin' => '/nonexistent/node-that-cannot-run']);
        $report->forceFill(['config' => array_merge((array) $report->config, ['pdf_type' => 'presentation'])])->saveQuietly();

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
        Cache::clearResolvedInstance('cache');
        Cache::swap(app('cache'));
        $token = 'captured-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), $minted, 300);

        return $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $payloads  surface name => payload
     */
    private function assertSameAt(string $key, array $payloads, string $what): void
    {
        $first = null;
        $firstName = null;
        foreach ($payloads as $name => $payload) {
            if ($first === null) {
                [$first, $firstName] = [$payload[$key] ?? null, $name];

                continue;
            }
            $this->assertEquals($first, $payload[$key] ?? null, "{$what}: «{$name}» differs from «{$firstName}» at `{$key}`");
        }
    }

    /** @return list<string> */
    private function slideTypes(array $payload): array
    {
        return array_values(array_map(static fn ($s): string => (string) ($s['type'] ?? ''), (array) ($payload['slides'] ?? [])));
    }

    // ---------------------------------------------------------------- 1. the section set

    public function test_one_saved_configuration_shows_and_hides_the_same_sections_on_all_four_surfaces(): void
    {
        $report = $this->saved();
        $link = $this->link($report);

        $preview = $this->preview($report);
        $page = $this->linkPage($link['raw']);
        $operatorPdf = $this->operatorPdf($report);
        $linkPdf = $this->linkPdf($report, $link['raw']);
        $file = $this->savedPayload($report);
        $linkFile = $this->savedPayload($report, $link['share']);

        $expected = $page['data']['report_sections'];
        // The configuration really does hide something, and really does show something — otherwise equality proves nothing.
        $this->assertNotContains('trends', $expected);
        $this->assertNotContains('recommendations', $expected);
        $this->assertContains('kpis', $expected);
        $this->assertContains('platform_comparison', $expected);

        $this->assertSame($expected, $preview['sections'], 'PREVIEW (builder list) ≠ LINK');
        $this->assertSame($expected, $preview['data']['report_sections'] ?? null, 'PREVIEW (the page the operator reads) carries no section set, or a different one');
        $this->assertSame($expected, $operatorPdf['data']['report_sections'] ?? null, 'PDF (operator) ≠ LINK');
        $this->assertSame($expected, $linkPdf['data']['report_sections'] ?? null, 'PDF (link) ≠ LINK');
        $this->assertSame($expected, $file['report_sections'] ?? null, 'SAVED (report file) ≠ LINK');
        $this->assertSame($expected, $linkFile['report_sections'] ?? null, 'SAVED (link file) ≠ LINK');

        // The tabs offered are the same fact.
        $this->assertSame($page['data']['report_views'], $linkPdf['data']['report_views'] ?? null);
        $this->assertSame($page['data']['report_views'], $preview['data']['report_views'] ?? null, 'PREVIEW offers different main sections');
    }

    public function test_a_hidden_section_has_no_data_on_the_client_surfaces(): void
    {
        $report = $this->saved();
        $link = $this->link($report);

        $surfaces = [
            'preview' => $this->preview($report)['data'],
            'link' => $this->linkPage($link['raw'])['data'],
            'pdf (link)' => $this->linkPdf($report, $link['raw'])['data'],
            'pdf (operator)' => $this->operatorPdf($report)['data'],
            'saved (report)' => $this->savedPayload($report),
            'saved (link)' => $this->savedPayload($report, $link['share']),
        ];

        foreach ($surfaces as $name => $payload) {
            // trends: off → its series is gone. recommendations: off → the recommendations travel nowhere.
            $this->assertArrayNotHasKey('platform_series', $payload, "{$name}: trends were switched off");
            $this->assertArrayNotHasKey('recommendations', $payload, "{$name}: recommendations were switched off");
            $this->assertArrayNotHasKey('findings', $payload, "{$name}: the recommendations section owns the findings");
            $this->assertArrayNotHasKey('budget', $payload, "{$name}: budget was switched off");
            $this->assertNotContains('budget', $this->slideTypes($payload), "{$name}: the budget slide survived");
            // What stays is what was left on.
            $this->assertNotSame([], (array) ($payload['platforms'] ?? []), "{$name}: platform comparison was left on and is empty");
        }
    }

    public function test_narrowed_main_sections_narrow_every_surface_alike(): void
    {
        $report = $this->saved(['section_settings' => ['views' => ['summary', 'platforms']]]);
        $link = $this->link($report);

        $page = $this->linkPage($link['raw'])['data'];
        $preview = $this->preview($report);

        $this->assertNotContains('daily_records', $page['report_sections'], 'the fixture narrows nothing, so the case proves nothing');
        $this->assertSame($page['report_sections'], $preview['sections'], 'the builder\'s preview list names sections the link does not carry');
        $this->assertSame($page['report_sections'], $preview['data']['report_sections'] ?? null);
        $this->assertSame($page['report_sections'], $this->linkPdf($report, $link['raw'])['data']['report_sections'] ?? null);
        $this->assertSame($page['report_sections'], $this->savedPayload($report, $link['share'])['report_sections'] ?? null);
    }

    public function test_the_builders_link_list_is_what_the_link_serves(): void
    {
        $report = $this->saved();
        $link = $this->link($report);
        $link['share']->settings = ['sections' => ['platform_comparison' => false]];
        $link['share']->save();

        Sanctum::actingAs($this->owner);
        $builder = $this->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares/{$link['share']->id}/sections")
            ->assertOk()->json('data.visible');

        $this->assertSame($this->linkPage($link['raw'])['data']['report_sections'], $builder);
        $this->assertNotContains('platform_comparison', $builder);
    }

    // ---------------------------------------------------------------- 2. the figures

    public function test_period_figures_metrics_and_currency_are_the_same_on_every_surface(): void
    {
        foreach (['SAR', 'USD'] as $currency) {
            $report = $this->saved(currency: $currency);
            $link = $this->link($report);

            $preview = $this->preview($report);
            $page = $this->linkPage($link['raw']);
            $operatorPdf = $this->operatorPdf($report);
            $linkPdf = $this->linkPdf($report, $link['raw']);
            $payloads = [
                'preview' => $preview['data'], 'link' => $page['data'], 'pdf (operator)' => $operatorPdf['data'],
                'pdf (link)' => $linkPdf['data'], 'saved (report)' => $this->savedPayload($report), 'saved (link)' => $this->savedPayload($report, $link['share']),
            ];

            foreach (['period', 'currency', 'kpis', 'platforms', 'metric_set', 'previous'] as $key) {
                $this->assertNotNull($payloads['link'][$key] ?? null, "{$currency}: the fixture has no `{$key}`");
                $this->assertSameAt($key, $payloads, "{$currency}");
            }
            $this->assertSame('2026-08-01', $payloads['link']['period']['from']);
            $this->assertSame('2026-08-31', $payloads['link']['period']['to']);
            $this->assertSame($currency, $payloads['link']['currency']);
            $this->assertEqualsWithDelta(4321.87, (float) $payloads['link']['kpis']['spend'], 0.001);

            // The envelope around the payload says the same thing as the payload.
            $this->assertSame($currency, $page['currency']);
            $this->assertSame($currency, $operatorPdf['currency']);
            $this->assertSame($currency, $linkPdf['currency']);
            $this->assertSame('2026-08-01', $page['period']['from']);
            $this->assertSame('2026-08-31', $page['period']['to']);

            // The metrics chosen are the allowlist every file lists, in order.
            $this->assertSame($payloads['link']['metric_set'], $linkPdf['data']['kpi_allowlist'] ?? null, "{$currency}: the PDF lists other KPIs than the link's");
            $this->assertSame($payloads['link']['metric_set'], $payloads['saved (link)']['kpi_allowlist'] ?? null);
            $this->assertSame($payloads['link']['metric_set'], $payloads['saved (report)']['kpi_allowlist'] ?? null);

            // And the bytes: the CSV names the same period and currency the page does.
            app(TenantContext::class)->setTenantId($this->tenant->id);
            $csv = app(ReportExporter::class)->render(app(ShareService::class)->documentReport($report->fresh(), $link['share']), 'csv', $link['share']);
            $this->assertContains(['Period', '2026-08-01 → 2026-08-31', 'Currency', $currency], array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), explode("\n", $csv)));
            $this->assertMatchesRegularExpression('/^spend,4321\.87$/m', $csv);
        }
    }

    public function test_a_link_hiding_spend_shows_the_same_figures_in_its_page_its_pdf_and_its_file(): void
    {
        $report = $this->saved();
        $link = $this->link($report, ['hide_spend' => true]);

        $payloads = [
            'link' => $this->linkPage($link['raw'])['data'],
            'pdf (link)' => $this->linkPdf($report, $link['raw'])['data'],
            'saved (link)' => $this->savedPayload($report, $link['share']),
        ];

        $this->assertNull($payloads['link']['kpis']['spend'] ?? null, 'the fixture hides nothing');
        foreach (['kpis', 'platforms', 'period', 'currency', 'funnel', 'findings'] as $key) {
            $this->assertSameAt($key, $payloads, 'hide_spend');
        }
        // The operator's own PDF is a different document: it is not the link's, and says so by keeping the money.
        $this->assertEqualsWithDelta(4321.87, (float) $this->operatorPdf($report)['data']['kpis']['spend'], 0.001);
    }

    // ---------------------------------------------------------------- 3. the client rules

    public function test_no_client_surface_carries_a_campaign_name(): void
    {
        $report = $this->saved(['section_settings' => ['sections' => ['detailed_tables' => true]]]);
        $link = $this->link($report);

        $surfaces = [
            'preview' => $this->preview($report)['data'],
            'link' => $this->linkPage($link['raw'])['data'],
            'pdf (link)' => $this->linkPdf($report, $link['raw'])['data'],
            'pdf (operator)' => $this->operatorPdf($report)['data'],
            'saved (report)' => $this->savedPayload($report),
            'saved (link)' => $this->savedPayload($report, $link['share']),
        ];
        foreach ($surfaces as $name => $payload) {
            $this->assertStringNotContainsString(self::CAMPAIGN, (string) json_encode($payload, JSON_UNESCAPED_UNICODE), "{$name} names the campaign");
            $this->assertSame([], (array) ($payload['campaigns'] ?? []), "{$name} carries a campaign roster");
        }

        app(TenantContext::class)->setTenantId($this->tenant->id);
        foreach (['csv'] as $format) {
            $this->assertStringNotContainsString(self::CAMPAIGN, app(ReportExporter::class)->render(app(ShareService::class)->documentReport($report->fresh(), $link['share']), $format, $link['share']));
        }
    }

    public function test_only_approved_observations_reach_the_link_the_pdf_and_the_file(): void
    {
        $note = static fn (string $text, string $status, string $audience): array => [
            'source' => 'manual', 'text' => $text, 'title' => $text, 'status' => $status, 'audience' => $audience,
        ];
        $report = $this->saved(
            ['section_settings' => ['sections' => ['trends' => false, 'recommendations' => true]]],
            function (array $d) use ($note): array {
                $d['findings'] = [$note('APPROVED-FOR-CLIENT', 'approved', 'client'), $note('STILL-A-DRAFT', 'draft', 'client'), $note('INTERNAL-ONLY', 'approved', 'internal')];

                return $d;
            },
        );
        $link = $this->link($report);

        foreach ([
            'preview' => $this->preview($report)['data'],
            'link' => $this->linkPage($link['raw'])['data'],
            'pdf (link)' => $this->linkPdf($report, $link['raw'])['data'],
            'pdf (operator)' => $this->operatorPdf($report)['data'],
            'saved (report)' => $this->savedPayload($report),
            'saved (link)' => $this->savedPayload($report, $link['share']),
        ] as $name => $payload) {
            $text = (string) json_encode($payload['findings'] ?? [], JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('APPROVED-FOR-CLIENT', $text, "{$name} lost the approved observation");
            $this->assertStringNotContainsString('STILL-A-DRAFT', $text, "{$name} shows a draft");
            $this->assertStringNotContainsString('INTERNAL-ONLY', $text, "{$name} shows an internal note");
        }
    }

    public function test_the_operators_pickers_still_get_the_roster_the_preview_no_longer_carries(): void
    {
        // A snapshot generated before the roster left the generator still holds one, and the picker reads it.
        $report = $this->saved(mutate: function (array $d): array {
            $d['campaigns'] = [['campaign_id' => (string) Str::uuid(), 'campaign_name' => self::CAMPAIGN, 'spend' => 100.0]];

            return $d;
        });

        Sanctum::actingAs($this->owner);
        $page = $this->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}")->assertOk()->json('data');

        $this->assertSame([], (array) ($page['data']['campaigns'] ?? []), 'the preview body carries the campaign roster');
        $this->assertContains('meta', $page['scope_options']['platforms']);
        $this->assertContains('snapchat', $page['scope_options']['platforms']);
        $this->assertContains(self::CAMPAIGN, array_column($page['scope_options']['campaigns'], 'campaign_name'), 'the link builder lost the campaigns it narrows a ceiling to');
    }

    // ---------------------------------------------------------------- 4. the document a link is

    public function test_a_summary_link_prints_and_exports_the_summary_its_page_is(): void
    {
        $report = $this->saved();
        $link = $this->link($report, ['form' => 'executive_summary']);

        $page = $this->linkPage($link['raw'])['data'];
        $pdf = $this->linkPdf($report, $link['raw'])['data'];
        $file = $this->savedPayload($report, $link['share']);

        $this->assertNotContains('funnel', $page['report_sections'], 'the fixture\'s summary form keeps the funnel, so the case proves nothing');
        $this->assertSame($page['report_sections'], $pdf['report_sections'] ?? null);
        $this->assertSame($page['report_sections'], $file['report_sections'] ?? null);
        $this->assertSame($this->slideTypes($page), $this->slideTypes($pdf), 'the summary link\'s PDF is printed with another set of slides than its page');
        $this->assertSame($this->slideTypes($page), $this->slideTypes($file), 'the summary link\'s file is built from another set of slides than its page');
        $this->assertSame($page['audience'] ?? null, $pdf['audience'] ?? null);
    }

    // ---------------------------------------------------------------- 5. branding

    /** @return list<array<string, mixed>> */
    public static function identities(): array
    {
        return [
            'default' => [[]],
            'the agency leads' => [['branding' => ['prefer' => 'agency']]],
            'typed names' => [['branding' => ['prepared_for_name' => 'Nakheel Dates', 'prepared_by_name' => 'Studio Seven']]],
            'a frozen identity' => [['branding' => ['name' => 'Nakheel, as it was in July']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('identities')]
    public function test_the_link_and_its_pdf_carry_one_identity(array $config): void
    {
        $report = $this->saved(branding: $config === [] ? null : $config['branding']);
        $link = $this->link($report);

        $onLink = $this->getJson("/api/v1/reports/shared/{$link['raw']}/branding")->assertOk()->json('data');
        $onPdf = $this->linkPdf($report, $link['raw'])['branding'];
        $onOperatorPdf = $this->operatorPdf($report)['branding'];

        $identity = static fn (array $b): array => [
            'name' => $b['name'] ?? null, 'by' => $b['by'] ?? null, 'logo_source' => $b['logo_source'] ?? null,
            'agency' => $b['agency']['name'] ?? null, 'client' => $b['client']['name'] ?? null,
            'has_logo' => ($b['logo_url'] ?? null) !== null,
        ];

        $this->assertSame($identity($onLink), $identity($onPdf), 'the PDF of a link is headed with another identity than the link');
        $this->assertSame($identity($onLink), $identity($onOperatorPdf), 'the operator\'s PDF is headed with another identity than the link');
    }
}
