<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — three boundaries of the client portal:
 *   - a phone typed into a profile proves nothing, so it cannot claim somebody else's unconverted requests;
 *   - a phone one-time-code session of a person who HAS a portal membership is bound by that membership's
 *     project ceiling, not handed the whole client by the request rows;
 *   - one client's grant never rewrites a person's role or project ceiling on a sibling client.
 */
final class ClientPortalCeilingBoundariesTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->buildCeilingFixture();
        $this->agency->forceFill(['is_default_portal' => true])->save();
    }

    private function portalUser(string $email, array $clients, ?array $projects = null, string $role = 'client_viewer', ?string $phone = null, bool $phoneVerified = false): User
    {
        $user = User::create(['name' => 'Client', 'email' => $email, 'password' => 'secret123', 'phone' => $phone]);
        if ($phoneVerified) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::ClientPortal, role: $role,
            clientScopeIds: $clients, projectScopeIds: $projects, grantedBy: $this->owner(),
        ));

        return $user;
    }

    private function request(string $reference, ?string $clientId, string $phone): ExternalRequest
    {
        return ExternalRequest::create([
            'tenant_id' => $this->agency->id, 'reference' => $reference,
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->where('key', 'new')->firstOrFail()->id,
            'contact_name' => 'Victim', 'contact_email' => 'victim@x.test', 'contact_phone' => $phone,
            'client_id' => $clientId, 'submitted_at' => now(),
        ]);
    }

    public function test_an_unverified_profile_phone_does_not_claim_another_contacts_pending_requests(): void
    {
        $this->request('REQ-VICTIM-PENDING', null, '+966500000777');
        $attacker = $this->portalUser('att@x.test', [(string) $this->clientA->id], null, 'client_viewer', '+966500000777', false);

        $this->actingAs($attacker);
        $res = $this->getJson('/api/v1/client/requests', $this->spa)->assertOk();
        $this->assertNotContains('REQ-VICTIM-PENDING', array_column($res->json('data.requests'), 'reference'));
        $this->getJson('/api/v1/client/requests/REQ-VICTIM-PENDING', $this->spa)->assertNotFound();
    }

    public function test_a_verified_profile_phone_still_finds_the_users_own_pending_requests(): void
    {
        $this->request('REQ-MINE-PENDING', null, '+966500000888');
        $user = $this->portalUser('me@x.test', [(string) $this->clientA->id], null, 'client_viewer', '+966500000888', true);

        $this->actingAs($user);
        $res = $this->getJson('/api/v1/client/requests', $this->spa)->assertOk();
        $this->assertContains('REQ-MINE-PENDING', array_column($res->json('data.requests'), 'reference'));
    }

    public function test_a_phone_code_session_keeps_the_project_ceiling_of_the_members_portal_membership(): void
    {
        $second = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $this->clientA->id, 'name' => 'Second Brand', 'status' => 'active']);
        foreach ([[$this->projectA, 'First campaign'], [$second, 'Second campaign']] as [$project, $name]) {
            UnifiedCampaign::create(['tenant_id' => $this->agency->id, 'project_id' => $project->id, 'client_workspace_id' => $this->clientA->id, 'name' => $name, 'objective' => 'conversions', 'status' => 'active']);
        }
        // The member's own request carries their phone, which is what makes the legacy engine hand them the whole client.
        $this->request('REQ-OWN', (string) $this->clientA->id, '+966500000999');
        $this->portalUser('limited@x.test', [(string) $this->clientA->id], [(string) $this->projectA->id], 'client_viewer', '+966500000999', true);

        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'sms', 'destination' => '+966500000999'])->assertCreated();
        $token = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'), 'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');

        $campaigns = $this->withHeaders(['X-Client-Token' => $token])->getJson('/api/v1/client/campaigns')->assertOk();
        $this->assertSame(['First campaign'], array_column($campaigns->json('data.campaigns'), 'name'));
    }

    public function test_a_grant_from_one_client_never_rewrites_the_role_or_projects_held_on_a_sibling(): void
    {
        $projectsOnA = [(string) $this->projectA->id];
        $person = $this->portalUser('both@x.test', [(string) $this->clientA->id], $projectsOnA, 'client_viewer');
        $adminB = $this->operatorIn($this->agency, ['clients.view', 'clients.manage_team'], [(string) $this->clientB->id]);

        // Client B's operator asks for a wider role and the whole of client B.
        $this->actingAs($adminB, 'sanctum')
            ->postJson("/api/v1/app/clients/{$this->clientB->id}/portal-users", ['email' => 'both@x.test', 'role' => 'client_approver'])
            ->assertStatus(422);

        $membership = Membership::query()->where('user_id', $person->id)->where('portal', Portal::ClientPortal->value)->firstOrFail();
        $this->assertSame('client_viewer', $membership->role);
        $this->assertSame($projectsOnA, $membership->fresh('scopes')->projectScopeIds());
        $this->assertSame([(string) $this->clientA->id], $membership->fresh('scopes')->clientScopeIds());

        // The whole of client B would also drop the project ceiling the person holds on client A.
        $this->actingAs($adminB, 'sanctum')
            ->postJson("/api/v1/app/clients/{$this->clientB->id}/portal-users", ['email' => 'both@x.test', 'role' => 'client_viewer'])
            ->assertStatus(422);
    }

    public function test_asking_for_what_the_person_already_holds_adds_the_client_without_changing_anything(): void
    {
        $person = $this->portalUser('same@x.test', [(string) $this->clientA->id], null, 'client_viewer');
        $adminB = $this->operatorIn($this->agency, ['clients.view', 'clients.manage_team'], [(string) $this->clientB->id]);

        $this->actingAs($adminB, 'sanctum')
            ->postJson("/api/v1/app/clients/{$this->clientB->id}/portal-users", ['email' => 'same@x.test', 'role' => 'client_viewer'])
            ->assertOk();

        $membership = Membership::query()->where('user_id', $person->id)->where('portal', Portal::ClientPortal->value)->firstOrFail();
        $this->assertSame('client_viewer', $membership->role);
        $this->assertEqualsCanonicalizing([(string) $this->clientA->id, (string) $this->clientB->id], $membership->fresh('scopes')->clientScopeIds());
    }

    public function test_an_operator_who_reaches_every_client_the_person_holds_may_still_change_their_role(): void
    {
        $person = $this->portalUser('both2@x.test', [(string) $this->clientA->id], null, 'client_viewer');

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/app/clients/{$this->clientB->id}/portal-users", ['email' => 'both2@x.test', 'role' => 'client_approver'])
            ->assertOk();

        $membership = Membership::query()->where('user_id', $person->id)->where('portal', Portal::ClientPortal->value)->firstOrFail();
        $this->assertSame('client_approver', $membership->role);
        $this->assertEqualsCanonicalizing([(string) $this->clientA->id, (string) $this->clientB->id], $membership->fresh('scopes')->clientScopeIds());
    }
}
