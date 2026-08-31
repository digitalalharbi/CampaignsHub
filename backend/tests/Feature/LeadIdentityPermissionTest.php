<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * LEAD-OPERATIONS-001 — a lead's identity is a permission, and the search box is part of it.
 *
 * `LeadResource` returned `name`, `email`, `phone` and `notes` to anybody holding the tenant's
 * `leads.view` — everybody who can open the screen, including the media buyer whose job is the cost
 * per lead. Reading the COUNT and reading the PEOPLE were one permission, so a client's customers
 * were readable by the whole agency.
 *
 * The subtler half is the search box. A reader who cannot SEE a phone number but can SEARCH by one
 * has the number: they type it and watch the count change. A redaction with an oracle beside it is
 * not a redaction.
 */
final class LeadIdentityPermissionTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'lip-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'آساس الثبات', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'Lead generation', 'status' => 'active',
        ]);
    }

    /**
     * The media buyer sees that the campaign produced a lead, and not who it is.
     *
     * Everything they need is still there — the row, the source, the campaign, the status — so the
     * cost per lead is unaffected. What is gone is the person.
     */
    public function test_a_media_buyer_sees_the_lead_but_not_the_person(): void
    {
        $this->lead('نورة', 'noura@example.com', '0500000000');
        $buyer = $this->member(ProjectRole::MEDIA_BUYER);

        $row = $this->list($buyer)['data'][0];

        $this->assertTrue($row['identity_withheld']);
        $this->assertNull($row['name']);
        $this->assertNull($row['email']);
        $this->assertNull($row['phone']);
        // The lead itself is still reported — this is a redaction, not a disappearance.
        $this->assertSame('provider', $row['source']);
    }

    /** The sales manager holds the identity permission, and gets the identity. */
    public function test_a_sales_manager_sees_who_the_lead_is(): void
    {
        $this->lead('نورة', 'noura@example.com', '0500000000');
        $manager = $this->member(ProjectRole::SALES_MANAGER);

        $row = $this->list($manager)['data'][0];

        $this->assertFalse($row['identity_withheld']);
        $this->assertSame('نورة', $row['name']);
        // Stored in E.164 by the intake — the point here is that it is PRESENT, not its shape.
        $this->assertSame('+966500000000', $row['phone']);
    }

    /**
     * Search is refused rather than quietly emptied.
     *
     * An empty result would be a lie about the client's leads — the reader would conclude nobody by
     * that name exists. And a working search is the number itself: type it, watch the count.
     */
    public function test_a_reader_without_the_identity_cannot_search_by_it(): void
    {
        $this->lead('نورة', 'noura@example.com', '0500000000');
        $buyer = $this->member(ProjectRole::MEDIA_BUYER);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/leads?project_id='.$this->project->id.'&search=0500000000')
            ->assertForbidden();

        // …and the plain list still works for them, so this is a refusal of the ORACLE, not the screen.
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/leads?project_id='.$this->project->id)->assertOk();
    }

    /**
     * A lead agent sees the leads assigned to them, and no others.
     *
     * `leads.assign` is what distinguishes somebody who runs the pipeline from somebody who works in
     * it. An unowned lead — one nobody has been given yet — belongs to the supervisor's view, because
     * it is exactly the row an agent must not quietly claim.
     */
    public function test_a_lead_agent_sees_only_their_own(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $mine = $this->lead('نورة', 'noura@example.com', '0500000000', $agent->id);
        $theirs = $this->lead('سارة', 'sara@example.com', '0511111111');

        $ids = array_column($this->list($agent)['data'], 'id');

        $this->assertSame([(string) $mine->getKey()], $ids);
        $this->assertNotContains((string) $theirs->getKey(), $ids);
    }

    /**
     * And an id is not a key. The list already hides the row; without this, guessing or pasting an id
     * is enough — and ids outlive the reason somebody had one.
     */
    public function test_a_lead_agent_cannot_open_a_lead_by_id(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $theirs = $this->lead('سارة', 'sara@example.com', '0511111111');

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/leads/{$theirs->getKey()}")
            ->assertForbidden();
    }

    /** The supervisor sees the whole pipeline, including the leads nobody has been given yet. */
    public function test_a_supervisor_sees_the_unassigned(): void
    {
        $manager = $this->member(ProjectRole::SALES_MANAGER);
        $this->lead('نورة', 'noura@example.com', '0500000000');

        $this->assertCount(1, $this->list($manager)['data']);
    }

    private function lead(string $name, string $email, string $phone, ?int $ownerId = null): Lead
    {
        $lead = new Lead;
        $lead->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'email_normalized' => strtolower($email),
            'phone_normalized' => preg_replace('/\D/', '', $phone),
            'source' => 'provider',
            'provider' => 'meta',
            'owner_id' => $ownerId,
            'provider_lead_id' => (string) Str::uuid(),
        ])->save();

        return $lead;
    }

    /** @return array<string,mixed> */
    private function list(User $user, array $query = []): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/leads?'.http_build_query($query + ['per_page' => 100, 'project_id' => (string) $this->project->id]))
            ->assertOk()
            ->json();
    }

    /**
     * A project member whose TENANT role carries what the leads endpoint checks.
     *
     * `leads.view` at the tenant layer, and nothing about identity: if the helper granted
     * `leads.pii.view` there too, the redaction tests would pass on the tenant layer for a lead with
     * no project and prove nothing about the project capability they were written for.
     */
    private function member(string $role): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.test', 'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);

        $tenantRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $tenantRole->givePermissionTo(...Permission::whereIn('key', ['projects.view', 'leads.view'])->pluck('key')->all());
        $user->assignRole($tenantRole);

        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => Carbon::now(),
        ]);

        return $user;
    }
}
