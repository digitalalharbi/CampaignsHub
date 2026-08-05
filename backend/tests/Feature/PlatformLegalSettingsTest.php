<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Legal\PolicyRegistry;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LEGAL-001 — who operates this platform, and which version of each policy is in force.
 *
 * The rule the whole slice turns on: an unknown legal fact stays unknown. A registration number or a
 * jurisdiction is a business fact the operator supplies, and a plausible default for either would end
 * up printed on a published privacy policy and relied upon by somebody. So the fields exist, they are
 * empty, the interface says which are missing, and nothing fills them in.
 */
final class PlatformLegalSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ── the public surface ────────────────────────────────────────────────────────────────────

    /**
     * The legal endpoint answers without a session.
     *
     * A requirement, not a convenience: every platform whose OAuth review this product must pass
     * fetches the privacy and terms URLs itself, unauthenticated. A policy surface behind a login
     * fails those reviews with no explanation given.
     */
    public function test_the_legal_metadata_is_readable_without_signing_in(): void
    {
        $res = $this->getJson('/api/v1/legal')->assertOk();

        $this->assertNotEmpty($res->json('data.documents'));
        $this->assertContains('terms', array_column($res->json('data.documents'), 'slug'));
        $this->assertContains('privacy', $res->json('data.binding'));
    }

    /** Every published document carries a version and the date it took effect. */
    public function test_every_document_states_a_version_and_an_effective_date(): void
    {
        foreach ($this->getJson('/api/v1/legal')->json('data.documents') as $doc) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $doc['version'], $doc['slug'].' has no version');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $doc['effective'], $doc['slug'].' has no effective date');
        }
    }

    /**
     * A fresh installation admits it has no legal identity rather than inventing one.
     *
     * This is the assertion that stops a well-meaning default from becoming a published claim about
     * a company that does not exist.
     */
    public function test_a_fresh_install_publishes_no_legal_identity_and_says_so(): void
    {
        $operator = $this->getJson('/api/v1/legal')->json('data.operator');

        $this->assertFalse($operator['published']);
        $this->assertNull($operator['legal_name_ar']);
        $this->assertNull($operator['legal_name_en']);
        $this->assertNull($operator['registration_number']);
        $this->assertNull($operator['jurisdiction']);
        // The one contact that is ours to state, because the product already publishes it.
        $this->assertSame('info@CampaignsHub.io', $operator['contact_email']);
    }

    /** Contacts fall back to the general address rather than rendering blank. */
    public function test_unset_contacts_fall_back_to_the_general_address(): void
    {
        $operator = $this->getJson('/api/v1/legal')->json('data.operator');

        $this->assertSame('info@CampaignsHub.io', $operator['privacy_email']);
        $this->assertSame('info@CampaignsHub.io', $operator['security_email']);
        $this->assertSame('info@CampaignsHub.io', $operator['support_email']);
    }

    // ── the operator's editor ─────────────────────────────────────────────────────────────────

    public function test_the_platform_operator_can_publish_the_legal_identity(): void
    {
        $res = $this->actingAs($this->platformOwner(), 'sanctum')
            ->putJson('/api/v1/admin/settings/platform', [
                'legal_name_ar' => 'شركة كامبينزهب',
                'legal_name_en' => 'CampaignsHub Co.',
                'registration_number' => '1010101010',
                'jurisdiction' => 'Riyadh, Saudi Arabia',
                'contact_email' => 'info@CampaignsHub.io',
                'privacy_email' => 'privacy@CampaignsHub.io',
            ])->assertOk();

        $this->assertTrue($res->json('data.published'));
        $this->assertSame([], $res->json('data.missing'));

        // …and the public page sees it, with no session involved.
        $operator = $this->getJson('/api/v1/legal')->json('data.operator');
        $this->assertSame('CampaignsHub Co.', $operator['legal_name_en']);
        $this->assertSame('privacy@CampaignsHub.io', $operator['privacy_email']);
    }

    /** What is still missing is named, so «is my policy publishable» is answerable on the screen. */
    public function test_the_editor_names_what_is_still_missing(): void
    {
        $res = $this->actingAs($this->platformOwner(), 'sanctum')
            ->getJson('/api/v1/admin/settings/platform')->assertOk();

        $this->assertFalse($res->json('data.published'));
        $this->assertContains('legal_name', $res->json('data.missing'));
    }

    /** A change to the published legal identity is audited, by field name. */
    public function test_changing_the_legal_identity_is_audited_by_field(): void
    {
        $this->actingAs($this->platformOwner(), 'sanctum')
            ->putJson('/api/v1/admin/settings/platform', [
                'legal_name_en' => 'CampaignsHub Co.',
                'contact_email' => 'info@CampaignsHub.io',
            ])->assertOk();

        $entry = AuditLog::query()->where('action', 'platform.settings.updated')->latest()->first();

        $this->assertNotNull($entry);
        $this->assertContains('legal_name_en', $entry->after['fields']);
    }

    /** A save that changes nothing writes no audit entry — noise buries the entries that matter. */
    public function test_a_save_that_changes_nothing_writes_no_audit_entry(): void
    {
        $owner = $this->platformOwner();
        $payload = ['legal_name_en' => 'CampaignsHub Co.', 'contact_email' => 'info@CampaignsHub.io'];

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/admin/settings/platform', $payload)->assertOk();
        $before = AuditLog::query()->where('action', 'platform.settings.updated')->count();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/admin/settings/platform', $payload)->assertOk();

        $this->assertSame($before, AuditLog::query()->where('action', 'platform.settings.updated')->count());
    }

    /**
     * A tenant administrator cannot change who the data controller is.
     *
     * The reason this record is not tenant-scoped: it names the party responsible under a published
     * privacy policy, and a customer able to edit that would be a considerable problem.
     */
    public function test_a_tenant_administrator_cannot_change_the_operators_identity(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        $this->actingAs($this->tenantAdmin($tenant), 'sanctum')
            ->putJson('/api/v1/admin/settings/platform', [
                'legal_name_en' => 'Not Their Company',
                'contact_email' => 'attacker@example.com',
            ])->assertForbidden();

        $this->assertNull(PlatformSetting::current()->legal_name_en);
    }

    public function test_an_anonymous_caller_cannot_change_the_operators_identity(): void
    {
        $this->putJson('/api/v1/admin/settings/platform', [
            'legal_name_en' => 'Nope', 'contact_email' => 'a@b.test',
        ])->assertUnauthorized();
    }

    /** A policy page must always be able to say how to reach the operator. */
    public function test_the_contact_address_cannot_be_cleared(): void
    {
        $this->actingAs($this->platformOwner(), 'sanctum')
            ->putJson('/api/v1/admin/settings/platform', ['contact_email' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_email');
    }

    // ── the registry ──────────────────────────────────────────────────────────────────────────

    /** Binding documents are a subset of published ones — nothing can be required but unreadable. */
    public function test_every_binding_document_is_also_published(): void
    {
        foreach (PolicyRegistry::binding() as $slug) {
            $this->assertTrue(PolicyRegistry::has($slug), "{$slug} is binding but not published");
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function platformOwner(): User
    {
        $user = User::create([
            'name' => 'Platform Owner', 'email' => 'legal-owner@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    private function tenantAdmin(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Tenant Admin', 'email' => 'legal-admin@tenant.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'slug' => 'admin-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::App, role: 'owner',
        ));

        return $user;
    }
}
