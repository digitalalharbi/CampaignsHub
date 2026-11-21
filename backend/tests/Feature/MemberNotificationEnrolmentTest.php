<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Services\EmailVerificationService;
use App\Domains\Notifications\Services\EnrolMemberNotifications;
use App\Domains\Platform\Support\SupportAccess;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * NOTIFICATION-ENROLMENT-001 — Owner, 2026-11-06: «كل مستخدم يحتاج إشعارات وملخصات على الإيميل، هل هي فعّالة أو لا؟»
 *
 * Production had ten verified users and ONE `notification_preferences` row, and every sender is driven from that table. These tests
 * pin the repair: a colleague is enrolled at every door they come through, with the documented defaults; an existing row is a recorded
 * choice and is never touched; a client-portal user, an influencer, a platform admin and an unproved address are never enrolled; and
 * the backfill command is a dry run until it is told otherwise.
 */
final class MemberNotificationEnrolmentTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-'.Str::random(5), 'status' => 'active', 'account_type' => 'agency']);
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Media buyer', 'slug' => 'media-buyer']);
        app(TenantContext::class)->forget();
    }

    /** @param array<string,mixed> $over */
    private function person(array $over = []): User
    {
        $user = User::create(['name' => 'Sam', 'email' => 'sam-'.Str::random(6).'@acme.test', 'password' => Hash::make('secret1234')]);
        $user->forceFill($over + ['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    /** The REAL grant door, with enrolment on — the test helper opts fixtures out. */
    private function grant(User $user, Portal $portal = Portal::Agency, string $role = 'media-buyer', array $clientIds = []): Membership
    {
        return app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: $portal, role: $role, clientScopeIds: $clientIds,
        ));
    }

    private function row(User $user): ?object
    {
        return DB::table('notification_preferences')->where('tenant_id', $this->tenant->id)->where('user_id', $user->id)->first();
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'secret1234']);
        $u->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $u;
    }

    // ── the defaults, exactly ───────────────────────────────────────────────────────────────────

    public function test_a_granted_colleague_gets_exactly_the_documented_defaults(): void
    {
        $sam = $this->person(['locale' => 'en', 'timezone' => 'Europe/London']);
        $this->grant($sam);

        $row = $this->row($sam);
        $this->assertNotNull($row, 'granting a colleague opens their notification row');
        $this->assertEquals(['in_app' => true, 'email' => true], json_decode((string) $row->channels, true));
        $this->assertEquals(['daily' => true, 'weekly' => true, 'monthly' => false, 'alerts' => true, 'recommendations' => true], json_decode((string) $row->digests, true));
        $this->assertSame('realtime', $row->frequency);
        $this->assertSame('en', $row->locale);
        $this->assertSame('Europe/London', $row->timezone);
        $this->assertSame(8, (int) $row->digest_hour);
        $this->assertNull($row->types, 'no per-type choice is frozen in: the catalogue defaults apply');
        $this->assertNull($row->project_ids);
        $this->assertNull($row->client_workspace_id);
        $this->assertFalse(json_decode((string) $row->quiet_hours, true)['enabled']);
        $this->assertSame([], json_decode((string) $row->categories, true), 'no category choice either');
    }

    public function test_an_unknown_locale_or_timezone_falls_back_to_arabic_and_riyadh(): void
    {
        $sam = $this->person(['locale' => 'fr', 'timezone' => 'Mars/Olympus']);
        $this->grant($sam);

        $this->assertSame('ar', $this->row($sam)->locale);
        $this->assertSame('Asia/Riyadh', $this->row($sam)->timezone);
    }

    // ── a row is a recorded choice ─────────────────────────────────────────────────────────────

    public function test_enrolment_is_idempotent_and_never_overwrites_a_row_that_exists(): void
    {
        $sam = $this->person();
        $this->grant($sam);
        $this->assertSame(1, DB::table('notification_preferences')->where('user_id', $sam->id)->count());

        // They switched everything off. That row IS their answer.
        DB::table('notification_preferences')->where('user_id', $sam->id)->update([
            'channels' => json_encode(['in_app' => false, 'email' => false]),
            'digests' => json_encode(['daily' => false, 'weekly' => false, 'monthly' => false, 'alerts' => false]),
            'digest_hour' => 21,
        ]);
        $before = (array) $this->row($sam);

        $this->grant($sam);                                                                      // granted again
        $this->assertSame('already_enrolled', app(EnrolMemberNotifications::class)->enrol($sam, (string) $this->tenant->id, 'test'));
        Artisan::call('notifications:enrol-members', ['--apply' => true]);                       // backfilled

        $this->assertSame(1, DB::table('notification_preferences')->where('user_id', $sam->id)->count());
        $this->assertEquals($before, (array) $this->row($sam), 'the row is byte-for-byte what the person left');
        $this->assertSame(1, AuditLog::query()->where('action', 'notifications.member_enrolled')->where('entity_id', (string) $sam->id)->count(), 'one enrolment, one audit row');
    }

    // ── who is eligible ────────────────────────────────────────────────────────────────────────

    public function test_a_client_portal_user_is_never_enrolled(): void
    {
        $client = $this->person();
        $this->grant($client, Portal::ClientPortal, 'client_viewer');

        $this->assertNull($this->row($client), 'a client reaches the product through the client portal and is not staff');
        $this->assertSame('not_staff_member', app(EnrolMemberNotifications::class)->enrol($client, (string) $this->tenant->id, 'test'));
        Artisan::call('notifications:enrol-members', ['--apply' => true]);
        $this->assertNull($this->row($client), 'the backfill leaves a client alone too');
    }

    public function test_an_influencer_a_platform_admin_an_unproved_address_and_a_disabled_account_are_not_enrolled(): void
    {
        $enrolment = app(EnrolMemberNotifications::class);
        $tenantId = (string) $this->tenant->id;

        $creator = $this->person();
        $this->grant($creator, Portal::Influencers, 'creator');
        $this->assertNull($this->row($creator));

        $unproved = $this->person(['email_verified_at' => null]);
        $this->grant($unproved);
        $this->assertNull($this->row($unproved), 'an address nobody confirmed is a stranger');
        $this->assertSame('email_not_verified', $enrolment->enrol($unproved, $tenantId, 'test'));

        $disabled = $this->person(['disabled_at' => now()]);
        $this->grant($disabled);
        $this->assertNull($this->row($disabled));
        $this->assertSame('disabled', $enrolment->enrol($disabled, $tenantId, 'test'));

        $admin = $this->admin();
        Membership::create(['user_id' => $admin->id, 'tenant_id' => $this->tenant->id, 'portal' => 'agency', 'role' => 'member', 'status' => 'active']);
        $this->assertSame('platform_admin', $enrolment->enrol($admin, $tenantId, 'test'));
        Artisan::call('notifications:enrol-members', ['--apply' => true]);
        $this->assertNull($this->row($admin), 'not even a stray saved membership puts the operator on a mailing list');

        $suspended = $this->person();
        $this->grant($suspended);
        DB::table('notification_preferences')->where('user_id', $suspended->id)->delete();
        DB::table('memberships')->where('user_id', $suspended->id)->update(['status' => 'suspended']);
        $this->assertSame('not_staff_member', $enrolment->enrol($suspended, $tenantId, 'test'));
    }

    // ── every door ─────────────────────────────────────────────────────────────────────────────

    public function test_accepting_an_invitation_enrols_the_new_colleague(): void
    {
        $owner = $this->person(['email' => 'owner@acme.test']);
        $role = Role::query()->where('tenant_id', $this->tenant->id)->where('slug', 'tenant-owner')->firstOrFail();
        $role->givePermissionTo(...Permission::pluck('key')->all());
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->grantMembership($owner, $this->tenant);
        $owner->assignRole($role);

        $res = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/app/team/invitations', ['email' => 'new@acme.test', 'role_slug' => 'media-buyer'])->assertCreated();
        $token = explode('token=', (string) $res->json('data.dev_link'))[1];
        app(TenantContext::class)->forget();

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'New One', 'password' => 'a-long-secret-1234'])->assertCreated();

        $new = User::query()->where('email', 'new@acme.test')->firstOrFail();
        $row = $this->row($new);
        $this->assertNotNull($row, 'accepting the invitation opens the notification row');
        $this->assertTrue(json_decode((string) $row->digests, true)['daily']);
        $this->assertNull($this->row($owner), 'a fixture grant by the test helper enrols nobody');
    }

    public function test_adding_an_existing_person_from_the_console_enrols_them(): void
    {
        $sam = $this->person();

        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/admin/tenants/'.$this->tenant->id.'/members', [
            'email' => $sam->email, 'role' => 'media-buyer', 'reason' => 'Requested by the agency owner',
        ])->assertOk();

        $this->assertNotNull($this->row($sam));
    }

    public function test_an_approved_registration_enrols_the_new_workspace_owner(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();
        $request = new RegistrationRequest;
        $request->forceFill([
            'email' => 'applicant@a.test', 'name' => 'Applicant', 'tenant_name' => 'Applicant Co', 'account_type' => 'brand',
            'requested_portal' => 'app', 'plan_code' => 'solo', 'billing_interval' => 'annual', 'phone' => self::aFreshSaudiNumber(),
            'password' => Hash::make('secret1234'), 'state' => 'approved_awaiting_payment',
            'email_verified_at' => now()->subDay(), 'mobile_verified_at' => now()->subDay(),
        ])->save();
        $plan = SubscriptionPlan::query()->where('code', 'solo')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision", [
            'outcome' => RegistrationDecision::WAIVER, 'confirm_waiver' => true,
            'confirm_amount' => number_format((float) $plan->priceFor('annual'), 2, '.', ''), 'reason' => 'Reviewed by the owner',
        ])->assertOk();

        $owner = User::query()->where('email', 'applicant@a.test')->firstOrFail();
        $tenantId = (string) Membership::query()->where('user_id', $owner->id)->value('tenant_id');
        $row = DB::table('notification_preferences')->where('tenant_id', $tenantId)->where('user_id', $owner->id)->first();
        $this->assertNotNull($row, 'the workspace owner a registration creates is reachable from day one');
        $this->assertSame('app', DB::table('memberships')->where('user_id', $owner->id)->value('portal'));
    }

    public function test_proving_the_address_after_the_grant_enrols_a_person_who_was_refused_at_the_grant(): void
    {
        $sam = $this->person(['email_verified_at' => null]);
        $this->grant($sam);
        $this->assertNull($this->row($sam));

        $token = 'verify-token-'.Str::random(20);
        DB::table('email_verifications')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $sam->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(EmailVerificationService::class)->verify($token);

        $this->assertNotNull($this->row($sam), 'the moment they prove the address they become reachable');
    }

    public function test_reactivating_a_member_from_the_console_opens_a_missing_row_and_keeps_an_existing_one(): void
    {
        $owner = $this->person();
        $this->grant($owner, Portal::Agency, 'tenant-owner');
        $owner->roles()->attach(Role::query()->where('tenant_id', $this->tenant->id)->where('slug', 'tenant-owner')->value('id'));
        $sam = $this->person();
        $this->grant($sam);
        $sam->roles()->attach(Role::query()->where('tenant_id', $this->tenant->id)->where('slug', 'media-buyer')->value('id'));
        DB::table('notification_preferences')->where('user_id', $sam->id)->delete();   // pre-dates enrolment
        DB::table('memberships')->where('user_id', $sam->id)->update(['status' => 'suspended']);

        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/admin/tenants/'.$this->tenant->id.'/members/'.$sam->uuid, ['action' => 'reactivate', 'reason' => 'Back from leave'])->assertOk();

        $this->assertNotNull($this->row($sam));
    }

    // ── impersonation ──────────────────────────────────────────────────────────────────────────

    public function test_a_support_visit_enrols_neither_the_operator_nor_the_person_they_look_at(): void
    {
        $sam = $this->person();
        $sam->forceFill(['email_verified_at' => now()])->save();
        Membership::create(['user_id' => $sam->id, 'tenant_id' => $this->tenant->id, 'portal' => 'agency', 'role' => 'member', 'status' => 'active']);
        $admin = $this->admin();

        app(SupportAccess::class)->start($admin, $this->tenant, 'Customer cannot find their report', '127.0.0.1');

        $this->assertSame(0, DB::table('notification_preferences')->count(), 'looking is not enrolling');
        $this->assertSame('platform_admin', app(EnrolMemberNotifications::class)->enrol($admin, (string) $this->tenant->id, 'support_visit'));
    }

    // ── the backfill command ───────────────────────────────────────────────────────────────────

    public function test_the_backfill_is_a_dry_run_that_writes_nothing_and_names_who_would_be_enrolled(): void
    {
        $sam = $this->person(['email' => 'sam.private@acme.test', 'locale' => 'en']);
        $this->grant($sam);
        DB::table('notification_preferences')->where('user_id', $sam->id)->delete();   // an account that pre-dates enrolment
        $client = $this->person();
        $this->grant($client, Portal::ClientPortal, 'client_viewer');
        $this->grant($this->person(['email_verified_at' => null]));
        $audits = AuditLog::query()->count();

        $code = Artisan::call('notifications:enrol-members');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('would_enrol=1', $out);
        $this->assertStringContainsString('s***@acme.test', $out, 'the address is masked');
        $this->assertStringNotContainsString('sam.private@acme.test', $out, 'a dry run never prints a full address');
        $this->assertStringContainsString('Acme', $out);
        $this->assertStringContainsString('media-buyer', $out);
        $this->assertStringContainsString('daily+weekly+alerts+recommendations', $out);
        $this->assertStringContainsString('not eligible (email_not_verified): 1', $out);
        $this->assertSame(0, DB::table('notification_preferences')->count(), 'DRY RUN wrote a row');
        $this->assertSame($audits, AuditLog::query()->count(), 'DRY RUN wrote an audit row');
    }

    public function test_apply_enrols_each_person_once_with_an_audit_row_and_a_second_run_changes_nothing(): void
    {
        $a = $this->person();
        $b = $this->person();
        foreach ([$a, $b] as $u) {
            $this->grant($u);
            DB::table('notification_preferences')->where('user_id', $u->id)->delete();
        }
        AuditLog::query()->where('action', 'notifications.member_enrolled')->delete();

        Artisan::call('notifications:enrol-members', ['--apply' => true]);
        $first = Artisan::output();
        $this->assertStringContainsString('enrolled=2 already_enrolled=0', $first);
        $this->assertSame(2, DB::table('notification_preferences')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'notifications.member_enrolled')->where('after->via', 'backfill_command')->count());

        Artisan::call('notifications:enrol-members', ['--apply' => true]);
        $this->assertStringContainsString('enrolled=0 already_enrolled=2', Artisan::output());
        $this->assertSame(2, DB::table('notification_preferences')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'notifications.member_enrolled')->count(), 'no second audit row');
    }

    // ── the admin readout ──────────────────────────────────────────────────────────────────────

    public function test_the_platform_email_page_reads_how_far_the_notification_system_reaches(): void
    {
        $enrolled = $this->person();
        $this->grant($enrolled);
        $optedOut = $this->person();
        $this->grant($optedOut);
        DB::table('notification_preferences')->where('user_id', $optedOut->id)->update(['digests' => json_encode(['daily' => false, 'weekly' => false, 'monthly' => false])]);
        $missing = $this->person();
        $this->grant($missing);
        DB::table('notification_preferences')->where('user_id', $missing->id)->delete();
        $this->grant($this->person(), Portal::ClientPortal, 'client_viewer');   // not a member of the staff population
        DB::table('digest_sends')->insert([
            ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $enrolled->id, 'kind' => 'daily', 'period_key' => '2026-11-05', 'status' => 'sent', 'sent_at' => '2026-11-06 05:00:00', 'created_at' => now(), 'updated_at' => now()],
            // An on-demand send must never read as the scheduler having run.
            ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $enrolled->id, 'kind' => 'now_weekly', 'period_key' => '2026-W45#abcd1234', 'status' => 'sent', 'sent_at' => '2026-11-06 06:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $reach = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/email/deliveries')->assertOk()->json('data.reach');

        $this->assertSame(3, $reach['members']);
        $this->assertSame(3, $reach['verified']);
        $this->assertSame(2, $reach['enrolled']);
        $this->assertSame(1, $reach['digest_on']);
        $this->assertStringStartsWith('2026-11-06T05:00:00', $reach['last_digest']['daily']);
        $this->assertNull($reach['last_digest']['weekly'], 'an on-demand send is not the scheduled weekly');
        $this->assertNull($reach['last_digest']['monthly']);
    }
}
