<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Legal\Models\ContactMessage;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AppliesToRegister;
use Tests\Concerns\VerifiesContact;
use Tests\TestCase;

/**
 * SAAS-STRUCTURE-AUDIT-001 — the ownership and safe-refusal probes behind
 * `docs/SAAS_STRUCTURE_AND_REQUEST_ROUTING_AUDIT.md` (contract §7, S01–S22).
 *
 * Two kinds of test live here, and the difference is the point of the file:
 *
 *  - a probe that PASSES states a property the product already has, and the audit cites it;
 *  - a probe that reaches `markTestIncomplete()` first PROVES the gap with an assertion on today's
 *    behaviour, and then stops. Nothing here is skipped on a guess: each incomplete names what it
 *    observed, and the batch that closes it removes the early return so the assertions below it run.
 *
 * No probe weakens an existing security test, and none of them is a second copy of one — where a
 * scenario is already proven (invitations, payment webhooks, client-portal isolation, creators) the
 * audit points at that test instead.
 */
final class SaasStructureRoutingContractTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;
    use VerifiesContact;

    private Tenant $agency;

    private User $agencyOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);

        $this->agency = Tenant::create([
            'name' => 'Subscriber Agency', 'slug' => 'subscriber-agency', 'status' => 'active',
            'is_default_portal' => true, 'portal_enabled' => true,
        ]);
        $this->agencyOwner = $this->ownerOf($this->agency, 'owner@subscriber.test');
    }

    private function ownerOf(Tenant $tenant, string $email): User
    {
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $user = User::create(['name' => 'Owner', 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant, Portal::Agency);
        $user->assignRole($role);

        return $user;
    }

    private function platformOwner(): User
    {
        $user = User::create([
            'name' => 'Platform', 'email' => 'platform@campaignshub.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user->refresh();
    }

    /**
     * One public submission. `$host` is the host the request ARRIVES on — the test client takes it from the URL, so a
     * relative path is always `localhost`; `$headers` are extra request headers a forger might add.
     *
     * @param  array<string, string>  $headers
     */
    private function submit(?string $host = null, string $email = 'visitor@example.test', array $headers = []): TestResponse
    {
        app(TenantContext::class)->forget();
        $payload = $this->withVerifiedContact([
            'type' => 'paid_campaign_launch', 'contact_name' => 'Visitor', 'contact_email' => $email, 'company_name' => 'Visitor Co',
        ]);

        return $this->postJson(($host === null ? '' : 'http://'.$host).'/api/v1/requests', $payload, $headers);
    }

    private function asAgency(User $user, Tenant $tenant): self
    {
        app(TenantContext::class)->setTenantId($tenant->id);

        return $this->actingAs($user, 'sanctum');
    }

    // ── S01 / S22 — a service bought from the official site belongs to the platform ────────────

    public function test_s01_a_service_request_from_the_official_site_is_platform_owned(): void
    {
        $this->submit('campaignshub.io')->assertCreated();
        $row = ExternalRequest::query()->sole();

        if (! Schema::hasColumn('external_requests', 'owner_scope')) {
            // What today's code does, asserted so the gap is a fact and not a reading of the source.
            $this->assertSame((string) $this->agency->id, (string) $row->tenant_id);
            $this->asAgency($this->agencyOwner, $this->agency)->getJson('/api/v1/app/requests')
                ->assertOk()->assertJsonPath('meta.total', 1);

            $this->markTestIncomplete(
                'PROVEN GAP (S01, contract §5 P0-6): a request made on the official host is written under the '
                .'`is_default_portal` tenant and listed in that subscriber agency\'s inbox; there is no platform-owned '
                .'inbox and no `owner_scope`. Ledger #168, closed by PLATFORM-SERVICE-INBOX-001.'
            );
        }

        $this->assertSame('platform', $row->owner_scope);
        $this->assertNull($row->tenant_id);
        $this->asAgency($this->agencyOwner, $this->agency)->getJson('/api/v1/app/requests')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ── S02 — a subscription application is a registration and never a service request ─────────

    public function test_s02_a_registration_is_never_a_service_request_in_any_inbox(): void
    {
        $this->apply(['email' => 'applicant@example.test'])->assertStatus(202);

        $this->assertSame(1, RegistrationRequest::query()->count());
        $this->assertSame(0, ExternalRequest::query()->count());
        $this->asAgency($this->agencyOwner, $this->agency)->getJson('/api/v1/app/requests')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ── S03 — a tenant's own portal domain files under that tenant only ───────────────────────

    public function test_s03_a_request_on_a_tenants_portal_domain_is_filed_under_that_tenant_only(): void
    {
        $other = Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active',
            'portal_enabled' => true, 'portal_domain' => 'requests.tenant-a.test',
        ]);

        $this->submit('requests.tenant-a.test')->assertCreated();

        $this->assertSame((string) $other->id, (string) ExternalRequest::query()->sole()->tenant_id);
        $this->asAgency($this->agencyOwner, $this->agency)->getJson('/api/v1/app/requests')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ── S04 — another tenant's request id reaches nothing, on any verb ────────────────────────

    public function test_s04_another_tenants_request_is_not_found_on_every_internal_route(): void
    {
        $this->submit()->assertCreated();
        $request = ExternalRequest::query()->sole();

        $other = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $intruder = $this->ownerOf($other, 'owner@tenant-b.test');
        $base = '/api/v1/app/requests/'.$request->id;

        $attempts = [
            ['getJson', $base, []],
            ['patchJson', $base.'/assign', ['assignee_id' => $intruder->id]],
            ['patchJson', $base.'/status', ['status' => 'under_review']],
            ['patchJson', $base.'/priority', ['priority' => 'high']],
            ['patchJson', $base.'/journey', ['stage' => 'under_review']],
            ['patchJson', $base.'/archive', []],
            ['postJson', $base.'/reply', ['body' => 'hello from elsewhere']],
            ['postJson', $base.'/internal-note', ['body' => 'note from elsewhere']],
            ['postJson', $base.'/request-information', ['message' => 'tell me more']],
            ['postJson', $base.'/convert', []],
            ['postJson', $base.'/quote', []],
        ];

        foreach ($attempts as [$verb, $url, $body]) {
            $response = $this->asAgency($intruder, $other)->{$verb}($url, $body);
            $response->assertNotFound();
            $this->assertStringNotContainsString((string) $request->reference, (string) $response->getContent(), "$verb $url leaked the reference");
            $this->assertStringNotContainsString('visitor@example.test', (string) $response->getContent(), "$verb $url leaked the contact");
        }

        $fresh = $request->fresh();
        $this->assertNull($fresh->assigned_to);
        $this->assertNull($fresh->archived_at);
        $this->assertSame($request->status_id, $fresh->status_id);
        $this->assertSame(0, $fresh->comments()->count());
    }

    // ── S05 — the platform owner holds no workspace, so a tenant surface answers nothing ──────

    public function test_s05_the_platform_owner_reads_no_tenant_work_without_a_support_visit(): void
    {
        $this->submit()->assertCreated();
        $admin = $this->platformOwner();
        app(TenantContext::class)->forget();

        foreach (['/api/v1/app/requests', '/api/v1/campaigns', '/api/v1/leads', '/api/v1/app/clients'] as $url) {
            $response = $this->actingAs($admin, 'sanctum')->getJson($url);
            $this->assertContains($response->status(), [401, 403, 404, 409], "$url answered the platform owner with {$response->status()}");
            $this->assertStringNotContainsString('visitor@example.test', (string) $response->getContent());
        }
    }

    // ── S09 — no owner can be established: refuse closed, write nothing ───────────────────────

    public function test_s09_with_no_owner_the_intake_refuses_and_writes_nothing(): void
    {
        $this->agency->forceFill(['is_default_portal' => false, 'portal_enabled' => false])->save();
        Tenant::create(['name' => 'Bystander', 'slug' => 'bystander', 'status' => 'active']);

        $this->submit()->assertNotFound();

        $this->assertSame(0, ExternalRequest::query()->count());
        $this->assertSame(0, DB::table('client_notifications')->count());
    }

    // ── S10 — the owner is never taken from a header the edge does not vouch for ──────────────

    public function test_s10_a_forwarded_host_header_cannot_select_another_tenant(): void
    {
        $victim = Tenant::create([
            'name' => 'Victim', 'slug' => 'victim', 'status' => 'active',
            'portal_enabled' => true, 'portal_domain' => 'requests.victim.test',
        ]);

        $this->submit(null, 'visitor@example.test', ['X-Forwarded-Host' => 'requests.victim.test', 'Forwarded' => 'host=requests.victim.test'])->assertCreated();

        $this->assertSame(0, ExternalRequest::query()->where('tenant_id', $victim->id)->count());
    }

    public function test_s10_an_unknown_host_is_refused_rather_than_given_to_the_default_tenant(): void
    {
        $response = $this->submit('unknown-host.example');

        if ($response->status() === 201) {
            $this->assertSame((string) $this->agency->id, (string) ExternalRequest::query()->sole()->tenant_id);

            $this->markTestIncomplete(
                'PROVEN GAP (S10, contract §5 P0-5): a request arriving on a host that is neither the official site nor '
                .'any tenant\'s `portal_domain` is accepted and filed under the `is_default_portal` tenant. Target is a '
                .'safe refusal with a diagnostic log. Ledger #168, closed by PLATFORM-SERVICE-INBOX-001.'
            );
        }

        $response->assertNotFound();
        $this->assertSame(0, ExternalRequest::query()->count());
    }

    // ── S11 — pressing send twice is one request ──────────────────────────────────────────────

    public function test_s11_resubmitting_the_same_verified_form_does_not_open_a_second_request(): void
    {
        app(TenantContext::class)->forget();
        $payload = $this->withVerifiedContact([
            'type' => 'paid_campaign_launch', 'contact_name' => 'Twice', 'contact_email' => 'twice@example.test', 'company_name' => 'Twice Co',
        ]);

        $this->postJson('/api/v1/requests', $payload)->assertCreated();
        $this->postJson('/api/v1/requests', $payload)->assertStatus(422);

        $this->assertSame(1, ExternalRequest::query()->count());
        $this->assertSame(1, DB::table('client_notifications')->where('channel', 'email')->count());
    }

    // ── S07 (object access) — a tracking token is one request and nothing beside it ───────────

    public function test_a_tracking_token_reaches_its_own_request_only(): void
    {
        $tokenA = (string) $this->submit(null, 'first@example.test')->assertCreated()->json('data.tracking_token');
        $this->submit(null, 'second@example.test')->assertCreated();
        $second = ExternalRequest::query()->where('contact_email', 'second@example.test')->sole();

        $file = RequestFile::create([
            'tenant_id' => $second->tenant_id, 'request_id' => $second->id, 'disk' => 'local',
            'path' => 'requests/'.$second->id.'/brief.pdf', 'original_name' => 'brief.pdf',
            'mime' => 'application/pdf', 'size' => 10, 'is_client_visible' => true,
        ]);

        $this->getJson("/api/v1/requests/track/{$tokenA}/files/{$file->id}")->assertNotFound();

        $view = $this->getJson("/api/v1/requests/track/{$tokenA}")->assertOk();
        $this->assertStringNotContainsString((string) $second->reference, (string) $view->getContent());
        $this->assertStringNotContainsString('second@example.test', (string) $view->getContent());
        $this->assertArrayNotHasKey('tenant_id', (array) $view->json('data'));
    }

    // ── The platform's own queues: who is told, and what the console counts ───────────────────

    public function test_the_registrations_badge_counts_what_is_actually_waiting_for_review(): void
    {
        $admin = $this->platformOwner();
        $this->apply(['email' => 'waiting@example.test'])->assertStatus(202);
        RegistrationRequest::query()->sole()->forceFill(['state' => 'pending_approval'])->save();

        $attention = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/overview', ['Origin' => 'http://localhost:5173'])
            ->assertOk()->json('data.attention'))->keyBy('key');

        if ((int) $attention['registrations_pending']['count'] === 0) {
            $this->markTestIncomplete(
                'PROVEN DEFECT: `PlatformOverviewController::attention()` counts the states `pending_review` and '
                .'`info_requested`, neither of which `AccountState` has; an application in `pending_approval` is '
                .'counted as 0. Ledger #169, closed by PLATFORM-INBOX-ATTENTION-001.'
            );
        }

        $this->assertSame(1, (int) $attention['registrations_pending']['count']);
    }

    public function test_a_message_addressed_to_the_platform_tells_a_platform_administrator(): void
    {
        $admin = $this->platformOwner();

        $this->postJson('/api/v1/contact', [
            'name' => 'Visitor', 'email' => 'visitor@example.test',
            'subject' => 'A question', 'message' => 'I would like to know more about the plans.',
        ])->assertOk();
        $this->assertSame(1, ContactMessage::query()->count());

        // Either channel counts: an in-app notice, or a row in the mail ledger addressed to the administrator.
        $told = AppNotification::query()->withoutGlobalScopes()->where('user_id', $admin->id)->count()
            + DB::table('mail_deliveries')->where('user_id', $admin->id)->count();

        if ($told === 0) {
            // It is stored and it is not handed to a tenant — the two halves that already hold.
            $this->asAgency($this->agencyOwner, $this->agency)
                ->getJson('/api/v1/admin/legal/contact-messages')->assertForbidden();

            $this->markTestIncomplete(
                'PROVEN GAP (S22): a contact message is stored and readable only through the platform API, but no '
                .'platform administrator is notified and no console screen lists it. Ledger #169, closed by '
                .'PLATFORM-INBOX-ATTENTION-001.'
            );
        }

        $this->assertGreaterThanOrEqual(1, $told);
    }
}
