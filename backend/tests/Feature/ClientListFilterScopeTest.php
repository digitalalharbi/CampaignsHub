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
 * FILTER-DATA-TRUTH-001 — the client filters ran AFTER the page was cut.
 *
 * `has_active_campaigns` and `has_open_requests` were applied to the twenty-four rows the paginator
 * had already returned, so the filter did not select clients — it selected among whichever clients
 * page one happened to hold. A client with active campaigns sitting at position 30 was invisible,
 * and paging forward to find it showed a DIFFERENT arbitrary subset, because the cut came first.
 *
 * `meta.total` made it worse by staying the unfiltered count: the screen said «137 clients» above a
 * list of three, and offered page numbers that render empty. A reader cannot tell that from a
 * genuinely small result.
 *
 * This is the same defect class as `CreativeAnalysisController`'s health filter. The fix is the same:
 * narrow the QUERY, so the page, the count and the pager all describe one set of clients.
 */
final class ClientListFilterScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function withActiveCampaign(ClientWorkspace $client): void
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P-'.uniqid(), 'status' => 'active',
        ]);

        UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'client_workspace_id' => $client->id, 'name' => 'C-'.uniqid(),
            'objective' => 'sales', 'status' => 'active', 'total_budget' => 100,
        ]);
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>} */
    private function list(string $query): array
    {
        $res = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/app/clients?'.$query)->assertOk();

        return [(array) $res->json('data'), (array) $res->json('meta')];
    }

    /**
     * Thirty clients, one of them the only one with a campaign, created FIRST so the default
     * newest-first sort puts it on the last page — exactly where the post-filter could not see it.
     */
    public function test_the_filter_finds_a_matching_client_beyond_the_first_page(): void
    {
        $needle = $this->client('Has Campaigns');
        $this->withActiveCampaign($needle);

        for ($i = 0; $i < 30; $i++) {
            $this->client('Quiet '.$i);
        }

        [$rows, $meta] = $this->list('has_active_campaigns=1&per_page=24');

        $this->assertSame(['Has Campaigns'], array_column($rows, 'name'));
        $this->assertSame(1, $meta['total'], 'the count describes the filtered set, not the table');
        $this->assertSame(1, $meta['last_page'], 'no page is offered that renders empty');
    }

    /** The same, for the filter that reads requests rather than campaigns. */
    public function test_the_open_requests_filter_is_not_limited_to_the_page(): void
    {
        $needle = $this->client('Has Requests');

        for ($i = 0; $i < 30; $i++) {
            $this->client('Quiet '.$i);
        }

        /*
         * The status is created here rather than skipped on. A skipped test proves nothing about the
         * filter, and the row this needs — one non-terminal status — is two columns of fixture.
         */
        $status = DB::table('request_statuses')->where('is_terminal', false)->value('id');

        if ($status === null) {
            $status = DB::table('request_statuses')->insertGetId([
                'key' => 'new-'.uniqid(), 'name_ar' => 'جديد', 'name_en' => 'New',
                'sort' => 0, 'is_terminal' => false, 'pauses_sla' => false, 'is_client_visible' => true,
            ]);
        }

        $type = DB::table('request_types')->value('id') ?? DB::table('request_types')->insertGetId([
            'key' => 'k-'.uniqid(), 'module' => 'paid_media', 'name_ar' => 'نوع', 'name_en' => 'Type',
        ]);

        DB::table('external_requests')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-'.uniqid(),
            'client_id' => $needle->id,
            'type_id' => $type,
            'status_id' => $status,
            'contact_name' => 'C',
            'contact_email' => 'c@a.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$rows, $meta] = $this->list('has_open_requests=1&per_page=24');

        $this->assertSame(['Has Requests'], array_column($rows, 'name'));
        $this->assertSame(1, $meta['total']);
    }

    /** A filter that matches nothing says so, rather than returning whatever page one held. */
    public function test_a_filter_that_matches_nothing_returns_nothing(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->client('Quiet '.$i);
        }

        [$rows, $meta] = $this->list('has_active_campaigns=1');

        $this->assertSame([], $rows);
        $this->assertSame(0, $meta['total']);
    }
}
