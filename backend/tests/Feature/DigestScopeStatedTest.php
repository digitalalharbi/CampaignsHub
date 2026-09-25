<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * PROJECT-DIGEST-SCOPE-001 — «all my projects» said out loud.
 *
 * An empty `project_ids` has always meant «every project you may reach». That is a defensible
 * default for a preference, and it is not a stated choice: a settings screen reading it has to
 * imply «جميع المشاريع» from an absence, which is the same absent-means-everything shape the
 * portfolio work removed from the project scope.
 *
 * So the preference now SAYS which of the two it is, and the send path is untouched — the point of
 * this unit is that nobody's mail moves. The second test is the one that matters: it asserts the
 * resolver still returns the same projects it did before, so the new word cannot have changed who
 * receives what.
 */
final class DigestScopeStatedTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Project $first;

    private Project $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->first = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->second = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'B', 'status' => 'active']);
    }

    /** No chosen list is «all», and it says so rather than leaving a screen to infer it. */
    public function test_an_unchosen_scope_reads_as_all(): void
    {
        $this->assertSame('all', $this->preferences()['digest_scope']);
    }

    /** A chosen list is «selected». */
    public function test_a_chosen_list_reads_as_selected(): void
    {
        $this->savePreference([(string) $this->first->id]);

        $this->assertSame('selected', $this->preferences()['digest_scope']);
    }

    /**
     * **The point of the unit.** The word is new; who receives what is not.
     *
     * `DigestScope` still reads `project_ids` exactly as before — empty means the whole ceiling —
     * so naming the scope cannot have moved anybody's mail.
     */
    public function test_naming_the_scope_does_not_change_who_is_sent_what(): void
    {
        $unchosen = app(DigestScope::class)->projectIdsFor($this->user, (string) $this->tenant->id);
        sort($unchosen);
        $this->assertSame([(string) $this->first->id, (string) $this->second->id], $unchosen);

        $this->savePreference([(string) $this->second->id]);

        $this->assertSame(
            [(string) $this->second->id],
            app(DigestScope::class)->projectIdsFor($this->user, (string) $this->tenant->id),
            'a narrowed preference stopped narrowing',
        );
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function preferences(): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/settings/notifications')
            ->assertOk()
            ->json('data');
    }

    /** @param list<string> $ids */
    private function savePreference(array $ids): void
    {
        DB::table('notification_preferences')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'client_workspace_id' => null],
            [
                'id' => (string) Str::uuid(),
                'project_ids' => json_encode($ids),
                'channels' => json_encode(['email' => true]),
                'categories' => json_encode([]),
                'frequency' => 'realtime',
                'timezone' => 'Asia/Riyadh',
                'locale' => 'ar',
                'digest_hour' => 8,
                'digest_weekday' => 1,
                'digest_monthday' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
