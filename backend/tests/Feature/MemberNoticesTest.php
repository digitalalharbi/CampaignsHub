<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Services\MemberNotices;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EMAIL-TRANSACTIONAL-SET-001 — the welcome and the role-change notice.
 *
 * Both are consequences of a change and never a condition of it: a message that cannot be sent leaves the grant and the role exactly as
 * they were, and a person who changed their own role is not told about it.
 */
final class MemberNoticesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $member;

    private Role $analyst;

    private Role $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $this->analyst = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);
        $this->analyst->givePermissionTo('campaigns.view', 'reports.view');
        $this->manager = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Manager', 'slug' => 'manager']);
        $this->manager->givePermissionTo('campaigns.view', 'reports.view', 'campaigns.manage');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($ownerRole);

        $this->member = User::create(['name' => 'Mona', 'email' => 'mona@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now(), 'locale' => 'ar']);
        $this->grantMembership($this->member, $this->tenant);
        $this->member->assignRole($this->analyst);
    }

    private function deliveries(string $kind)
    {
        return DB::table('mail_deliveries')->where('kind', $kind);
    }

    public function test_a_person_who_accepts_an_invitation_is_welcomed_once_with_the_workspace_and_the_role_they_joined_as(): void
    {
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/app/team/invitations', ['email' => 'new@a.test', 'role_slug' => 'manager'])->assertCreated();
        $token = explode('token=', (string) $res->json('data.dev_link'))[1];

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'New Member', 'password' => 'secret1234'])->assertCreated();

        $row = $this->deliveries('member_welcome')->where('recipient', 'new@a.test')->first();
        $this->assertNotNull($row, 'no welcome was recorded');
        $this->assertSame((string) $this->tenant->id, (string) $row->tenant_id);
        $this->assertSame('ar', $row->locale);
        // No mailer on this install: the outcome is the truth about that, never «sent».
        $this->assertSame('awaiting_credentials', $row->status);

        // The invitation is single-use, so a second welcome cannot be produced by replaying it.
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'New Member', 'password' => 'secret1234'])->assertStatus(422);
        $this->assertSame(1, $this->deliveries('member_welcome')->where('recipient', 'new@a.test')->count());
    }

    public function test_changing_somebody_elses_role_tells_them_once_and_a_same_role_or_their_own_change_says_nothing(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/settings/team/{$this->member->uuid}/role", ['role' => 'manager'])->assertOk();
        $this->assertSame(1, $this->deliveries('role_changed')->where('recipient', 'mona@a.test')->count());
        $this->assertSame(0, $this->deliveries('role_changed')->where('recipient', 'o@a.test')->count());

        // The same role again: nothing changed, nothing to say.
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/settings/team/{$this->member->uuid}/role", ['role' => 'manager'])->assertOk();
        $this->assertSame(1, $this->deliveries('role_changed')->where('recipient', 'mona@a.test')->count());

        // A second owner changing their OWN role is not mailed about it.
        $second = User::create(['name' => 'Second', 'email' => 's@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($second, $this->tenant);
        $second->assignRole(Role::where('slug', 'tenant-owner')->firstOrFail());
        $this->actingAs($second, 'sanctum')->putJson("/api/v1/settings/team/{$second->uuid}/role", ['role' => 'analyst'])->assertOk();
        $this->assertSame(0, $this->deliveries('role_changed')->where('recipient', 's@a.test')->count());
    }

    public function test_a_message_that_cannot_be_sent_never_undoes_the_role_change_and_is_never_recorded_as_sent(): void
    {
        // A transport that exists on paper and reaches nobody: the connection is refused.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.username' => 'u', 'mail.mailers.smtp.password' => 'p']);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/settings/team/{$this->member->uuid}/role", ['role' => 'manager'])->assertOk();

        $this->assertTrue($this->member->fresh()->rolesInCurrentTenant()->contains('slug', 'manager'), 'the role change was rolled back by a mail failure');
        $status = $this->deliveries('role_changed')->where('recipient', 'mona@a.test')->value('status');
        $this->assertContains($status, ['failed', 'awaiting_credentials'], 'a message that reached nobody was recorded as delivered');
    }

    public function test_the_role_notice_states_the_names_and_what_to_do_in_both_languages_and_describes_no_permissions(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.provider.test', 'mail.mailers.smtp.username' => 'campaignshub', 'providers.channels.email' => LaravelMailProvider::class]);
        Mail::fake();
        $notices = app(MemberNotices::class);

        $en = User::create(['name' => 'Eve', 'email' => 'eve@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now(), 'locale' => 'en']);
        $r1 = $notices->roleChanged($en, $this->tenant, 'Analyst', 'Manager', $this->owner);
        $r2 = $notices->roleChanged($this->member, $this->tenant, 'Analyst', 'Manager', $this->owner);
        $this->assertSame(['sent', 'sent'], [$r1, $r2]);

        $sent = Mail::sent(OperationalMail::class);
        $this->assertCount(2, $sent);
        $english = $sent->first(fn (OperationalMail $m) => $m->lang === 'en');
        $arabic = $sent->first(fn (OperationalMail $m) => $m->lang === 'ar');
        $this->assertSame('Your role in the workspace changed', $english->title);
        $this->assertStringContainsString('from «Analyst» to «Manager»', $english->detail);
        $this->assertStringContainsString('by Owner', $english->detail);
        $this->assertStringContainsString('contact a workspace administrator', $english->detail);
        $this->assertSame('تغيّر دورك في مساحة العمل', $arabic->title);
        $this->assertStringContainsString('من «Analyst» إلى «Manager»', $arabic->detail);
        $this->assertStringNotContainsString('permission', mb_strtolower($english->detail), 'a role notice names the role; it does not describe access');
        $this->assertSame('unchanged', $notices->roleChanged($en, $this->tenant, 'Manager', 'Manager'));
    }
}
