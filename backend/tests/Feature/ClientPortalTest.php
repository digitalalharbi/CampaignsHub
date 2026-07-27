<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * External Client Portal acceptance: verify phone + email (OTP) → submit → get reference → portal login →
 * list requests → view → reply → persistence + client-safety + contact-scoped isolation.
 */
final class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
    }

    /** Run an OTP challenge to completion and return the verified verification id. */
    private function verify(string $channel, string $destination, string $purpose = 'contact_verify'): string
    {
        $start = $this->postJson('/api/v1/requests/verify/start', compact('channel', 'destination') + ['purpose' => $purpose])
            ->assertCreated();
        $id = $start->json('data.verification_id');
        $code = $start->json('data.dev_code');
        $this->assertNotNull($code, 'dev_code should be exposed in non-production');
        $this->postJson('/api/v1/requests/verify/check', ['verification_id' => $id, 'code' => $code])
            ->assertOk()->assertJsonPath('data.verified', true);

        return $id;
    }

    private function submit(string $phone, string $email): TestResponse
    {
        return $this->postJson('/api/v1/requests', [
            'type' => 'paid_campaign_launch',
            'contact_name' => 'Client One',
            'contact_email' => $email,
            'contact_phone' => $phone,
            'company_name' => 'Client Co',
            'objective' => 'Run a summer campaign.',
            'phone_verification_id' => $this->verify('sms', $phone),
            'email_verification_id' => $this->verify('email', $email),
        ]);
    }

    /** Log into the portal via email OTP; return the raw portal token (dev-only) for the X-Client-Token header. */
    private function portalLogin(string $email): string
    {
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])->assertCreated();
        $verify = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->assertJsonPath('data.authenticated', true);

        // The browser uses the httpOnly cookie; tests authenticate via the dev token header.
        $this->assertNotNull(collect($verify->headers->getCookies())->first(fn ($c) => $c->getName() === 'client_portal'));

        return $verify->json('data.dev_token');
    }

    /** @return array<string,string> */
    private function auth(string $token): array
    {
        return ['X-Client-Token' => $token];
    }

    public function test_delivery_is_awaiting_provider_credentials_without_a_provider(): void
    {
        $this->postJson('/api/v1/requests/verify/start', ['channel' => 'sms', 'destination' => '+966500000009'])
            ->assertCreated()->assertJsonPath('data.delivery_status', 'awaiting_provider_credentials');
    }

    public function test_intake_is_rejected_without_verified_contact(): void
    {
        $this->postJson('/api/v1/requests', [
            'type' => 'paid_campaign_launch', 'contact_name' => 'X', 'contact_email' => 'x@x.test',
            'contact_phone' => '+966500000001', 'company_name' => 'Co',
        ])->assertStatus(422);
    }

    public function test_intake_rejects_verification_for_a_different_destination(): void
    {
        $phoneVid = $this->verify('sms', '+966500000002'); // verified a DIFFERENT phone
        $emailVid = $this->verify('email', 'a@x.test');

        $this->postJson('/api/v1/requests', [
            'type' => 'paid_campaign_launch', 'contact_name' => 'X', 'contact_email' => 'a@x.test',
            'contact_phone' => '+966500000001', 'company_name' => 'Co',
            'phone_verification_id' => $phoneVid, 'email_verification_id' => $emailVid,
        ])->assertStatus(422);
    }

    public function test_full_portal_flow_submit_login_view_reply_persist(): void
    {
        $phone = '+966500000001';
        $email = 'client@x.test';

        $ref = $this->submit($phone, $email)->assertCreated()->json('data.reference');
        $this->assertMatchesRegularExpression('/REQ-\d{4}-[A-Z0-9]{6}/', $ref);

        $cookie = $this->portalLogin($email);

        // Dashboard lists the request.
        $this->withHeaders($this->auth($cookie))->getJson('/api/v1/client/requests')
            ->assertOk()->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.reference', $ref);

        // Detail is client-safe and has a progress bar.
        $detail = $this->withHeaders($this->auth($cookie))->getJson("/api/v1/client/requests/{$ref}")->assertOk();
        $detail->assertJsonPath('data.reference', $ref);
        $this->assertGreaterThan(0, $detail->json('data.progress'));

        // Reply persists.
        $this->withCookie('client_portal', $cookie)
            ->postJson("/api/v1/client/requests/{$ref}/reply", ['message' => 'Any update please?'])->assertCreated();
        $this->withHeaders($this->auth($cookie))->getJson("/api/v1/client/requests/{$ref}")
            ->assertOk()->assertJsonFragment(['body' => 'Any update please?']);
    }

    public function test_internal_notes_are_never_exposed_in_the_portal(): void
    {
        $phone = '+966500000003';
        $email = 'safe@x.test';
        $ref = $this->submit($phone, $email)->json('data.reference');
        $req = ExternalRequest::where('reference', $ref)->firstOrFail();
        $req->comments()->create(['visibility' => 'internal', 'author_label' => 'Staff', 'body' => 'SECRET internal note']);

        $cookie = $this->portalLogin($email);
        $res = $this->withHeaders($this->auth($cookie))->getJson("/api/v1/client/requests/{$ref}")->assertOk();
        $this->assertStringNotContainsString('SECRET internal note', $res->getContent());
    }

    public function test_portal_is_contact_scoped(): void
    {
        $ref = $this->submit('+966500000004', 'owner@x.test')->json('data.reference');

        // A different verified contact cannot see it.
        $otherCookie = $this->portalLogin('stranger@x.test');
        $this->withHeaders($this->auth($otherCookie))->getJson('/api/v1/client/requests')
            ->assertOk()->assertJsonCount(0, 'data.requests');
        $this->withHeaders($this->auth($otherCookie))->getJson("/api/v1/client/requests/{$ref}")
            ->assertNotFound();
    }

    public function test_portal_requires_a_session(): void
    {
        $this->getJson('/api/v1/client/requests')->assertUnauthorized();
    }

    public function test_lifecycle_notifications_are_recorded_honestly_and_deduped(): void
    {
        $email = 'notif@x.test';
        $phone = '+966500000005';
        $ref = $this->submit($phone, $email)->json('data.reference');

        // "received" recorded on BOTH channels, honestly awaiting provider credentials (never "sent").
        $this->assertDatabaseHas('client_notifications', ['event' => 'received', 'channel' => 'email', 'status' => 'awaiting_provider_credentials']);
        $this->assertDatabaseHas('client_notifications', ['event' => 'received', 'channel' => 'whatsapp', 'status' => 'awaiting_provider_credentials']);
        $this->assertDatabaseMissing('client_notifications', ['status' => 'sent']);

        // Deduplicated per (request,event,channel) — re-notifying the same event adds nothing.
        $req = ExternalRequest::where('reference', $ref)->firstOrFail();
        app(\App\Domains\Requests\Services\ClientNotifier::class)->notify($req, 'received');
        $this->assertSame(2, (int) \Illuminate\Support\Facades\DB::table('client_notifications')->where('event', 'received')->count());

        // The portal surfaces the delivery log honestly.
        $cookie = $this->portalLogin($email);
        $this->withHeaders($this->auth($cookie))->getJson("/api/v1/client/requests/{$ref}")
            ->assertOk()->assertJsonFragment(['channel' => 'email', 'status' => 'awaiting_provider_credentials']);
    }
}
