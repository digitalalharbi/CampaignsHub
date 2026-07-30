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
 * Public page settings are the CMS for the marketing homepage + the three external portals. The contract:
 * a draft is never live, publishing is what visitors see, everything is permission-gated + tenant-isolated,
 * and the public endpoint serves published content (or shipped defaults) with no auth.
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

    private function owner(?Tenant $tenant = null): User
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

    public function test_index_returns_every_editable_page_with_shipped_defaults(): void
    {
        $res = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/settings/public-pages')->assertOk();

        $pages = array_column($res->json('data'), 'page');
        $this->assertSame(PublicPageSetting::PAGES, $pages);
        $this->assertFalse($res->json('data.0.is_published'));
        $this->assertNotEmpty($res->json('data.0.draft.hero.title'));
    }

    public function test_saving_a_draft_does_not_change_what_the_public_sees(): void
    {
        $owner = $this->owner();
        $draft = ['hero' => ['enabled' => true, 'order' => 1, 'title' => 'مسودة غير منشورة']];

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/settings/public-pages/home', ['draft' => $draft])
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
        $owner = $this->owner();
        $draft = [
            'hero' => ['enabled' => true, 'order' => 1, 'title' => 'عنوان منشور'],
            'services' => ['enabled' => false, 'order' => 3, 'title' => 'الخدمات'],
        ];

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/settings/public-pages/home', ['draft' => $draft])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/settings/public-pages/home/publish')
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
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/settings/public-pages/home', ['draft' => ['hero' => ['title' => 'v1']]])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/settings/public-pages/home/publish')->assertOk();
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/settings/public-pages/home', ['draft' => ['hero' => ['title' => 'v2-unsaved']]])->assertOk();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/settings/public-pages/home/revert')
            ->assertOk()
            ->assertJsonPath('data.draft.hero.title', 'v1')
            ->assertJsonPath('data.has_unpublished_changes', false);
    }

    public function test_writes_require_settings_manage_and_unknown_pages_404(): void
    {
        $viewer = User::create([
            'name' => 'V', 'email' => 'v-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($viewer, $this->tenant);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/settings/public-pages')->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->putJson('/api/v1/settings/public-pages/home', ['draft' => []])->assertForbidden();
        $this->actingAs($this->owner(), 'sanctum')->putJson('/api/v1/settings/public-pages/nope', ['draft' => ['a' => 1]])->assertNotFound();
        $this->getJson('/api/v1/public/pages/nope')->assertNotFound();
    }

    public function test_publishing_is_tenant_isolated(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);

        // Tenant A publishes its own content.
        $this->actingAs($this->owner(), 'sanctum')->putJson('/api/v1/settings/public-pages/portal_paid', ['draft' => ['hero' => ['title' => 'A only']]])->assertOk();
        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/settings/public-pages/portal_paid/publish')->assertOk();

        // Tenant B sees ITS own row set (defaults), never tenant A's draft/published content.
        app(TenantContext::class)->setTenantId($other->id);
        $res = $this->actingAs($this->owner($other), 'sanctum')->getJson('/api/v1/settings/public-pages')->assertOk();
        $paid = collect($res->json('data'))->firstWhere('page', 'portal_paid');
        $this->assertFalse($paid['is_published']);
        $this->assertNotSame('A only', $paid['draft']['hero']['title'] ?? null);
    }

    /**
     * SITE-CMS-002: each of the three external portals is its own editable document, so publishing the
     * influencer portal must not touch the homepage or the other portals.
     */
    public function test_each_external_portal_publishes_independently(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/settings/public-pages/portal_influencer', ['draft' => ['hero' => ['title' => 'بوابة المؤثرين المحرَّرة']]])
            ->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/settings/public-pages/portal_influencer/publish')->assertOk();

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
