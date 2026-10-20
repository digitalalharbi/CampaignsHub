<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\ReviewAccess;
use App\Domains\Tenancy\Support\ReviewAccountProvisioner;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * SALLA-REVIEW-001 — the restricted workspace a Salla app-store reviewer is given.
 *
 * A reviewer sees real dashboards and reports on demo data, can connect a Salla store, and can do nothing that
 * exports, mints a credential, invites, configures webhooks, reaches the admin panel or touches billing; they
 * are rate limited and audited; the access ends on its date or when revoked, with a message that says so; and
 * the whole workspace is invisible to every other tenant and sees none of theirs.
 */
final class SallaReviewAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private const PASSWORD = 'review-pass-1234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function provision(?Carbon $expires = null): Tenant
    {
        return app(ReviewAccountProvisioner::class)->provision(self::PASSWORD, $expires)['tenant'];
    }

    private function reviewer(): User
    {
        return User::query()->where('email', ReviewAccountProvisioner::EMAIL)->firstOrFail();
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders(self::SPA);
    }

    private function project(Tenant $tenant): Project
    {
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $p = Project::query()->firstOrFail();
        app(TenantContext::class)->forget();

        return $p;
    }

    public function test_provisioning_builds_an_isolated_demo_workspace_without_ever_printing_or_storing_the_password(): void
    {
        $tenant = $this->provision();
        $user = $this->reviewer();

        $this->assertSame('Salla Review', $tenant->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($user->getAttributes()));
        $this->assertNotNull($user->email_verified_at);

        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $project = Project::query()->firstOrFail();
        $this->assertSame(17, UnifiedCampaign::query()->count(), '~16 campaigns');
        $this->assertSame(['google', 'linkedin', 'meta', 'snapchat', 'tiktok', 'x'], DailyMetric::query()->distinct()->orderBy('provider')->pluck('provider')->all(), 'six platforms');
        $this->assertSame(30, DailyMetric::query()->where('metric_key', 'spend')->distinct()->count('metric_date'), 'thirty days');
        $this->assertSame(0, DailyMetric::query()->where('is_demo', false)->count(), 'everything is flagged demo');
        $this->assertGreaterThan(0, SpendLimit::query()->where('project_id', $project->id)->count(), 'budgets');
        $this->assertSame('completed', Report::query()->where('project_id', $project->id)->value('status'), 'a weekly report');
        app(TenantContext::class)->forget();
    }

    public function test_provisioning_twice_changes_nothing_but_the_password_and_the_window(): void
    {
        $first = $this->provision(Carbon::now()->addDays(5));
        $second = app(ReviewAccountProvisioner::class)->provision('another-pass-1234567')['tenant'];

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Tenant::query()->where('slug', 'salla-review')->count());
        $this->assertSame(1, User::query()->where('email', ReviewAccountProvisioner::EMAIL)->count());
        $this->assertTrue(Hash::check('another-pass-1234567', $this->reviewer()->password));
        app(TenantContext::class)->setTenantId((string) $first->id);
        $this->assertSame(17, UnifiedCampaign::query()->count(), 'no duplicate demo campaigns');
        app(TenantContext::class)->forget();
        // The expiry set before is kept when none is passed.
        $this->assertEqualsWithDelta(Carbon::now()->addDays(5)->timestamp, Carbon::parse(ReviewAccess::settingsOf($second->fresh())['expires_at'])->timestamp, 5);
    }

    public function test_a_short_password_is_refused_and_the_command_needs_the_environment_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(ReviewAccountProvisioner::class)->provision('short');
        } finally {
            config(['app.salla_review_password' => null]);
            $this->artisan('salla:review')->expectsOutputToContain('SALLA_REVIEW_PASSWORD is not set')->assertFailed();
            $this->assertSame(0, Tenant::query()->where('slug', 'salla-review')->count());
        }
    }

    public function test_the_command_never_prints_the_password(): void
    {
        config(['app.salla_review_password' => self::PASSWORD]);

        $this->artisan('salla:review')
            ->expectsOutputToContain(ReviewAccountProvisioner::EMAIL)
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();
        $this->assertTrue(Hash::check(self::PASSWORD, $this->reviewer()->password));
    }

    public function test_the_reviewer_signs_in_while_the_access_is_live_and_is_told_when_it_has_ended(): void
    {
        $tenant = $this->provision();

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => ReviewAccountProvisioner::EMAIL, 'password' => self::PASSWORD])->assertOk();
        $this->assertAuthenticated('web');
        $this->app['auth']->guard('web')->logout();

        $this->expireReview($tenant);
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => ReviewAccountProvisioner::EMAIL, 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('message', ReviewAccess::ENDED_MESSAGE);
        $this->assertGuest('web');
    }

    private function expireReview(Tenant $tenant, bool $revoke = false): void
    {
        $settings = (array) $tenant->settings;
        $settings['review'] = $revoke ? ['active' => false, 'expires_at' => now()->addDays(5)->toIso8601String()] : ['active' => true, 'expires_at' => now()->subMinute()->toIso8601String()];
        $tenant->forceFill(['settings' => $settings])->save();
    }

    public function test_an_open_session_is_ended_on_its_next_request_when_the_access_expires_or_is_revoked(): void
    {
        $tenant = $this->provision();
        $project = $this->project($tenant);

        $this->as($this->reviewer())->getJson("/api/v1/projects/{$project->id}")->assertOk();

        $this->expireReview($tenant);
        $this->as($this->reviewer())->getJson("/api/v1/projects/{$project->id}")->assertStatus(403)->assertJsonPath('code', 'review_ended');

        $this->expireReview($tenant->fresh(), revoke: true);
        $this->as($this->reviewer())->getJson("/api/v1/projects/{$project->id}")->assertStatus(403)->assertJsonPath('code', 'review_ended');
    }

    public function test_the_reviewer_cannot_export_mint_tokens_invite_configure_webhooks_share_or_reach_billing_and_admin(): void
    {
        $tenant = $this->provision();
        $project = $this->project($tenant);
        $report = Report::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();
        $as = $this->as($this->reviewer());

        $as->postJson("/api/v1/projects/{$project->id}/reports/{$report->id}/export", ['format' => 'csv'])->assertStatus(403)->assertJsonPath('code', 'review_restricted');
        $as->postJson("/api/v1/projects/{$project->id}/reports/{$report->id}/shares", [])->assertStatus(403)->assertJsonPath('code', 'review_restricted');
        $as->postJson('/api/v1/app/team/invitations', ['email' => 'x@y.test', 'role_slug' => 'viewer'])->assertStatus(403)->assertJsonPath('code', 'review_restricted');
        $as->getJson('/api/v1/admin/tenants')->assertStatus(403);
        $as->getJson('/api/v1/billing/invoices')->assertStatus(403);
        $as->getJson('/api/v1/subscriptions/current')->assertStatus(403);

        // API tokens: refused at issuance, where there is no tenant context to restrict by.
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/tokens', ['email' => ReviewAccountProvisioner::EMAIL, 'password' => self::PASSWORD])->assertStatus(403);
        $this->assertSame(0, $this->reviewer()->tokens()->count());
    }

    public function test_the_reviewer_can_still_read_dashboards_and_reports_and_connect_a_store(): void
    {
        $tenant = $this->provision();
        $project = $this->project($tenant);
        $as = $this->as($this->reviewer());

        $as->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $as->getJson("/api/v1/projects/{$project->id}/reports")->assertOk();
        $as->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.account.review.label', 'Salla Review');
        // Starting a Salla connection is allowed (the response may refuse for a missing app key, never for review).
        $this->assertNotSame('review_restricted', $as->postJson('/api/v1/integrations/commerce/salla/oauth/start')->json('code'));
    }

    public function test_requests_are_limited_to_sixty_a_minute_per_user(): void
    {
        $tenant = $this->provision();
        $project = $this->project($tenant);
        $user = $this->reviewer();
        RateLimiter::clear('review-rate:'.$user->id);

        for ($i = 0; $i < ReviewAccess::RATE_PER_MINUTE; $i++) {
            $this->as($user)->getJson("/api/v1/projects/{$project->id}")->assertOk();
        }
        $this->as($user)->getJson("/api/v1/projects/{$project->id}")->assertStatus(429)->assertJsonPath('code', 'review_rate_limited');
    }

    public function test_each_session_leaves_an_audit_trail_with_address_browser_and_page(): void
    {
        $tenant = $this->provision();
        $project = $this->project($tenant);

        $this->as($this->reviewer())->withHeader('User-Agent', 'ReviewerBrowser/1.0')->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->as($this->reviewer())->withHeader('User-Agent', 'ReviewerBrowser/1.0')->getJson("/api/v1/projects/{$project->id}")->assertOk();

        $rows = AuditLog::withoutGlobalScopes()->where('action', 'review.request')->get();
        $this->assertCount(1, $rows, 'once a minute per route, not one row per poll');
        $after = (array) $rows->first()->after;
        $this->assertSame('GET', $after['method']);
        $this->assertSame('ReviewerBrowser/1.0', $after['user_agent']);
        $this->assertArrayHasKey('ip', $after);
        $this->assertStringContainsString('projects/', (string) $after['path']);
    }

    public function test_a_refused_attempt_is_audited_too(): void
    {
        $tenant = $this->provision();
        $project = $this->project($tenant);
        $report = Report::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();

        $this->as($this->reviewer())->postJson("/api/v1/projects/{$project->id}/reports/{$report->id}/export", [])->assertStatus(403);

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'review.refused')->count());
    }

    public function test_the_platform_owner_extends_and_revokes_in_one_call_and_only_a_review_workspace(): void
    {
        $tenant = $this->provision(Carbon::now()->addDays(2));
        $admin = User::create(['name' => 'Platform', 'email' => 'platform@t.test', 'password' => 'secret1234']);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $customer = Tenant::create(['name' => 'Customer', 'slug' => 'customer', 'status' => 'active']);

        $this->as($admin)->postJson("/api/v1/admin/review-accounts/{$tenant->id}/extend", ['days' => 7])->assertOk()->assertJsonPath('data.live', true);
        $this->assertEqualsWithDelta(Carbon::now()->addDays(9)->timestamp, Carbon::parse(ReviewAccess::settingsOf($tenant->fresh())['expires_at'])->timestamp, 5, 'extended from the current expiry');

        $this->as($admin)->postJson("/api/v1/admin/review-accounts/{$tenant->id}/revoke")->assertOk()->assertJsonPath('data.live', false);
        $project = $this->project($tenant);
        $this->as($this->reviewer())->getJson("/api/v1/projects/{$project->id}")->assertStatus(403)->assertJsonPath('code', 'review_ended');

        // A customer's tenant cannot be pointed at.
        $this->as($admin)->postJson("/api/v1/admin/review-accounts/{$customer->id}/revoke")->assertNotFound();
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertGreaterThanOrEqual(2, AuditLog::withoutGlobalScopes()->whereIn('action', ['review.extended', 'review.revoked'])->count());

        // A tenant user cannot use the admin endpoints at all.
        $this->as($this->reviewer())->postJson("/api/v1/admin/review-accounts/{$tenant->id}/extend", ['days' => 7])->assertStatus(403);
    }

    public function test_the_command_extends_and_revokes(): void
    {
        $tenant = $this->provision(Carbon::now()->addDays(1));

        $this->artisan('salla:review', ['--extend' => 7])->assertSuccessful();
        $this->assertTrue(ReviewAccess::isLive($tenant->fresh()));
        $this->assertEqualsWithDelta(Carbon::now()->addDays(8)->timestamp, Carbon::parse(ReviewAccess::settingsOf($tenant->fresh())['expires_at'])->timestamp, 5);

        $this->artisan('salla:review', ['--revoke' => true])->assertSuccessful();
        $this->assertFalse(ReviewAccess::isLive($tenant->fresh()));
    }

    public function test_the_review_workspace_and_everyone_elses_never_see_each_other(): void
    {
        $review = $this->provision();
        $reviewProject = $this->project($review);

        $other = Tenant::create(['name' => 'Merchant', 'slug' => 'merchant', 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $other->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $theirs = Project::create(['client_workspace_id' => $client->id, 'name' => 'Their project', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $as = $this->as($this->reviewer());
        $as->getJson("/api/v1/projects/{$theirs->id}")->assertNotFound();
        $names = collect($as->getJson('/api/v1/projects')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertNotContains('Their project', $names);
        $this->assertContains('Salla Review — Demo Store', $names);

        // …and the merchant sees nothing of the review workspace.
        $merchant = User::create(['name' => 'M', 'email' => 'm@merchant.test', 'password' => 'secret1234']);
        app(TenantContext::class)->setTenantId((string) $other->id);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $merchant->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $merchant, tenant: $other, portal: Portal::App, role: 'owner'));
        app(TenantContext::class)->forget();
        $this->as($merchant)->getJson("/api/v1/projects/{$reviewProject->id}")->assertNotFound();
    }
}
