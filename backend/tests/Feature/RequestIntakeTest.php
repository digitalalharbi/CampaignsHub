<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\VerifiesContact;
use Tests\TestCase;

final class RequestIntakeTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesContact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        Tenant::create(['name' => 'Portal', 'slug' => 'portal-'.uniqid(), 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return $this->withVerifiedContact(array_merge([
            'type' => 'paid_campaign_launch',
            'contact_name' => 'Sara Ali',
            'contact_email' => 'sara@example.com',
            'company_name' => 'Sara Store',
            'objective' => 'Increase online sales for Ramadan.',
            'budget' => 25000,
            'currency' => 'SAR',
        ], $overrides));
    }

    /**
     * The intake catalogue lists what is on OFFER.
     *
     * Ten rather than eleven, because the influencer/UGC type is withdrawn while its sub-system is
     * off (INFL-OFF-001) — and withdrawn is not the same as gone: the assertion below reads the row
     * straight from the table to prove it is still there, still active, and still available to every
     * request already attached to it. A count alone could not tell those two apart.
     */
    public function test_meta_exposes_active_service_types(): void
    {
        $this->getJson('/api/v1/requests/meta')
            ->assertOk()
            ->assertJsonPath('data.types.0.key', 'paid_campaign_launch')
            ->assertJsonPath('data.types.0.module', 'paid_media')
            ->assertJsonCount(10, 'data.types');
    }

    public function test_the_withdrawn_service_type_is_preserved_rather_than_deleted(): void
    {
        $offered = collect($this->getJson('/api/v1/requests/meta')->assertOk()->json('data.types'))
            ->pluck('key');

        $this->assertFalse($offered->contains('influencer_ugc'), 'a withdrawn service is still being offered');

        $row = RequestType::query()->where('key', 'influencer_ugc')->first();

        $this->assertNotNull($row, 'the influencer service type was deleted rather than withdrawn');
        $this->assertTrue($row->is_active, 'the row must stay active — every existing request hangs off it');
    }

    /** …and a payload naming it is refused, rather than quietly opening a request nobody can serve. */
    public function test_a_new_request_cannot_be_opened_for_a_withdrawn_service(): void
    {
        $this->postJson('/api/v1/requests', $this->payload(['type' => 'influencer_ugc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    /**
     * INFL-SOON-001 — the withdrawn service is ANNOUNCED, in a list of its own.
     *
     * Absent from the form entirely, it read as «this product does not do influencer work» to anyone
     * looking for it. Naming it as «قريبًا» answers that. The separate key is the guarantee: the list
     * the form submits against holds only submittable things, so no client can turn the announcement
     * into an order by ignoring a flag.
     */
    public function test_the_withdrawn_service_is_announced_separately_from_the_openable_ones(): void
    {
        $res = $this->getJson('/api/v1/requests/meta')->assertOk();

        $offered = collect($res->json('data.types'))->pluck('key');
        $soon = collect($res->json('data.coming_soon'));

        $this->assertTrue($soon->pluck('key')->contains('influencer_ugc'));
        $this->assertFalse($offered->contains('influencer_ugc'));

        // The two lists are complements — nothing may appear in both.
        $this->assertEmpty(array_intersect($offered->all(), $soon->pluck('key')->all()));

        // The refusal travels with the row, so the page shows the server's sentence, not its own.
        $row = $soon->firstWhere('key', 'influencer_ugc');
        $this->assertStringContainsString('قريبًا', $row['note_ar']);
        $this->assertNotEmpty($row['note_en']);
    }

    /**
     * Announcing it does not make it openable.
     *
     * The pairing that matters: the key is now visible in a public response, which is exactly the
     * circumstance under which somebody copies it into a payload by hand.
     */
    public function test_a_service_that_is_only_announced_still_cannot_be_submitted(): void
    {
        $announced = collect($this->getJson('/api/v1/requests/meta')->json('data.coming_soon'))->pluck('key');

        foreach ($announced as $key) {
            $this->postJson('/api/v1/requests', $this->payload(['type' => $key]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('type');
        }
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

    public function test_client_reply_creates_a_client_visible_message_and_shows_in_tracking(): void
    {
        $token = $this->postJson('/api/v1/requests', $this->payload())->json('data.tracking_token');

        $this->postJson("/api/v1/requests/track/{$token}/reply", ['message' => 'Here is more info about our store.'])
            ->assertCreated()->assertJsonPath('data.status', 'received');

        $body = $this->getJson("/api/v1/requests/track/{$token}")->assertOk()->getContent();
        $this->assertStringContainsString('Here is more info about our store.', $body);

        // A client reply is stored as a client-visible comment — never an internal note.
        $this->assertDatabaseHas('request_comments', ['visibility' => 'client', 'author_label' => 'Client']);
        $this->assertDatabaseMissing('request_comments', ['visibility' => 'internal', 'body' => 'Here is more info about our store.']);
    }

    public function test_client_reply_requires_a_message_and_a_valid_token(): void
    {
        $token = $this->postJson('/api/v1/requests', $this->payload())->json('data.tracking_token');
        $this->postJson("/api/v1/requests/track/{$token}/reply", ['message' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson('/api/v1/requests/track/bad-token/reply', ['message' => 'hi there'])->assertNotFound();
    }

    public function test_tracking_rejects_unknown_and_revoked_tokens(): void
    {
        $this->getJson('/api/v1/requests/track/nonexistent-token')->assertNotFound();

        $token = $this->postJson('/api/v1/requests', $this->payload())->json('data.tracking_token');
        ExternalRequest::firstOrFail()->tokens()->first()->forceFill(['revoked_at' => now()])->save();
        $this->getJson("/api/v1/requests/track/{$token}")->assertStatus(410);
    }
}
