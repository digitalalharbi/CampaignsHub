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
 * LEAD-SOURCE-ATTRIBUTION-001 — the chain reaches the API, for the reader who may not see the person.
 *
 * `LeadAttributionChainTest` proves the chain is built correctly. This proves it SURVIVES the trip:
 * that the resource carries it, and — the part that decides whether the feature is usable at all —
 * that a media buyer whose identity permission is withheld still gets it.
 *
 * That combination is the whole point. The buyer is the person who needs to know which ad produced
 * the lead and has no business knowing who the lead is. If attribution were gated behind the same
 * permission as the phone number, the only reader who needs it would be the one reader denied it,
 * and the client would be paying for a chain nobody can see.
 */
final class LeadAttributionApiTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'lattr-'.uniqid(), 'status' => 'active']);
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
     * The reader who may not see the person still learns which ad produced them.
     *
     * A media buyer is redacted down to «a lead happened». That redaction is about the HUMAN; the
     * campaign that ran is the client's own media buying, and withholding it here would leave cost
     * per lead attributable to nothing.
     */
    public function test_a_media_buyer_denied_the_identity_still_gets_the_chain(): void
    {
        $this->lead('نورة', 'noura@example.com', '0500000000');
        $buyer = $this->member(ProjectRole::MEDIA_BUYER);

        $row = $this->list($buyer)['data'][0];

        $this->assertTrue($row['identity_withheld'], 'the fixture stopped redacting; this test proves nothing');
        $this->assertNull($row['name']);

        $chain = $row['attribution'];
        $this->assertSame('native_form', $chain['route']);
        $this->assertSame('meta', $chain['platform']['provider']);

        $campaign = collect($chain['rungs'])->firstWhere('rung', 'campaign');
        $this->assertSame('named', $campaign['state']);
        $this->assertSame('Riyadh — Q3', $campaign['name']);
    }

    /**
     * A gap the platform could have filled reaches the reader as a gap.
     *
     * Meta returns the ad set on a lead form. Serving that absence as an ordinary empty value would
     * make a broken sync indistinguishable from a platform that has no ad sets — and the client
     * would find out when the leads got worse, not when the data did.
     */
    public function test_a_missing_rung_arrives_as_a_gap_and_not_as_an_empty_value(): void
    {
        /*
         * Created WITHOUT the ad set rather than stripped afterwards: the model refuses to let
         * provenance be rewritten on an existing row (LEAD-PROVENANCE-001), which is the guard doing
         * its job. A lead that never carried one is also the real shape of the defect.
         */
        $this->lead('نورة', 'noura@example.com', '0500000000', null, [
            'external_adset_id' => null, 'adset_name' => null,
        ]);

        $row = $this->list($this->member(ProjectRole::SALES_MANAGER))['data'][0];
        $adset = collect($row['attribution']['rungs'])->firstWhere('rung', 'adset');

        $this->assertSame('missing', $adset['state']);
        $this->assertFalse($row['attribution']['complete']);
    }

    /** The detail view carries the same chain the list does — one answer, not two. */
    public function test_the_detail_view_reports_the_same_chain(): void
    {
        $lead = $this->lead('نورة', 'noura@example.com', '0500000000');
        $manager = $this->member(ProjectRole::SALES_MANAGER);

        $shown = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/leads/{$lead->getKey()}")
            ->assertOk()
            ->json('data.attribution');

        $listed = $this->list($manager)['data'][0]['attribution'];

        $this->assertSame($listed, $shown);
    }

    /** @param array<string,mixed> $over */
    private function lead(string $name, string $email, string $phone, ?int $ownerId = null, array $over = []): Lead
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
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
            'external_campaign_id' => 'cmp-1', 'campaign_name' => 'Riyadh — Q3',
            'external_adset_id' => 'set-1', 'adset_name' => 'Riyadh 25-45',
            'external_ad_id' => 'ad-1', 'ad_name' => 'Villa carousel',
            'external_creative_id' => 'cr-1', 'creative_name' => 'Villa 3BR',
            'owner_id' => $ownerId,
            'provider_lead_id' => (string) Str::uuid(),
        ], $over))->save();

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
