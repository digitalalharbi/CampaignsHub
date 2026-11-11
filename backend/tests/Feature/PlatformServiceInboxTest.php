<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Services\RequestOwnerResolver;
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
use Tests\Concerns\VerifiesContact;
use Tests\TestCase;

/**
 * PLATFORM-SERVICE-INBOX-001 — a service ordered from the official site belongs to the platform owner.
 *
 * The ownership probes (S01, S03, S09, S10) live in `SaasStructureRoutingContractTest`. This file is the other
 * half: what the platform owner can do with such a request, who is told about it, and the two walls — no
 * subscriber reads a platform request, and the platform inbox never lists a subscriber's.
 */
final class PlatformServiceInboxTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesContact;

    private Tenant $agency;

    private User $agencyOwner;

    private User $admin;

    private array $spa = ['Origin' => 'http://localhost:5173'];

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
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->agencyOwner = User::create(['name' => 'Agency Owner', 'email' => 'owner@subscriber.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->agencyOwner, $this->agency, Portal::Agency);
        $this->agencyOwner->assignRole($role);

        $this->admin = $this->platformAdmin('platform@campaignshub.test', 'Platform Owner');
    }

    private function platformAdmin(string $email, string $name): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user->refresh();
    }

    private function order(string $host, string $email): ExternalRequest
    {
        app(TenantContext::class)->forget();
        $this->postJson('http://'.$host.'/api/v1/requests', $this->withVerifiedContact([
            'type' => 'paid_campaign_launch', 'contact_name' => 'Visitor', 'contact_email' => $email, 'company_name' => 'Visitor Co',
        ]))->assertCreated();

        return ExternalRequest::query()->whereRaw('lower(contact_email) = ?', [$email])->sole();
    }

    private function asAdmin(): self
    {
        app(TenantContext::class)->forget();

        return $this->actingAs($this->admin, 'sanctum');
    }

    private function asAgency(): self
    {
        app(TenantContext::class)->setTenantId($this->agency->id);

        return $this->actingAs($this->agencyOwner, 'sanctum');
    }

    private function inbox(string $query = ''): TestResponse
    {
        return $this->asAdmin()->getJson('/api/v1/admin/service-requests'.$query, $this->spa);
    }

    public function test_the_platform_hosts_are_the_published_site_application_and_api(): void
    {
        $hosts = RequestOwnerResolver::platformHosts();

        foreach (['campaignshub.io', 'www.campaignshub.io', 'app.campaignshub.io', 'api.campaignshub.io'] as $host) {
            $this->assertContains($host, $hosts);
        }

        config(['requests.platform_hosts' => ['Services.Example.test ']]);
        $this->assertSame(['services.example.test'], RequestOwnerResolver::platformHosts());
    }

    public function test_the_platform_inbox_lists_the_platforms_requests_and_never_a_subscribers(): void
    {
        $mine = $this->order('campaignshub.io', 'platform-visitor@example.test');
        $theirs = $this->order('localhost', 'agency-visitor@example.test');
        $this->assertSame(ExternalRequest::OWNER_TENANT, $theirs->owner_scope);

        $list = $this->inbox()->assertOk();
        $list->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.requests.0.reference', $mine->reference)
            ->assertJsonPath('data.requests.0.status.key', 'new')
            ->assertJsonPath('data.requests.0.assignee', null)
            ->assertJsonPath('data.counts.open', 1)
            ->assertJsonPath('data.counts.unassigned', 1);
        $this->assertStringNotContainsString((string) $theirs->reference, (string) $list->getContent());

        // The subscriber's request cannot be opened or changed from the console either.
        $this->asAdmin()->getJson('/api/v1/admin/service-requests/'.$theirs->id, $this->spa)->assertNotFound();
        $this->asAdmin()->patchJson('/api/v1/admin/service-requests/'.$theirs->id, ['status' => 'under_review'], $this->spa)->assertNotFound();
        $this->assertSame('new', $theirs->fresh()->status->key);

        // …and the subscriber still has it, exactly as before.
        $this->asAgency()->getJson('/api/v1/app/requests')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_no_subscriber_session_reaches_the_platform_inbox_or_a_platform_request(): void
    {
        $mine = $this->order('campaignshub.io', 'platform-visitor@example.test');

        // Nobody signed in at all.
        $this->getJson('/api/v1/admin/service-requests')->assertUnauthorized();
        $this->getJson('/api/v1/admin/service-requests/'.$mine->id)->assertUnauthorized();

        $this->asAgency()->getJson('/api/v1/admin/service-requests')->assertForbidden();
        $this->asAgency()->getJson('/api/v1/admin/service-requests/'.$mine->id)->assertForbidden();
        $this->asAgency()->patchJson('/api/v1/admin/service-requests/'.$mine->id, ['status' => 'under_review'])->assertForbidden();

        foreach (['getJson' => '', 'patchJson' => '/status'] as $verb => $suffix) {
            $response = $this->asAgency()->{$verb}('/api/v1/app/requests/'.$mine->id.$suffix, ['status' => 'under_review']);
            $response->assertNotFound();
            $this->assertStringNotContainsString((string) $mine->reference, (string) $response->getContent());
        }

        $this->assertSame('new', $mine->fresh()->status->key);
    }

    public function test_the_platforms_administrators_are_told_through_the_mail_ledger_and_no_subscriber_is(): void
    {
        $second = $this->platformAdmin('second@campaignshub.test', 'Second Admin');
        $disabled = $this->platformAdmin('gone@campaignshub.test', 'Gone Admin');
        $disabled->forceFill(['disabled_at' => now()])->save();

        $mine = $this->order('campaignshub.io', 'platform-visitor@example.test');

        $rows = DB::table('mail_deliveries')->where('kind', 'client_request')->get();
        $this->assertEqualsCanonicalizing(
            [$this->admin->id, $second->id],
            $rows->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $this->assertSame([null], $rows->pluck('tenant_id')->unique()->values()->all());
        $this->assertSame(0, DB::table('mail_deliveries')->where('recipient', $this->agencyOwner->email)->count());
        $this->assertSame(0, DB::table('mail_deliveries')->where('recipient', 'platform-visitor@example.test')->count());

        // A tenant request keeps its own path and tells no platform administrator.
        $this->order('localhost', 'agency-visitor@example.test');
        $this->assertSame(2, DB::table('mail_deliveries')->where('kind', 'client_request')->whereNull('tenant_id')->count());
        $this->assertSame(0, DB::table('client_notifications')->where('request_id', $mine->id)->count());
    }

    public function test_an_administrator_takes_a_request_and_moves_it_and_both_are_on_the_record(): void
    {
        $mine = $this->order('campaignshub.io', 'platform-visitor@example.test');
        $url = '/api/v1/admin/service-requests/'.$mine->id;

        $this->asAdmin()->patchJson($url, ['assigned_to' => $this->admin->id], $this->spa)->assertOk()
            ->assertJsonPath('data.request.assignee.id', $this->admin->id);
        $this->asAdmin()->patchJson($url, ['status' => 'under_review', 'reason' => 'Picked up'], $this->spa)->assertOk()
            ->assertJsonPath('data.request.status.key', 'under_review');

        $detail = $this->asAdmin()->getJson($url, $this->spa)->assertOk();
        $detail->assertJsonPath('data.request.contact.email', 'platform-visitor@example.test');
        $this->assertContains('status_changed', array_column($detail->json('data.request.activity'), 'type'));
        $this->assertContains('assigned', array_column($detail->json('data.request.activity'), 'type'));

        $audit = AuditLog::query()->withoutGlobalScopes()->where('entity_id', (string) $mine->id)->orderBy('created_at')->get();
        $this->assertEqualsCanonicalizing(
            ['platform.service_request.assigned', 'platform.service_request.status_changed'],
            $audit->pluck('action')->all(),
        );
        $this->assertSame([null], $audit->pluck('tenant_id')->unique()->values()->all());
        $this->assertSame([(int) $this->admin->id], $audit->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        $this->inbox('?unassigned=1')->assertOk()->assertJsonPath('data.total', 0);
        $this->inbox('?status=under_review')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_a_platform_request_cannot_be_given_to_a_subscribers_user_or_moved_illegally(): void
    {
        $mine = $this->order('campaignshub.io', 'platform-visitor@example.test');
        $url = '/api/v1/admin/service-requests/'.$mine->id;

        $this->asAdmin()->patchJson($url, ['assigned_to' => $this->agencyOwner->id], $this->spa)->assertStatus(422);
        $this->asAdmin()->patchJson($url, ['status' => 'completed'], $this->spa)->assertStatus(422);

        $fresh = $mine->fresh();
        $this->assertNull($fresh->assigned_to);
        $this->assertSame('new', $fresh->status->key);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('entity_id', (string) $mine->id)->count());
    }

    public function test_the_visitor_still_tracks_a_platform_request_and_it_opens_no_portal(): void
    {
        app(TenantContext::class)->forget();
        $created = $this->postJson('http://campaignshub.io/api/v1/requests', $this->withVerifiedContact([
            'type' => 'consulting', 'contact_name' => 'Visitor', 'contact_email' => 'tracker@example.test', 'company_name' => 'Visitor Co',
        ]))->assertCreated();

        $this->getJson('/api/v1/requests/track/'.$created->json('data.tracking_token'))->assertOk()
            ->assertJsonPath('data.reference', $created->json('data.reference'))
            ->assertJsonPath('data.status', 'new');

        // The address that ordered a platform service is not a client of any workspace.
        $this->assertSame(0, ExternalRequest::query()->tenantOwned()->whereRaw('lower(contact_email) = ?', ['tracker@example.test'])->count());
        $this->assertSame(1, ExternalRequest::query()->platformOwned()->count());
    }

    public function test_the_console_home_counts_what_nobody_has_picked_up(): void
    {
        $mine = $this->order('campaignshub.io', 'platform-visitor@example.test');
        $this->order('localhost', 'agency-visitor@example.test');

        $attention = fn (): array => collect($this->asAdmin()->getJson('/api/v1/admin/overview', $this->spa)->assertOk()->json('data.attention'))
            ->keyBy('key')->all();

        $this->assertSame(1, (int) $attention()['service_requests_new']['count']);
        $this->assertSame('/admin/requests', $attention()['service_requests_new']['to']);
        // The subscribers' workload figure counts their requests and not the platform's.
        $this->assertSame(1, (int) $this->asAdmin()->getJson('/api/v1/admin/overview', $this->spa)->json('data.workload.open_requests'));

        $this->asAdmin()->patchJson('/api/v1/admin/service-requests/'.$mine->id, ['assigned_to' => $this->admin->id], $this->spa)->assertOk();
        $this->assertSame(0, (int) $attention()['service_requests_new']['count']);
    }

    public function test_the_migration_is_additive_and_reads_every_earlier_request_as_its_tenants(): void
    {
        $this->assertTrue(Schema::hasColumn('external_requests', 'owner_scope'));

        // A row written the way every row was written before this change: a tenant and no stated owner.
        $earlier = $this->order('localhost', 'earlier@example.test');
        DB::table('external_requests')->where('id', $earlier->id)->update(['owner_scope' => DB::raw('DEFAULT')]);

        $row = $earlier->fresh();
        $this->assertSame(ExternalRequest::OWNER_TENANT, $row->owner_scope);
        $this->assertSame((string) $this->agency->id, (string) $row->tenant_id);
        $this->inbox()->assertOk()->assertJsonPath('data.total', 0);
    }
}
