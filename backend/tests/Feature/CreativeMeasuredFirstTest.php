<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-MEASURED-FIRST-001 — content the period never measured cannot lead content it did.
 *
 * ## The defect, as the owner found it
 *
 * `relevance` ordered by the running-state bucket FIRST and by spend only within it. A creative that
 * had not delivered in over a year, with no metric row of any kind in the window, sat in the «idle»
 * bucket — and the idle bucket outranks the «stopped» one, so it was placed above a paused creative
 * that had actually spent 2,704.50 in the same window.
 *
 * Measured on a real library: position 3 was «Old untouched active», no spend and no impressions at
 * all; position 4 was «Bundle Carousel», 2,704.50 spent across 6,010 impressions.
 *
 * That is the ordering telling an operator that the thing nothing is known about matters more than
 * the thing the money went to — on a page whose whole purpose is to put the budget where it performs.
 * It also reads as a data fault rather than an ordering one, because a reader seeing dead content at
 * the top of a performance list stops trusting the figures beside it.
 *
 * ## The rule
 *
 * Measurement comes first. A creative the window holds a figure for is ranked above one it holds
 * nothing for, whatever either of their statuses say. Within the measured, spend decides, descending:
 * continuous management moves budget toward what performs, so spend order IS performance order.
 * Within the unmeasured — which is everything we cannot rank — the previous reading applies: what is
 * running, then name.
 *
 * A reported ZERO is measurement. A creative the platform told us spent nothing is a creative we know
 * something about, and it stays ahead of one that reported nothing at all, which is the same
 * distinction `NULLS LAST` already draws one rung down.
 */
final class CreativeMeasuredFirstTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-measured-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-measured-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->to = Carbon::parse('2026-08-30');
    }

    /** The owner's case, in two rows. */
    public function test_a_paused_creative_that_spent_outranks_an_idle_one_that_never_reported(): void
    {
        $this->creative('never reported', 'active', '2025-07-01');
        $this->creative('paused but spent', 'paused', '2026-08-29', spend: 2_704.50);

        $this->assertSame(['paused but spent', 'never reported'], $this->ordered());
    }

    /** And in the shape a real library has: every measured row above every unmeasured one. */
    public function test_everything_measured_comes_before_everything_unmeasured(): void
    {
        $this->creative('unmeasured, serving', 'active', '2026-08-29');
        $this->creative('measured small, archived', 'archived', '2026-08-20', spend: 12.0);
        $this->creative('unmeasured, paused', 'paused', '2026-08-29');
        $this->creative('measured large, paused', 'paused', '2026-08-20', spend: 900.0);

        $this->assertSame(
            ['measured large, paused', 'measured small, archived', 'unmeasured, serving', 'unmeasured, paused'],
            $this->ordered(),
        );
    }

    /** Spend decides among the measured, descending — because budget follows performance. */
    public function test_the_measured_are_ranked_by_spend_descending(): void
    {
        $this->creative('small', 'active', '2026-08-29', spend: 10.0);
        $this->creative('large', 'active', '2026-08-29', spend: 5_000.0);
        $this->creative('middle', 'active', '2026-08-29', spend: 400.0);

        $this->assertSame(['large', 'middle', 'small'], $this->ordered());
    }

    /**
     * A reported zero is measurement, and outranks silence.
     *
     * «The platform told us this spent nothing» and «the platform told us nothing» are different
     * facts, and the product does not flatten them anywhere else either.
     */
    public function test_a_reported_zero_outranks_a_creative_with_no_rows_at_all(): void
    {
        $this->creative('silent', 'active', '2026-08-29');
        $this->creative('reported zero', 'paused', '2026-08-20', spend: 0.0, reportRow: true);

        $this->assertSame(['reported zero', 'silent'], $this->ordered());
    }

    /** Among the unmeasured, the old reading still applies: what is running leads. */
    public function test_the_unmeasured_keep_the_running_first_reading(): void
    {
        $this->creative('stopped', 'archived', '2026-08-29');
        $this->creative('serving', 'active', '2026-08-29');

        $this->assertSame(['serving', 'stopped'], $this->ordered());
    }

    /**
     * CONTENT-BROWSER-PARITY-001 — among the MEASURED, what is running leads what has stopped.
     *
     * The owner: «يجب أن تكون المحتويات التي لا تعمل أن تبقى آخر المحتويات». This clause existed
     * already and sat below the metric and below spend, where it almost never fired — a metric and
     * a spend rarely tie — so a paused creative with the period's highest spend led a page whose
     * reader can do nothing about it.
     *
     * The campaigns workspace settled the same question first, in `relevanceOf`'s own words: «a
     * finished campaign that outspent every running one used to lead the operational list». Serving
     * first, then by spend, at every rung.
     */
    public function test_a_serving_creative_leads_a_paused_one_that_spent_more(): void
    {
        $this->creative('paused, spent 5,000', 'paused', '2026-08-20', spend: 5_000.0);
        $this->creative('serving, spent 12', 'active', '2026-08-29', spend: 12.0);

        $this->assertSame(['serving, spent 12', 'paused, spent 5,000'], $this->ordered());
    }

    /**
     * …and the paused big spender still leads everything UNMEASURED, which is the other rule intact.
     *
     * This is the case that proves the change is not a revert. CONTENT-MEASURED-FIRST-001 is about
     * measurement against silence — the owner's «makes you doubt the accuracy of the data» — and it
     * still decides first. What moved is the order WITHIN the measured, which that row never spoke
     * about. Both of his instructions hold in the same list, and this test is where they meet.
     */
    public function test_the_paused_spender_still_outranks_everything_unmeasured(): void
    {
        $this->creative('unmeasured, serving', 'active', '2026-08-29');
        $this->creative('paused, spent 5,000', 'paused', '2026-08-20', spend: 5_000.0);
        $this->creative('serving, spent 12', 'active', '2026-08-29', spend: 12.0);

        $this->assertSame(
            ['serving, spent 12', 'paused, spent 5,000', 'unmeasured, serving'],
            $this->ordered(),
        );
    }

    /** Idle sits between the two: switched on and producing nothing is not the same as stopped. */
    public function test_an_idle_creative_sits_between_serving_and_stopped(): void
    {
        $this->creative('stopped', 'archived', '2026-08-29', spend: 900.0);
        $this->creative('idle', 'active', '2026-08-01', spend: 900.0);
        $this->creative('serving', 'active', '2026-08-29', spend: 900.0);

        $this->assertSame(['serving', 'idle', 'stopped'], $this->ordered());
    }

    /**
     * …and an EXPLICIT metric sort is the reader's, not the product's.
     *
     * «Running first» is the DEFAULT order — the constitution's own word. A reader who picks «sort
     * by spend» has asked for spend, and a delivery state that outranked their choice would be the
     * product overruling them. `CreativeLibraryApiTest` caught the first version of this change
     * within a minute: a creative that had spent 9,000 and gone quiet for five days lost its own
     * spend sort to a serving creative that had spent 10.
     *
     * The state still breaks TIES on an explicit sort, where it is the better answer and overrules
     * nothing.
     */
    public function test_an_explicitly_chosen_metric_is_not_overruled_by_the_running_state(): void
    {
        $this->creative('serving, spent 10', 'active', '2026-08-29', spend: 10.0);
        $this->creative('quiet, spent 9,000', 'active', '2026-08-20', spend: 9_000.0);

        $query = ExternalCreative::query()->where('project_id', $this->project->id);
        $sorted = app(CreativeRows::class)
            ->applySort($query, 'spend', $this->to->copy()->subDays(30), $this->to)
            ->get()->pluck('name')->map(strval(...))->all();

        $this->assertSame(['quiet, spent 9,000', 'serving, spent 10'], $sorted);
    }

    /** Measurement is read inside the WINDOW. A figure from last year is not this period's evidence. */
    public function test_a_figure_outside_the_window_does_not_count_as_measured(): void
    {
        $this->creative('spent long ago', 'active', '2026-08-29', spend: 9_000.0, on: '2025-01-05');
        $this->creative('spent in window', 'paused', '2026-08-20', spend: 5.0);

        $this->assertSame(['spent in window', 'spent long ago'], $this->ordered());
    }

    /** @return list<string> */
    private function ordered(): array
    {
        $query = ExternalCreative::query()->where('project_id', $this->project->id);
        $sorted = app(CreativeRows::class)->applySort($query, 'relevance', $this->to->copy()->subDays(30), $this->to);

        return $sorted->get()->pluck('name')->map(strval(...))->all();
    }

    private function creative(
        string $name,
        ?string $status,
        ?string $lastActive,
        float $spend = 0.0,
        bool $reportRow = false,
        ?string $on = null,
    ): ExternalCreative {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->project->tenant_id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => $name,
            'format' => 'image',
            'status' => $status,
            'last_active_at' => $lastActive,
        ]);

        if ($spend > 0 || $reportRow) {
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->project->tenant_id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => $on ?? $this->to->copy()->subDay()->toDateString(),
                'spend' => $spend,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $creative;
    }
}
