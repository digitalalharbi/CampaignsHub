<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Management\WriteCapabilityRegistry;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\AdPlatforms;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CAMPAIGN-MGMT-DOMAIN-001 — the gate in front of every provider write.
 *
 * The Owner's rule: never expose a write action for a provider unless that exact write capability
 * is implemented and permission-gated. These tests pin the three halves of that sentence — the
 * registry is the single place «implemented» is declared; a declared-but-unverified write is never
 * reported verified without Production evidence; and a permission alone opens nothing.
 */
final class CampaignWriteCapabilityGateTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'active',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        if ($permissions !== []) {
            $role->givePermissionTo(...$permissions);
        }
        $user = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@w.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    public function test_every_provider_and_every_capability_is_declared_and_today_none_is_implemented(): void
    {
        $entries = WriteCapabilityRegistry::entries();

        $this->assertCount(count(AdPlatforms::ORDER) * count(WriteCapabilityRegistry::CAPABILITIES), $entries);
        foreach ($entries as $entry) {
            $this->assertSame(WriteCapabilityRegistry::NOT_IMPLEMENTED, $entry['status'], "{$entry['provider']}/{$entry['capability']} claims a write the code cannot perform");
            $this->assertStringStartsWith('campaigns.', $entry['permission']);
            $this->assertTrue(Permission::where('key', $entry['permission'])->exists(), "{$entry['permission']} is not a seeded permission");
        }
    }

    public function test_a_permission_alone_never_opens_an_unimplemented_write(): void
    {
        $allAllowed = static fn (string $permission): bool => true;

        foreach (AdPlatforms::ORDER as $provider) {
            foreach (array_keys(WriteCapabilityRegistry::CAPABILITIES) as $capability) {
                $this->assertFalse(WriteCapabilityRegistry::allowed($provider, $capability, $allAllowed), "$provider/$capability opened on permission alone");
            }
        }
        $this->assertFalse(WriteCapabilityRegistry::allowed('meta', 'delete_everything', $allAllowed), 'an unknown capability is never allowed');
    }

    /**
     * «NO X ADS» — the Owner's standing constraint. X stays in the platform list because PLATFORM-ORDER-001
     * says the list is the only thing that decides how many platforms there are; but no write for it is
     * ever declared, and this is where that is pinned.
     */
    public function test_no_write_is_ever_declared_for_x(): void
    {
        foreach (WriteCapabilityRegistry::entries() as $entry) {
            if ($entry['provider'] === 'x') {
                $this->assertSame(WriteCapabilityRegistry::NOT_IMPLEMENTED, $entry['status'], "x/{$entry['capability']} declares a write — NO X ADS");
            }
        }
    }

    public function test_verified_without_production_evidence_is_demoted_to_implemented_not_verified(): void
    {
        $entries = WriteCapabilityRegistry::entriesFrom([
            'meta' => [
                'pause_resume' => ['status' => WriteCapabilityRegistry::VERIFIED, 'evidence' => null],
                'budget_change' => ['status' => WriteCapabilityRegistry::VERIFIED, 'evidence' => '   '],
                'publish' => ['status' => WriteCapabilityRegistry::VERIFIED, 'evidence' => 'act_1/campaign 2381: paused 2026-10-01'],
                'targeting' => ['status' => WriteCapabilityRegistry::AWAITING_CREDENTIALS, 'evidence' => null],
            ],
        ]);
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry['provider'].'/'.$entry['capability']] = $entry;
        }

        $this->assertSame(WriteCapabilityRegistry::IMPLEMENTED_NOT_VERIFIED, $byKey['meta/pause_resume']['status']);
        $this->assertNull($byKey['meta/pause_resume']['evidence']);
        $this->assertSame(WriteCapabilityRegistry::IMPLEMENTED_NOT_VERIFIED, $byKey['meta/budget_change']['status']);
        $this->assertSame(WriteCapabilityRegistry::VERIFIED, $byKey['meta/publish']['status']);
        $this->assertSame('act_1/campaign 2381: paused 2026-10-01', $byKey['meta/publish']['evidence']);
        $this->assertSame(WriteCapabilityRegistry::AWAITING_CREDENTIALS, $byKey['meta/targeting']['status']);
        $this->assertSame(WriteCapabilityRegistry::NOT_IMPLEMENTED, $byKey['snapchat/publish']['status'], 'a declaration for one provider says nothing about another');
    }

    public function test_the_capabilities_endpoint_states_the_rule_and_every_cell_for_the_reader(): void
    {
        $viewer = $this->userWith(['projects.view', 'projects.view.all', 'campaigns.view', 'campaigns.pause']);

        $data = (array) $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaign-management/capabilities")
            ->assertOk()
            ->json('data');

        $this->assertSame([
            WriteCapabilityRegistry::NOT_IMPLEMENTED,
            WriteCapabilityRegistry::IMPLEMENTED_NOT_VERIFIED,
            WriteCapabilityRegistry::AWAITING_CREDENTIALS,
            WriteCapabilityRegistry::VERIFIED,
        ], $data['statuses']);
        $this->assertStringContainsString('Production write round-trip', $data['rule_en']);
        $this->assertStringContainsString('جولة كتابة حقيقية', $data['rule_ar']);

        $providers = array_column($data['providers'], 'provider');
        $this->assertSame(AdPlatforms::ORDER, $providers, 'providers come in the canonical order');

        $meta = collect($data['providers'])->firstWhere('provider', 'meta');
        $this->assertCount(count(WriteCapabilityRegistry::CAPABILITIES), $meta['capabilities']);
        $pause = collect($meta['capabilities'])->firstWhere('capability', 'pause_resume');
        $this->assertSame('campaigns.pause', $pause['permission']);
        $this->assertTrue($pause['permitted'], 'the reader holds campaigns.pause');
        $this->assertFalse($pause['allowed'], 'held permission + unimplemented write = no action');
        $budget = collect($meta['capabilities'])->firstWhere('capability', 'budget_change');
        $this->assertFalse($budget['permitted']);
        $this->assertFalse($budget['allowed']);
    }

    public function test_the_endpoint_needs_campaigns_view(): void
    {
        $nobody = $this->userWith(['projects.view', 'projects.view.all']);

        $this->actingAs($nobody, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaign-management/capabilities")
            ->assertForbidden();
    }
}
