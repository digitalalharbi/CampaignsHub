<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Settings\Models\PublicPageSetting;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PAGES-001 — the marketing homepage and the three public portals, owned by the platform.
 *
 * The contract: a draft is never live, publishing is what visitors see, the editor is reachable by the
 * platform operator and by nobody else, and the public endpoint serves the published content (or the
 * shipped defaults) with no auth.
 *
 * The two failures this guards against are the ones that were actually shipped:
 *
 *  - the editor sat behind tenant scope while the console that renders it is `/admin`, where the
 *    operator holds no membership — so the tab could only ever show a load error;
 *  - every tenant had a row and the public read took whichever was published last, so a customer could
 *    rewrite the platform's own front page.
 */
final class PublicPageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** The platform operator: no tenant, no role — which is the whole point. */
    private function operator(): User
    {
        $user = User::create([
            'name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    /** A tenant administrator with every permission their own workspace can grant. */
    private function tenantOwner(?Tenant $tenant = null): User
    {
        $t = $tenant ?? $this->tenant;
        $role = Role::create(['tenant_id' => $t->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create([
            'name' => 'Owner', 'email' => 'o-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $t);
        $user->assignRole($role);

        return $user;
    }

    // ── the console can actually load it ──────────────────────────────────────────────────────

    /**
     * The regression that was reported: «تعذّر تحميل إعدادات الصفحات» in `/admin/settings`.
     *
     * The operator belongs to no tenant. Any gate that asks them for one — tenant scope, a portal
     * membership, a permission granted through a tenant role — refuses the only person entitled to be
     * here, and does it with a load error rather than an explanation.
     */
    public function test_the_platform_operator_can_load_the_editor(): void
    {
        $res = $this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/admin/settings/public-pages')->assertOk();

        $pages = array_column($res->json('data'), 'page');
        $this->assertSame(PublicPageSetting::PAGES, $pages);
        $this->assertFalse($res->json('data.0.is_published'));
        $this->assertNotEmpty($res->json('data.0.draft.hero.title'));
    }

    public function test_saving_a_draft_does_not_change_what_the_public_sees(): void
    {
        $draft = ['hero' => ['enabled' => true, 'order' => 1, 'title' => 'مسودة غير منشورة']];

        $this->actingAs($this->operator(), 'sanctum')
            ->putJson('/api/v1/admin/settings/public-pages/home', ['draft' => $draft])
            ->assertOk()
            ->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonPath('data.is_published', false);

        // The public surface still serves the shipped defaults — a draft is never live.
        $public = $this->getJson('/api/v1/public/pages/home')->assertOk();
        $this->assertSame('defaults', $public->json('data.source'));
        $this->assertNotSame('مسودة غير منشورة', $public->json('data.content.hero.title'));
    }

    public function test_publishing_promotes_the_draft_and_the_public_endpoint_serves_it_without_auth(): void
    {
        $operator = $this->operator();
        $draft = [
            'hero' => ['enabled' => true, 'order' => 1, 'title' => 'عنوان منشور'],
            'services' => ['enabled' => false, 'order' => 3, 'title' => 'الخدمات'],
        ];

        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/admin/settings/public-pages/home', ['draft' => $draft])->assertOk();
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/admin/settings/public-pages/home/publish')
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.has_unpublished_changes', false);

        // No auth here — this is what a visitor gets, including the disabled section.
        $public = $this->getJson('/api/v1/public/pages/home')->assertOk();
        $this->assertSame('published', $public->json('data.source'));
        $this->assertSame('عنوان منشور', $public->json('data.content.hero.title'));
        $this->assertFalse($public->json('data.content.services.enabled'));
    }

    public function test_revert_restores_the_draft_to_the_published_version(): void
    {
        $operator = $this->operator();
        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/admin/settings/public-pages/home', ['draft' => ['hero' => ['title' => 'v1']]])->assertOk();
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/admin/settings/public-pages/home/publish')->assertOk();
        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/admin/settings/public-pages/home', ['draft' => ['hero' => ['title' => 'v2-unsaved']]])->assertOk();

        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/admin/settings/public-pages/home/revert')
            ->assertOk()
            ->assertJsonPath('data.draft.hero.title', 'v1')
            ->assertJsonPath('data.has_unpublished_changes', false);
    }

    public function test_unknown_pages_are_404(): void
    {
        $this->actingAs($this->operator(), 'sanctum')
            ->putJson('/api/v1/admin/settings/public-pages/nope', ['draft' => ['a' => 1]])->assertNotFound();
        $this->getJson('/api/v1/public/pages/nope')->assertNotFound();
    }

    // ── one homepage, one owner ───────────────────────────────────────────────────────────────

    /**
     * A tenant administrator cannot touch the platform's public site — not with every permission their
     * own workspace can grant them.
     *
     * This replaces the old «publishing is tenant isolated» test, which asked a weaker question. Under
     * the previous design each tenant had a row of its own AND the public endpoint read whichever was
     * published most recently, so isolation between those rows was never what protected the front
     * page. The guarantee that matters is that there is nothing here for a customer to publish at all.
     */
    public function test_a_tenant_administrator_cannot_read_or_change_the_public_site(): void
    {
        $owner = $this->tenantOwner();

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/settings/public-pages')->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/public-pages/home', ['draft' => ['hero' => ['title' => 'ours now']]])
            ->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/admin/settings/public-pages/home/publish')->assertForbidden();

        // …and the old tenant-scoped address is gone rather than merely unused.
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/settings/public-pages')->assertNotFound();
    }

    public function test_an_anonymous_caller_cannot_reach_the_editor(): void
    {
        $this->getJson('/api/v1/admin/settings/public-pages')->assertUnauthorized();
    }

    /**
     * A leftover per-tenant row from before the move is never served to a visitor.
     *
     * The migration deliberately does not delete these — destroying somebody's writing to tidy up a
     * schema is not a migration's decision — so the READ has to be what ignores them. If it fell back
     * to «the most recently published row anywhere», one of these would be the homepage.
     */
    public function test_a_legacy_tenant_row_is_not_served_to_visitors(): void
    {
        PublicPageSetting::create([
            'tenant_id' => $this->tenant->id,
            'page' => 'home',
            'draft' => ['hero' => ['title' => 'legacy tenant copy']],
            'published' => ['hero' => ['title' => 'legacy tenant copy']],
            'version' => 7,
            'published_at' => now(),
        ]);

        $public = $this->getJson('/api/v1/public/pages/home')->assertOk();

        $this->assertSame('defaults', $public->json('data.source'));
        $this->assertNotSame('legacy tenant copy', $public->json('data.content.hero.title'));
    }

    /** …and it is not shown in the operator's editor either. */
    public function test_a_legacy_tenant_row_is_not_shown_in_the_editor(): void
    {
        PublicPageSetting::create([
            'tenant_id' => $this->tenant->id,
            'page' => 'home',
            'draft' => ['hero' => ['title' => 'legacy tenant copy']],
            'version' => 0,
        ]);

        $res = $this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/admin/settings/public-pages')->assertOk();

        $home = collect($res->json('data'))->firstWhere('page', 'home');
        $this->assertNotSame('legacy tenant copy', $home['draft']['hero']['title'] ?? null);
    }

    /**
     * SITE-CMS-002: each of the three public portals is its own document, so publishing the influencer
     * portal must not touch the homepage or the other portals.
     */
    public function test_each_public_portal_publishes_independently(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/admin/settings/public-pages/portal_influencer', ['draft' => ['hero' => ['title' => 'بوابة المؤثرين المحرَّرة']]])
            ->assertOk();
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/admin/settings/public-pages/portal_influencer/publish')->assertOk();

        // The influencer portal serves the edited copy to a visitor with no auth...
        $influencer = $this->getJson('/api/v1/public/pages/portal_influencer')->assertOk();
        $this->assertSame('published', $influencer->json('data.source'));
        $this->assertSame('بوابة المؤثرين المحرَّرة', $influencer->json('data.content.hero.title'));

        // ...while every other public surface is untouched and still serves its shipped defaults.
        foreach (['home', 'portal_paid', 'portal_tracking'] as $page) {
            $other = $this->getJson("/api/v1/public/pages/{$page}")->assertOk();
            $this->assertSame('defaults', $other->json('data.source'), "{$page} must not inherit another portal's content");
            $this->assertNotSame('بوابة المؤثرين المحرَّرة', $other->json('data.content.hero.title'));
        }
    }
}
