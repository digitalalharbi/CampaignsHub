<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Requests\Journey\InvalidStageTransitionException;
use App\Domains\Requests\Journey\RequestStage;
use App\Domains\Requests\Journey\RequestTaxonomy;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Services\RequestJourneyService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RequestJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($ownerRole);
    }

    private function makeRequest(): ExternalRequest
    {
        return ExternalRequest::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-2026-'.strtoupper(bin2hex(random_bytes(3))),
            'module' => 'paid_media',
            'type_id' => RequestType::where('key', 'paid_campaign_launch')->value('id'),
            'status_id' => RequestStatus::where('key', 'new')->value('id'),
            'priority' => 'medium',
            'source' => 'public_portal',
            'contact_name' => 'Client Co',
            'contact_email' => 'c@co.test',
            'assigned_to' => $this->owner->id,
        ]);
    }

    private function service(): RequestJourneyService
    {
        return app(RequestJourneyService::class);
    }

    public function test_full_valid_path_from_draft_to_completed(): void
    {
        $req = $this->makeRequest();
        $svc = $this->service();

        $this->assertSame(RequestStage::Draft, $svc->currentStage($req)); // default when unset

        $path = [
            RequestStage::Submitted, RequestStage::UnderReview, RequestStage::Qualified,
            RequestStage::ProposalSent, RequestStage::AwaitingClientApproval, RequestStage::PaymentPending,
            RequestStage::Paid, RequestStage::Onboarding, RequestStage::InProgress,
            RequestStage::ClientReview, RequestStage::Completed,
        ];
        foreach ($path as $stage) {
            $svc->transition($req, $stage, $this->owner);
        }

        $req->refresh();
        $this->assertSame('completed', $req->journey_stage);
        $this->assertSame('paid', $req->payment_status); // stamped when it passed through Paid

        // The whole journey is captured on the event timeline.
        $this->assertSame(count($path), $req->events()->where('type', 'journey_transition')->count());

        // And it can be archived from completed.
        $svc->transition($req, RequestStage::Archived, $this->owner);
        $req->refresh();
        $this->assertSame('archived', $req->journey_stage);
        $this->assertNotNull($req->archived_at);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $req = $this->makeRequest();

        $this->expectException(InvalidStageTransitionException::class);
        $this->service()->transition($req, RequestStage::Completed, $this->owner); // draft → completed is illegal

        $req->refresh();
        $this->assertNull($req->journey_stage); // nothing was written
    }

    public function test_on_hold_sets_reason_and_resume_clears_it(): void
    {
        $req = $this->makeRequest();
        $svc = $this->service();

        $svc->transition($req, RequestStage::Submitted, $this->owner);
        $svc->transition($req, RequestStage::UnderReview, $this->owner);
        $svc->transition($req, RequestStage::OnHold, $this->owner, 'Waiting on legal sign-off');

        $req->refresh();
        $this->assertSame('on_hold', $req->journey_stage);
        $this->assertSame('Waiting on legal sign-off', $req->on_hold_reason);

        // Resuming into an active stage clears the hold reason.
        $svc->transition($req, RequestStage::UnderReview, $this->owner);
        $req->refresh();
        $this->assertSame('under_review', $req->journey_stage);
        $this->assertNull($req->on_hold_reason);
    }

    public function test_cancel_reject_and_refund_states_set_their_fields(): void
    {
        $svc = $this->service();

        // Cancel from draft.
        $cancelled = $this->makeRequest();
        $svc->transition($cancelled, RequestStage::Cancelled, $this->owner, 'Client withdrew');
        $this->assertSame('cancelled', $cancelled->refresh()->journey_stage);

        // Reject from submitted.
        $rejected = $this->makeRequest();
        $svc->transition($rejected, RequestStage::Submitted, $this->owner);
        $svc->transition($rejected, RequestStage::Rejected, $this->owner, 'Out of scope');
        $this->assertSame('rejected', $rejected->refresh()->journey_stage);

        // Refund after a paid journey → payment_status refunded.
        $refunded = $this->makeRequest();
        foreach ([RequestStage::Submitted, RequestStage::UnderReview, RequestStage::Qualified,
            RequestStage::ProposalSent, RequestStage::AwaitingClientApproval, RequestStage::PaymentPending,
            RequestStage::Paid, RequestStage::Refunded] as $stage) {
            $svc->transition($refunded, $stage, $this->owner);
        }
        $refunded->refresh();
        $this->assertSame('refunded', $refunded->journey_stage);
        $this->assertSame('refunded', $refunded->payment_status);
    }

    public function test_payment_pending_failed_recovery_updates_payment_status(): void
    {
        $req = $this->makeRequest();
        $svc = $this->service();

        foreach ([RequestStage::Submitted, RequestStage::UnderReview, RequestStage::Qualified,
            RequestStage::ProposalSent, RequestStage::AwaitingClientApproval, RequestStage::PaymentPending] as $stage) {
            $svc->transition($req, $stage, $this->owner);
        }
        $this->assertSame('pending', $req->refresh()->payment_status);

        $svc->transition($req, RequestStage::PaymentFailed, $this->owner);
        $this->assertSame('failed', $req->refresh()->payment_status);

        $svc->transition($req, RequestStage::PaymentPending, $this->owner);
        $svc->transition($req, RequestStage::Paid, $this->owner);
        $this->assertSame('paid', $req->refresh()->payment_status);
    }

    public function test_key_transition_writes_audit_and_raises_notification(): void
    {
        $req = $this->makeRequest();
        $svc = $this->service();

        $svc->transition($req, RequestStage::Submitted, $this->owner);
        $svc->transition($req, RequestStage::UnderReview, $this->owner);
        $svc->transition($req, RequestStage::Qualified, $this->owner);
        $svc->transition($req, RequestStage::ProposalSent, $this->owner); // notifiable milestone

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'request.journey.transitioned',
            'entity_type' => 'external_request',
            'entity_id' => $req->id,
        ]);

        $this->assertDatabaseHas('app_notifications', [
            'tenant_id' => $this->tenant->id,
            'type' => 'request.journey.proposal_sent',
            'entity_type' => 'external_request',
            'entity_id' => $req->id,
            'action_url' => "/app/requests/{$req->id}/journey/proposal_sent",
        ]);

        // A non-notifiable transition (submitted) must NOT have produced its own notification.
        $this->assertDatabaseMissing('app_notifications', ['type' => 'request.journey.submitted']);
    }

    public function test_taxonomy_validation_accepts_known_and_rejects_unknown(): void
    {
        $this->assertContains('Paid Advertising Management', RequestTaxonomy::services());
        $this->assertContains('New Campaign', RequestTaxonomy::categoriesFor('Paid Advertising Management'));
        $this->assertTrue(RequestTaxonomy::isValidPath('Paid Advertising Management', 'New Campaign', 'Search Campaign'));
        $this->assertSame('paid_media', RequestTaxonomy::moduleForService('Paid Advertising Management'));

        $this->assertFalse(RequestTaxonomy::isValidService('Nonexistent Service'));
        $this->assertFalse(RequestTaxonomy::isValidCategory('Paid Advertising Management', 'Bogus Category'));
        $this->assertFalse(RequestTaxonomy::isValidPath('Paid Advertising Management', 'New Campaign', 'Bogus Type'));

        $this->assertTrue(RequestTaxonomy::isValidPriority('high'));
        $this->assertFalse(RequestTaxonomy::isValidPriority('urgent'));
        $this->assertTrue(RequestTaxonomy::isValidObjective('sales'));
        $this->assertFalse(RequestTaxonomy::isValidObjective('world_domination'));
        $this->assertTrue(RequestTaxonomy::isValidSource('public_portal'));
        $this->assertFalse(RequestTaxonomy::isValidSource('carrier_pigeon'));
    }

    public function test_transition_endpoint_enforces_the_state_machine(): void
    {
        $req = $this->makeRequest();

        // Illegal jump → 422 from the state machine.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/app/requests/{$req->id}/journey", ['stage' => 'completed'])
            ->assertStatus(422);

        // Legal move → 200 and the stage is advanced.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/app/requests/{$req->id}/journey", ['stage' => 'submitted'])
            ->assertOk()
            ->assertJsonPath('data.journey_stage', 'submitted');

        $this->assertSame('submitted', $req->refresh()->journey_stage);
    }
}
