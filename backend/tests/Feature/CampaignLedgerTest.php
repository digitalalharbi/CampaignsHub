<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAMPAIGNS-LEDGER-001 — a realistic estate, bounded, ordered and counted truthfully.
 *
 * `UnifiedCampaignController::index()` ended in `$query->get()`. Campaigns are the one list here that
 * grows without a ceiling, and the page fetched every one of them on every visit, then derived its
 * status counts, its donut and its «what is running» ordering from the array it held.
 *
 * Paginating alone would have been worse than leaving it: the server would cut by `created_at` and
 * the browser would re-order twenty-five rows, so «the campaigns that need you» would silently mean
 * «the most relevant of the twenty-five newest». The relevance ordering moves to the server with the
 * page, and the counts describe the project rather than the page.
 *
 * The fixture is a two-hundred-campaign estate, which is the size at which every one of those
 * failures stops being theoretical.
 */
final class CampaignLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($this->user, $this->tenant, Portal::App);
        $this->user->assignRole($role);
    }

    private int $created = 0;

    /**
     * `created_at` is set EXPLICITLY and always increasing.
     *
     * Without it every fixture lands in the same second, `ORDER BY created_at DESC` ties, and Postgres
     * returns them in roughly insertion order — which made the «what is running leads» assertion pass
     * against the OLD code by luck. The estate is newer than the campaign it buries, deliberately, so
     * only a relevance ordering can surface it.
     */
    private function campaign(string $name, string $status, ?string $activeOn = null, float $spend = 0): UnifiedCampaign
    {
        $c = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'objective' => 'sales', 'status' => $status,
            'total_budget' => 1_000, 'budget_currency' => 'SAR',
        ]);

        /* Not mass-assignable, so set after creation — `created_at` is not in `$fillable`. */
        $c->forceFill(['created_at' => now()->subYear()->addMinutes(++$this->created)])->saveQuietly();

        if ($activeOn !== null) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => 'spend',
                'metric_date' => $activeOn, 'value' => $spend, 'project_currency' => 'SAR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $c;
    }

    /** @return array{0: array<int, mixed>, 1: array<string, mixed>} */
    private function list(string $query = ''): array
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns".($query ? '?'.$query : ''))
            ->assertOk();

        return [(array) $res->json('data'), (array) $res->json('meta')];
    }

    /** Two hundred campaigns is a real estate, and the size at which an unbounded list stops being free. */
    private function estate(int $n = 200): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->campaign('Filler '.$i, 'paused');
        }
    }

    public function test_the_page_is_bounded_and_states_the_total(): void
    {
        $this->estate();

        [$rows, $meta] = $this->list('per_page=25');

        $this->assertCount(25, $rows, 'the one list that grows without a ceiling is not handed over whole');
        $this->assertSame(200, $meta['total']);
        $this->assertSame(8, $meta['last_page']);
    }

    /**
     * The serving campaign leads the FIRST page, from behind two hundred others.
     *
     * This is the assertion the whole unit exists for. Created last so `created_at DESC` would have
     * put it first anyway — so it is created FIRST, buried under two hundred newer rows, where only a
     * server-side relevance ordering can surface it.
     */
    public function test_what_is_running_leads_the_first_page_from_under_a_large_estate(): void
    {
        $serving = $this->campaign('Running now', 'active', now()->toDateString(), 250);
        $this->estate();

        [$rows] = $this->list('per_page=25');

        $this->assertSame((string) $serving->id, (string) $rows[0]['id'], 'the campaign that is running leads');
    }

    /** And a stopped big spender does not take that place, however much it spent. */
    public function test_a_stopped_big_spender_does_not_lead(): void
    {
        $this->campaign('Finished, huge', 'completed', now()->toDateString(), 900_000);
        $serving = $this->campaign('Running, small', 'active', now()->toDateString(), 10);
        $this->estate(30);

        [$rows] = $this->list('per_page=25');

        $this->assertSame((string) $serving->id, (string) $rows[0]['id']);
    }

    /** The status counts describe the project — a donut of one page looks exactly like a donut of all. */
    public function test_the_counts_describe_the_project_not_the_page(): void
    {
        $this->estate(40);
        for ($i = 0; $i < 7; $i++) {
            $this->campaign('Active '.$i, 'active', now()->toDateString(), 5);
        }

        [, $meta] = $this->list('per_page=25');

        $this->assertSame(47, $meta['total']);
        $this->assertSame(40, $meta['counts']['paused']);
        $this->assertSame(7, $meta['counts']['active']);
    }

    /** A filter narrows the page, the total and the counts together — one set, described once. */
    public function test_a_filter_narrows_everything_together(): void
    {
        $this->estate(30);
        $this->campaign('The one', 'active', now()->toDateString(), 5);

        [$rows, $meta] = $this->list('status=active&per_page=25');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $meta['total']);
        $this->assertSame(1, $meta['counts']['active']);
    }
}
