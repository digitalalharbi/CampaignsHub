<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\AccessSections;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ACCESS-SECTIONS-001 — the third layer (sections) over the role and the client ceiling, edited after the invitation,
 * enforced on every route of a section, effective on the next request, audited. Fixture names only.
 */
final class AccessSectionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    /** Every key a section's representative endpoint checks, so «held by role» is true for the member. */
    private const EVERYTHING_BUT_ADMIN = [
        'projects.view', 'projects.view.all', 'notifications.view', 'clients.view', 'clients.view_all',
        'campaigns.view', 'budget.view', 'funding.view', 'billing.view', 'reports.view', 'integrations.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Fixture Agency', 'slug' => 'fx-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->tenant->id);
        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Tenant Owner', 'slug' => 'tenant-owner', 'is_system' => true]);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = $this->member('owner@fixture.test', $owner);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Client One', 'slug' => 'c1-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Project One', 'status' => 'active']);
    }

    private function member(string $email, Role $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant, Portal::Agency, 'member');
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /** @param list<string> $keys */
    private function role(string $slug, array $keys): Role
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $slug, 'slug' => $slug, 'is_system' => false]);
        $role->givePermissionTo(...$keys);

        return $role;
    }

    /** One representative endpoint per section — a GET the section's own page makes. */
    private function endpoint(string $section): string
    {
        $p = $this->project->id;

        return match ($section) {
            'campaigns' => "/api/v1/projects/{$p}/campaigns",
            'budgets' => '/api/v1/funding',
            'spend_limits' => "/api/v1/projects/{$p}/spend-limits",
            'invoices' => '/api/v1/billing/invoices',
            'reports' => "/api/v1/projects/{$p}/reports",
            'integrations' => '/api/v1/integrations',
            'team' => '/api/v1/settings/team',
        };
    }

    private function switchSections(User $target, array $sections): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/settings/access/members/{$target->uuid}", ['sections' => $sections])->assertOk();
    }

    public function test_every_section_route_pattern_names_a_real_route(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->values()->all();
        foreach (AccessSections::all() as $section) {
            foreach ($section['routes'] as $pattern) {
                $this->assertNotEmpty(array_filter($names, fn ($n) => Str::is($pattern, $n)), "{$section['key']}: {$pattern} matches no route");
            }
            $this->assertSame($section['key'], AccessSections::forRoute(app('router')->getRoutes()->match(Request::create($this->endpoint($section['key'])))->getName()));
        }
        $this->assertCount(7, AccessSections::all());
    }

    /** Deny-by-default: a role holding none of a section's keys is refused by the API itself, for all seven. */
    public function test_a_member_whose_role_lacks_a_section_is_refused_by_the_api_for_each_of_the_seven(): void
    {
        $bare = $this->member('bare@fixture.test', $this->role('bare', ['projects.view', 'projects.view.all', 'notifications.view', 'clients.view', 'clients.view_all']));
        Sanctum::actingAs($bare);

        foreach (AccessSections::keys() as $section) {
            $this->getJson($this->endpoint($section))->assertForbidden();
        }

        Sanctum::actingAs($bare);
        $me = $this->getJson('/api/v1/me/access')->assertOk();
        $this->assertSame([], $me->json('data.sections'));
    }

    /** A section switched off is refused on the very next request even though the role's keys would pass — and reopens just as fast. */
    public function test_a_switched_off_section_is_refused_on_the_next_request_for_each_of_the_seven_and_reopens(): void
    {
        $keys = [...self::EVERYTHING_BUT_ADMIN, 'settings.manage'];
        $member = $this->member('full@fixture.test', $this->role('full', $keys));

        foreach (AccessSections::keys() as $section) {
            Sanctum::actingAs($member);
            $this->assertNotSame(403, $this->getJson($this->endpoint($section))->status(), "{$section} should open before the switch");

            $this->switchSections($member, [$section => false]);
            Sanctum::actingAs($member);
            $this->getJson($this->endpoint($section))->assertForbidden();
            $this->assertNotContains($section, $this->getJson('/api/v1/me/access')->json('data.sections'));

            $this->switchSections($member, [$section => true]);
            Sanctum::actingAs($member);
            $this->assertNotSame(403, $this->getJson($this->endpoint($section))->status(), "{$section} should reopen");
            $this->assertContains($section, $this->getJson('/api/v1/me/access')->json('data.sections'));
        }
    }

    public function test_switching_a_section_on_grants_its_reading_key_and_takes_effect_at_once(): void
    {
        $member = $this->member('reader@fixture.test', $this->role('reader', ['projects.view', 'projects.view.all', 'notifications.view', 'clients.view', 'clients.view_all']));
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/billing/invoices')->assertForbidden();

        $this->switchSections($member, ['invoices' => true]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/billing/invoices')->assertOk();
        $this->assertContains('invoices', $this->getJson('/api/v1/me/access')->json('data.sections'));
    }

    public function test_the_edit_changes_role_sections_and_clients_together_and_writes_one_audit_row(): void
    {
        $viewer = $this->role('viewer-fx', ['projects.view', 'projects.view.all', 'clients.view', 'campaigns.view', 'reports.view']);
        $finance = $this->role('finance-fx', ['projects.view', 'projects.view.all', 'clients.view', 'budget.view', 'billing.view']);
        $member = $this->member('mover@fixture.test', $viewer);
        $client = ClientWorkspace::query()->firstOrFail();

        Sanctum::actingAs($this->owner);
        $res = $this->putJson("/api/v1/settings/access/members/{$member->uuid}", [
            'category' => 'finance-fx',
            'sections' => ['invoices' => false],
            'client_ids' => [(string) $client->id],
            'reason' => 'moved to finance',
        ])->assertOk();
        $this->assertSame('finance-fx', $res->json('data.category'));
        $this->assertFalse($res->json('data.sections.invoices'));
        $this->assertTrue($res->json('data.sections.budgets'));

        $this->assertTrue(DB::table('role_user')->where('user_id', $member->id)->where('role_id', $finance->id)->exists());
        $this->assertFalse(DB::table('role_user')->where('user_id', $member->id)->where('role_id', $viewer->id)->exists());

        $row = DB::table('audit_logs')->where('action', 'access.member_updated')->where('entity_id', $member->uuid)->sole();
        $this->assertSame($this->owner->id, (int) $row->user_id);
        $before = json_decode((string) $row->before, true);
        $after = json_decode((string) $row->after, true);
        $this->assertSame('viewer-fx', $before['category']);
        $this->assertSame('finance-fx', $after['category']);
        $this->assertContains('campaigns', $before['sections']);
        $this->assertNotContains('invoices', $after['sections']);
        $this->assertSame([(string) $client->id], $after['client_ids']);
        $this->assertSame('moved to finance', $row->reason);

        // The compact log in the access center shows who, whom, when and what changed.
        $log = $this->getJson('/api/v1/settings/access/audit')->assertOk()->json('data.entries.0');
        $this->assertSame('access.member_updated', $log['action']);
        $this->assertSame('owner@fixture.test', $log['by']);
        $this->assertSame('mover@fixture.test', $log['target']);
        $fields = array_column($log['changes'], 'field');
        $this->assertContains('category', $fields);
        $this->assertContains('sections', $fields);
        $this->assertContains('client_ids', $fields);
    }

    public function test_the_access_center_lists_sections_prefilled_per_member(): void
    {
        $member = $this->member('listed@fixture.test', $this->role('listed', self::EVERYTHING_BUT_ADMIN));
        $this->switchSections($member, ['reports' => false]);

        $data = $this->getJson('/api/v1/settings/access')->assertOk()->json('data');
        $this->assertSame(AccessSections::keys(), array_column($data['sections'], 'key'));
        $row = collect($data['members'])->firstWhere('email', 'listed@fixture.test');
        $this->assertFalse($row['sections']['reports']);
        $this->assertTrue($row['sections']['campaigns']);
        $this->assertFalse($row['sections']['team']);
    }

    public function test_walls_hold_self_owner_unknown_section_and_grant_only_what_you_hold(): void
    {
        $member = $this->member('walled@fixture.test', $this->role('walled', ['projects.view', 'clients.view']));

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/settings/access/members/{$this->owner->uuid}", ['sections' => ['team' => false]])->assertForbidden();
        $this->putJson("/api/v1/settings/access/members/{$member->uuid}", ['sections' => ['payroll' => false]])->assertStatus(422);

        // A manager without billing cannot switch invoices on for somebody else.
        $manager = $this->member('manager@fixture.test', $this->role('mgr', ['settings.manage', 'projects.view', 'clients.view']));
        Sanctum::actingAs($manager);
        $this->putJson("/api/v1/settings/access/members/{$member->uuid}", ['sections' => ['invoices' => true]])->assertForbidden();
        $this->assertFalse(DB::table('member_access_overrides')->where('user_id', $member->id)->exists());
    }

    public function test_an_existing_member_without_rows_keeps_exactly_their_access(): void
    {
        $keys = ['projects.view', 'projects.view.all', 'clients.view', 'clients.view_all', 'analytics.view', 'campaigns.view', 'reports.view'];
        $member = $this->member('legacy@fixture.test', $this->role('legacy', $keys));
        Sanctum::actingAs($member);
        $me = $this->getJson('/api/v1/me/access')->assertOk()->json('data');
        // analytics.view already opens budgets and spend limits (ProjectAbilities), so the sections say so too.
        $this->assertSame(['campaigns', 'budgets', 'spend_limits', 'reports'], $me['sections']);
        $this->assertTrue($me['clients']['all']);
        $this->assertSame('legacy', $me['role']['slug']);
    }

    public function test_a_role_change_from_the_old_team_screen_keeps_a_switched_off_section(): void
    {
        $this->role('other', ['projects.view', 'clients.view', 'campaigns.view']);
        $member = $this->member('kept@fixture.test', $this->role('first', ['projects.view', 'clients.view', 'campaigns.view']));
        $this->switchSections($member, ['campaigns' => false]);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/settings/team/{$member->uuid}/role", ['role' => 'other'])->assertOk();
        $this->assertTrue(DB::table('member_access_overrides')->where('user_id', $member->id)->where('permission_key', 'section:campaigns')->exists());
    }
}
