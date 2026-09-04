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
 * ENTITY-RELEVANCE-ORDERING-001 — the content library orders by what is RUNNING, not by what is recent.
 *
 * The default was `last_active_at DESC`, and recency is not relevance: a paused campaign's creative
 * that delivered yesterday sorted above a serving creative whose last figure was three days old, so
 * the first thing an operator saw was work they could do nothing about.
 *
 * Expressed in SQL rather than in the browser because this listing is PAGED by the database. Ordering
 * one page client-side reorders that page and misstates the listing, which is worse than the recency
 * order it replaces.
 */
final class CreativeRelevanceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** The window's end — «serving» is measured against this, never against today. */
    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-relevance', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-relevance', 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->to = Carbon::parse('2026-08-30');
    }

    /**
     * @return list<string> the creative names in the order the listing returns them
     */
    private function ordered(): array
    {
        $query = ExternalCreative::query()->where('project_id', $this->project->id);
        $sorted = app(CreativeRows::class)->applySort($query, 'relevance', $this->to->copy()->subDays(30), $this->to);

        return $sorted->get()->pluck('name')->map(strval(...))->all();
    }

    private function creative(string $name, ?string $status, ?string $lastActive, float $spend = 0.0): ExternalCreative
    {
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

        if ($spend > 0) {
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->project->tenant_id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => $this->to->copy()->subDay()->toDateString(),
                'spend' => $spend,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $creative;
    }

    /** The defect, stated as a test: a stopped thing does not lead the list however recent it is. */
    public function test_a_serving_creative_outranks_a_paused_one_that_delivered_more_recently(): void
    {
        $this->creative('paused yesterday', 'paused', '2026-08-29');
        $this->creative('serving, three days quiet', 'active', '2026-08-27');

        $this->assertSame(['serving, three days quiet', 'paused yesterday'], $this->ordered());
    }

    /**
     * Reporting lag is not idleness — and the boundary is measured from the WINDOW, not from today.
     *
     * A figure two days behind the window's end is a platform being slow. Four days behind is a fact
     * about the creative.
     */
    public function test_the_three_day_lag_is_where_serving_ends(): void
    {
        $this->creative('two days behind', 'active', '2026-08-28');
        $this->creative('four days behind', 'active', '2026-08-26');

        $this->assertSame(['two days behind', 'four days behind'], $this->ordered());
    }

    /** Inside one state, the money decides — the same tiebreak the campaigns workspace uses. */
    public function test_spend_orders_within_a_state(): void
    {
        $this->creative('small', 'active', '2026-08-29', 100.0);
        $this->creative('large', 'active', '2026-08-29', 900.0);

        $this->assertSame(['large', 'small'], $this->ordered());
    }

    /**
     * A draft has not stopped; it has not started.
     *
     * The campaigns workspace bought this distinction with a real defect — a campaign is created as
     * `draft`, and filing draft under «stopped» made the campaign an operator had just created
     * disappear from the list they created it in. The same statuses mean the same thing here.
     */
    public function test_a_draft_is_not_filed_with_the_stopped(): void
    {
        $this->creative('archived', 'archived', '2026-08-29');
        $this->creative('draft', 'draft', null);

        $this->assertSame(['draft', 'archived'], $this->ordered());
    }
}
