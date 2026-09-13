<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OPS-LEDGER-001 — a campaign's alert list says how much of itself it is showing.
 *
 * ## The cap is right; being silent about it is not
 *
 * `CampaignAlertsController` returns at most a hundred rows, which is a real bound for a real
 * reason — a busy campaign can hold thousands and nobody reads a thousand. What it did not do is
 * say so, and a hundred rows handed over with no count reads as «these are the alerts», which is the
 * defect this product has already fixed on the content library, the rules list and the security log.
 *
 * ## `counts` answers a DIFFERENT question, which is why it did not cover this
 *
 * The response already carried `meta.counts` — active, resolved, snoozed, all. Those are a status
 * BREAKDOWN of every alert this campaign has ever had, and they ignore the `status` filter the list
 * was narrowed by. So a campaign with two hundred and fifty unread alerts, asked for its unread
 * ones, returned a hundred rows beside «active: 250» — two true numbers that do not answer «is this
 * list complete», and a reader who took the hundred as the set was not contradicted by anything on
 * the page.
 *
 * Every case below keeps more alerts than the cap, or the assertion proves nothing.
 */
final class CampaignAlertCapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

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

        $this->user = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user->assignRole($role);
        $this->grantMembership($this->user, $this->tenant, Portal::App);
    }

    private function alerts(int $count, string $status = 'unread'): void
    {
        for ($i = 0; $i < $count; $i++) {
            AppNotification::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'type' => 'budget_risk',
                'severity' => 'warning',
                'title' => "Alert {$i}",
                'entity_type' => UnifiedCampaign::class,
                'entity_id' => (string) $this->campaign->id,
                'status' => $status,
            ]);
        }
    }

    private function open(string $query = ''): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns/{$this->campaign->id}/alerts{$query}")
            ->assertOk()
            ->json();
    }

    /** A hundred rows out of a hundred and five says so. */
    public function test_the_list_states_the_scope_it_was_taken_from(): void
    {
        $this->alerts(105);

        $body = $this->open();

        $this->assertCount(100, $body['data'], 'the cap itself should still hold');
        $this->assertSame(105, $body['meta']['total'] ?? null, 'the list did not say how many there are');
        $this->assertSame(5, $body['meta']['withheld'] ?? null);
    }

    /**
     * And the count follows the FILTER, which is what `counts` could never do.
     *
     * With a hundred and five unread and forty resolved, asking for the resolved ones must report
     * forty — not a hundred and forty-five, and not the unread total.
     */
    public function test_the_total_follows_the_filter(): void
    {
        $this->alerts(105, 'unread');
        $this->alerts(40, 'resolved');

        $resolved = $this->open('?status=resolved');

        $this->assertCount(40, $resolved['data']);
        $this->assertSame(40, $resolved['meta']['total'] ?? null, 'the total ignored the status filter');
        $this->assertSame(0, $resolved['meta']['withheld'] ?? null);
    }

    /** A campaign inside the cap withholds nothing, and says that rather than staying silent. */
    public function test_a_short_list_withholds_nothing(): void
    {
        $this->alerts(3);

        $body = $this->open();

        $this->assertSame(3, $body['meta']['total'] ?? null);
        $this->assertSame(0, $body['meta']['withheld'] ?? null);
    }

    /** The status breakdown it already carried is untouched — this adds, it does not replace. */
    public function test_the_status_breakdown_still_answers_its_own_question(): void
    {
        $this->alerts(105, 'unread');
        $this->alerts(40, 'resolved');

        $counts = $this->open()['meta']['counts'];

        $this->assertSame(105, $counts['active']);
        $this->assertSame(40, $counts['resolved']);
        $this->assertSame(145, $counts['all']);
    }

    /**
     * The NOTIFICATION CENTRE has the same shape, and the same silence.
     *
     * `NotificationController::index` bounds at a hundred and returns `meta.unread`. An unread count
     * is not a statement of completeness: a reader with three hundred notifications, ninety of them
     * unread, saw a hundred rows beside «unread: 90» and had nothing on the page telling them two
     * hundred more existed. Same fix, same reason, and it is asserted here rather than in a second
     * file because it is one defect wearing two routes.
     */
    public function test_the_notification_centre_states_its_own_bound(): void
    {
        for ($i = 0; $i < 105; $i++) {
            AppNotification::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
                'type' => 'budget_risk',
                'severity' => 'info',
                'title' => "N{$i}",
                'status' => $i < 30 ? 'unread' : 'read',
            ]);
        }

        $body = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/notifications")
            ->assertOk()
            ->json();

        $this->assertCount(100, $body['data'], 'the cap itself should still hold');
        $this->assertSame(105, $body['meta']['total'] ?? null, 'the centre did not say how many there are');
        $this->assertSame(5, $body['meta']['withheld'] ?? null);

        /* The unread count it already carried answers its own question and is untouched. */
        $this->assertSame(30, $body['meta']['unread'] ?? null);
    }
}
