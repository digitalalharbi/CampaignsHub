<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ExternalRequest;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'paid_campaign_launch',
            'contact_name' => 'Sara Ali',
            'contact_email' => 'sara@example.com',
            'objective' => 'Increase online sales for Ramadan.',
            'budget' => 25000,
            'currency' => 'SAR',
        ], $overrides);
    }

    public function test_public_intake_creates_request_reference_token_and_event(): void
    {
        $res = $this->postJson('/api/v1/requests', $this->payload())->assertCreated();

        $res->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.email_delivery', 'awaiting_credentials')
            ->assertJsonStructure(['data' => ['reference', 'type', 'tracking_token', 'tracking_url']]);

        $ref = $res->json('data.reference');
        $this->assertMatchesRegularExpression('/^REQ-\d{4}-[A-Z0-9]{6}$/', $ref);

        $req = ExternalRequest::where('reference', $ref)->firstOrFail();
        $this->assertTrue($req->is_external);
        $this->assertNotNull($req->submitted_at);
        $this->assertNotNull($req->sla_due_at);
        $this->assertEquals(1, $req->events()->where('type', 'created')->count());
    }

    public function test_token_is_stored_hashed_not_in_plaintext(): void
    {
        $token = $this->postJson('/api/v1/requests', $this->payload())->json('data.tracking_token');

        $this->assertDatabaseMissing('request_access_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('request_access_tokens', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_honeypot_and_validation_reject_bad_input(): void
    {
        // Honeypot filled → rejected.
        $this->postJson('/api/v1/requests', $this->payload(['website' => 'http://spam']))
            ->assertStatus(422)->assertJsonValidationErrors('website');

        // Missing service type.
        $this->postJson('/api/v1/requests', $this->payload(['type' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('type');

        // Unknown type.
        $this->postJson('/api/v1/requests', $this->payload(['type' => 'not_a_type']))
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_tracking_returns_client_safe_view_and_hides_internal_notes(): void
    {
        $token = $this->postJson('/api/v1/requests', $this->payload())->json('data.tracking_token');
        $req = ExternalRequest::firstOrFail();

        // One internal note (must NOT leak) and one client comment (must show).
        $req->comments()->create(['visibility' => 'internal', 'body' => 'SECRET internal analysis', 'author_label' => 'Analyst']);
        $req->comments()->create(['visibility' => 'client', 'body' => 'We received your request.', 'author_label' => 'Team']);
        // An internal-only event (must NOT leak).
        $req->events()->create(['type' => 'status_changed', 'to_status' => 'under_review', 'is_client_visible' => false, 'message' => 'internal move', 'created_at' => now()]);

        $res = $this->getJson("/api/v1/requests/track/{$token}")->assertOk();
        $body = $res->getContent();

        $this->assertStringNotContainsString('SECRET internal analysis', $body);
        $this->assertStringNotContainsString('internal move', $body);
        $this->assertStringContainsString('We received your request.', $body);
        // No internal fields exposed.
        $res->assertJsonMissingPath('data.assigned_to')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.sla_due_at');
        $res->assertJsonPath('data.reference', $req->reference);
    }

    public function test_tracking_rejects_unknown_and_revoked_tokens(): void
    {
        $this->getJson('/api/v1/requests/track/nonexistent-token')->assertNotFound();

        $token = $this->postJson('/api/v1/requests', $this->payload())->json('data.tracking_token');
        ExternalRequest::firstOrFail()->tokens()->first()->forceFill(['revoked_at' => now()])->save();
        $this->getJson("/api/v1/requests/track/{$token}")->assertStatus(410);
    }
}
