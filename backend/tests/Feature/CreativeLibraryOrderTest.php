<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
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
 * ENTITY-RELEVANCE-ORDERING-001 — when everything ties, order by something a reader can follow.
 *
 * ## The tie is the normal case, not the edge case
 *
 * The library's default order is `last_active_at DESC NULLS LAST, last_synced_at DESC NULLS LAST,
 * id`. Both of the first two are properties of DELIVERY and of SYNCING, and an account that has
 * just been connected has neither: `last_active_at` is null for every creative that has never
 * delivered, and `last_synced_at` is identical across everything a single sync batch wrote in one
 * run. So the whole first page ties, and falls through to `id`.
 *
 * `id` is a UUID. It is deterministic — which is why it is there, and it must stay, because an
 * ordering with ties repeats and skips rows across pages — and it is meaningless: the library opens
 * in an order nobody can predict, follow, or scan for a creative they know the name of.
 *
 * ## The tiebreak has to come before `id`, not instead of it
 *
 * Name ordering does not make the sort stable on its own: two creatives can share a name, and on
 * this product they routinely do — the same film uploaded to two platforms carries one name. So the
 * name is inserted BEFORE `id` rather than replacing it, and the pagination guarantee is untouched.
 *
 * Every case below gives the creatives the same delivery and sync facts, which is what the tie is,
 * and ids deliberately in the OPPOSITE order to their names — otherwise a test could pass on a
 * database that happened to return insertion order.
 */
final class CreativeLibraryOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'provider' => 'meta', 'external_id' => 'c-1', 'name' => 'Campaign',
            'status' => 'active', 'objective' => 'sales',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * One creative, with an id chosen rather than generated.
     *
     * The point of the fixture is that `id` order and `name` order disagree, so a result in name
     * order cannot be a result in id order wearing a disguise.
     */
    private function creative(string $name, int $idOrder, array $over = []): ExternalCreative
    {
        /*
         * `forceFill`, because `id` is not fillable and `create()` DROPPED it in silence.
         *
         * The first version of this fixture passed `id` to `create()`. The model guards everything
         * and `HasUuidKey` then generated a random one, so the ids were not the ids the cases
         * describe and every result was luck: the three-row case failed five times in six and the
         * two-row case passed one time in two — which is what it did under injection, with the
         * tiebreak removed, and it read as a guard that could not fail. It could; it was a coin.
         */
        $creative = new ExternalCreative;

        $creative->forceFill(array_merge([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $idOrder),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $this->campaign->id,
            'provider' => 'meta',
            'external_creative_id' => 'ec-'.$idOrder,
            'name' => $name,
            'format' => 'image',
            'asset_url' => 'https://cdn.test/a.jpg',
            // The tie: one sync batch, nothing delivered yet.
            'last_active_at' => null,
            'last_synced_at' => Carbon::now(),
        ], $over));

        $creative->save();

        return $creative;
    }

    /** @return list<string> the library's own order, by name */
    private function order(): array
    {
        $rows = app(CreativeRows::class)
            ->applySort(ExternalCreative::query(), 'relevance', Carbon::now()->subDays(7), Carbon::now())
            ->get();

        return $rows->pluck('name')->all();
    }

    /**
     * A freshly connected account opens in a readable order, not in UUID order.
     *
     * `relevance` is what the library opens on, and it asks «is it running» then «what did it
     * spend». A new account answers neither, so every creative sits in one status bucket with a
     * null spend and the whole first page ties.
     */
    public function test_creatives_that_tie_are_ordered_by_name(): void
    {
        $this->creative('Zahra film', 1);
        $this->creative('Awal film', 2);
        $this->creative('Muntasaf film', 3);

        $this->assertSame(['Awal film', 'Muntasaf film', 'Zahra film'], $this->order());
    }

    /**
     * Spend still wins. The tiebreak is a tiebreak, not a re-sort.
     *
     * Without this case the first one would be satisfied by ordering the library alphabetically
     * outright, which would bury the creative carrying the budget under one that spent nothing.
     */
    public function test_a_creative_that_spent_still_leads_whatever_it_is_called(): void
    {
        $zahra = $this->creative('Zahra film', 1);
        $this->creative('Awal film', 2);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'creative_id' => $zahra->id,
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'spend' => 500, 'impressions' => 10, 'clicks' => 1, 'conversions' => 0, 'revenue' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(['Zahra film', 'Awal film'], $this->order());
    }

    /** And a creative that is no longer running still sinks below one that is, name notwithstanding. */
    public function test_a_stopped_creative_still_sinks_whatever_it_is_called(): void
    {
        $this->creative('Awal film', 1, ['status' => 'paused']);
        $this->creative('Zahra film', 2, ['last_active_at' => Carbon::now()->subDay()]);

        $this->assertSame(['Zahra film', 'Awal film'], $this->order());
    }

    /**
     * Two creatives with ONE name still order deterministically, which is why `id` stays last.
     *
     * The same film uploaded to two platforms carries one name on this product, so this is the
     * normal case rather than a contrived one — and an ordering with ties repeats and skips rows
     * across pages.
     */
    public function test_identical_names_still_break_the_tie_on_id(): void
    {
        $this->creative('One film', 2);
        $this->creative('One film', 1);

        $ids = app(CreativeRows::class)
            ->applySort(ExternalCreative::query(), 'relevance', Carbon::now()->subDays(7), Carbon::now())
            ->get()
            ->pluck('id')
            ->all();

        $sorted = $ids;
        sort($sorted);

        $this->assertSame($sorted, $ids, 'two creatives sharing a name did not fall back to a stable order');
    }

    /**
     * The DEFAULT arm ties the same way and takes the same tiebreak.
     *
     * It is reached by any caller that does not name a sort — the report's own roster among them —
     * and its two keys are delivery and sync time, both of which a single sync batch makes uniform.
     */
    public function test_the_default_order_breaks_its_tie_by_name_too(): void
    {
        $this->creative('Zahra film', 1);
        $this->creative('Awal film', 2);

        $names = app(CreativeRows::class)
            ->applySort(ExternalCreative::query(), '', Carbon::now()->subDays(7), Carbon::now())
            ->get()
            ->pluck('name')
            ->all();

        $this->assertSame(['Awal film', 'Zahra film'], $names);
    }
}
