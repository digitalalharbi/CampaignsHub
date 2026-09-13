<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MONEY-USD-002 — a report may not publish a currency its own rows contradict.
 *
 * ## The finding this closes, recorded on that row and deliberately left unfixed
 *
 * «Metrics surfaces DERIVE the unit from the rows (`rangeCurrency`) while a report carries a STORED
 * `currency` column that is published unchecked — so a report stamped with the wrong currency will
 * mislabel real money on the client link, and nothing today prevents it.»
 *
 * It is not hypothetical. `ReportingCurrency::DEFAULT` is `USD`, every new report is stamped with it,
 * and rows already normalised with `project_currency = SAR` are explicitly NOT re-normalised yet —
 * `metrics:renormalise-currency` is still pending. So any project holding legacy rows produces a
 * report stamped USD over SAR figures, and `$report->currency` is threaded through every money
 * formatter in the generator: the KPIs, the platform notes, the leaders, the findings, the
 * recommendations and the executive summary prose. One wrong stamp mislabels the whole document.
 *
 * ## The decision, stated because it is one
 *
 * THE ROWS WIN. The stamp is metadata written when the report was created, before any row was read;
 * the rows are the money. Labelling figures with what they actually are does not alter what a
 * snapshot is — the FIGURES are still frozen at generation, which is the property a snapshot exists
 * for. What changes is that the unit stops being a claim nobody checked.
 *
 * Where the rows hold MORE than one basis, no single unit is true and the report states none, the way
 * `readMoney` refuses a mixed scope rather than choosing for the reader. `rangeCurrency()` and its
 * siblings took `->value()` — the first row — and so could not tell one basis from several; that is
 * why `currencyBasis()` counts them.
 *
 * Where there are no rows at all, nothing contradicts the stamp and it stands.
 */
final class ReportCurrencyBasisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Basis', 'slug' => 'basis-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);
    }

    private function metric(string $currency, float $value = 1000.0, string $key = 'spend'): void
    {
        DailyMetric::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $this->campaign->getKey(),
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'snapchat',
            'metric_key' => $key,
            'metric_date' => now()->subDays(2)->toDateString(),
            'value' => $value,
            'project_currency' => $currency,
            'attribution_window' => '7d_click',
            'source_type' => 'platform_reported',
        ]);
    }

    private function generate(string $stamped = 'USD'): array
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Monthly',
            'type' => 'monthly',
            'form' => 'detailed',
            'status' => 'processing',
            'period_start' => now()->subDays(10)->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => $stamped,
        ]);

        (new GenerateReportJob((string) $report->getKey()))->handle(app(ReportGenerator::class));

        return (array) $report->refresh()->data;
    }

    #[Test]
    public function the_rows_currency_wins_over_a_stamp_that_contradicts_it(): void
    {
        $this->metric('SAR');

        $data = $this->generate('USD');

        $this->assertSame('SAR', $data['currency'] ?? null, 'the report published USD over figures its own rows say are SAR');
    }

    #[Test]
    public function a_stamp_the_rows_agree_with_is_left_alone(): void
    {
        $this->metric('USD');

        $data = $this->generate('USD');

        $this->assertSame('USD', $data['currency'] ?? null);
    }

    /**
     * Two bases is not a unit. Picking one would state a total in a currency half of it is not in.
     */
    #[Test]
    public function no_unit_is_stated_when_the_rows_hold_more_than_one_basis(): void
    {
        $this->metric('SAR');
        $this->metric('USD', 500.0, 'revenue');

        $data = $this->generate('USD');

        /*
         * `array_key_exists`, not `??` — the coalesce treats an explicit null as an absent key, so the
         * first version of this assertion could not tell «stated no unit» from «never stated one» and
         * failed on the correct behaviour.
         */
        $this->assertArrayHasKey('currency', $data, 'the report did not state a currency key at all');
        $this->assertNull($data['currency'], 'a report stated one currency for figures held in two');
    }

    /** Nothing contradicts a stamp when there are no rows, so it stands. */
    #[Test]
    public function a_report_with_no_rows_keeps_its_stamp(): void
    {
        $data = $this->generate('USD');

        $this->assertSame('USD', $data['currency'] ?? null);
    }

    /** The basis reader itself, since three callers will ask it and one wrong answer is a wrong label. */
    #[Test]
    public function the_basis_reader_separates_one_currency_from_several(): void
    {
        $agg = app(MetricsAggregator::class);
        $from = now()->subDays(10);
        $to = now();

        $this->assertSame(['currency' => null, 'bases' => 0], $agg->currencyBasis($from, $to));

        $this->metric('SAR');
        $this->assertSame(['currency' => 'SAR', 'bases' => 1], $agg->currencyBasis($from, $to));

        $this->metric('USD', 500.0, 'revenue');
        $this->assertSame(['currency' => null, 'bases' => 2], $agg->currencyBasis($from, $to));
    }
}
