<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — the internal requests inbox honours the membership's client ceiling (ADR 0002, REG-001).
 * A manager named to client B reaches B's requests and nothing of client A's, by list, summary or id.
 */
final class InternalRequestsClientCeilingTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    private ExternalRequest $reqA;

    private ExternalRequest $reqB;

    private ExternalRequest $reqNobody;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->buildCeilingFixture();
        $this->reqA = $this->makeRequest('REQ-CEIL-A', (string) $this->clientA->id);
        $this->reqB = $this->makeRequest('REQ-CEIL-B', (string) $this->clientB->id);
        $this->reqNobody = $this->makeRequest('REQ-CEIL-NONE', null);
    }

    private function makeRequest(string $reference, ?string $clientId): ExternalRequest
    {
        return ExternalRequest::create([
            'tenant_id' => $this->agency->id, 'reference' => $reference,
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->where('key', 'new')->firstOrFail()->id,
            'contact_name' => 'Contact '.$reference, 'contact_email' => $reference.'@x.test', 'contact_phone' => '+966500000009',
            'client_id' => $clientId, 'submitted_at' => now(),
        ]);
    }

    private const PERMS = ['requests.view', 'requests.assign', 'requests.change_status', 'requests.change_priority',
        'requests.comment_internal', 'requests.comment_client', 'requests.archive', 'requests.convert',
        'requests.request_information', 'billing.manage'];

    public function test_a_scoped_manager_lists_only_their_clients_requests_and_counts(): void
    {
        $manager = $this->scopedTo($this->clientB, self::PERMS);

        $res = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/app/requests')->assertOk();

        $this->assertSame(['REQ-CEIL-B'], array_column($res->json('data'), 'reference'));
        $this->assertSame(1, $res->json('meta.summary.total'));
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_an_unrestricted_reader_still_sees_every_request(): void
    {
        $res = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/app/requests')->assertOk();

        $this->assertEqualsCanonicalizing(['REQ-CEIL-A', 'REQ-CEIL-B', 'REQ-CEIL-NONE'], array_column($res->json('data'), 'reference'));
    }

    public function test_a_scoped_manager_cannot_open_another_clients_request(): void
    {
        $manager = $this->scopedTo($this->clientB, self::PERMS);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/app/requests/{$this->reqA->id}")->assertNotFound();
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/app/requests/{$this->reqB->id}")->assertOk();
    }

    public function test_a_scoped_manager_cannot_change_another_clients_request(): void
    {
        $manager = $this->scopedTo($this->clientB, self::PERMS);
        $id = $this->reqA->id;

        $this->actingAs($manager, 'sanctum')->patchJson("/api/v1/app/requests/{$id}/assign", ['assignee_id' => $manager->id])->assertNotFound();
        $this->actingAs($manager, 'sanctum')->patchJson("/api/v1/app/requests/{$id}/status", ['status' => 'under_review'])->assertNotFound();
        $this->actingAs($manager, 'sanctum')->patchJson("/api/v1/app/requests/{$id}/priority", ['priority' => 'high'])->assertNotFound();
        $this->actingAs($manager, 'sanctum')->patchJson("/api/v1/app/requests/{$id}/journey", ['stage' => 'under_review'])->assertNotFound();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/app/requests/{$id}/internal-note", ['body' => 'hello'])->assertNotFound();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/app/requests/{$id}/reply", ['body' => 'hello'])->assertNotFound();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/app/requests/{$id}/request-information", ['message' => 'hello'])->assertNotFound();
        $this->actingAs($manager, 'sanctum')->patchJson("/api/v1/app/requests/{$id}/archive")->assertNotFound();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/app/requests/{$id}/convert")->assertNotFound();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/app/requests/{$id}/quote")->assertNotFound();

        $this->assertNull($this->reqA->fresh()->assigned_to);
    }

    public function test_conversion_cannot_file_a_request_under_a_client_the_actor_cannot_reach(): void
    {
        $manager = $this->scopedTo($this->clientB, self::PERMS);
        $mine = $this->makeRequest('REQ-CEIL-B2', (string) $this->clientB->id);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/app/requests/{$mine->id}/convert", ['client_id' => (string) $this->clientA->id])
            ->assertForbidden();

        $this->assertNotSame((string) $this->clientA->id, (string) $mine->fresh()->client_id);
    }
}
