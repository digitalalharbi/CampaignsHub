<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Review\ProviderReviewItem;
use App\Domains\Integrations\Review\ReviewCatalogue;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REVIEW-001 — eight checklists, because there are eight different reviews.
 *
 * The failure this guards against is a single generic list applied to every provider. It would be
 * wrong in both directions at once: silent about the developer token that makes every Google call
 * fail, and about the organisation id without which a valid Snapchat token lists nothing — while
 * demanding business verification from Salla, which has no such thing.
 */
final class ProviderReviewChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ── the checklists are genuinely different ────────────────────────────────────────────────

    public function test_every_provider_has_its_own_checklist(): void
    {
        $res = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/integrations/review')->assertOk();

        $providers = $res->json('data.providers');

        $this->assertCount(count(ProviderCatalogue::all()), $providers);

        // No two providers share an identical requirement set — that would be the generic list.
        $signatures = array_map(
            static fn (array $p): string => implode(',', array_column($p['items'], 'key')),
            $providers,
        );
        $this->assertSame(count($signatures), count(array_unique($signatures)), 'two providers share one checklist');
    }

    /**
     * Each platform's own blockers appear, named.
     *
     * These are the specific facts that make a correct-looking configuration return nothing.
     */
    public function test_each_provider_names_the_requirement_that_actually_blocks_it(): void
    {
        $expected = [
            'google' => 'developer_token_basic',
            'meta' => 'business_verification',
            'tiktok' => 'sandbox_whitelist',
            // Was `organisation_id` — retired with the field itself (SNAP-ORG-001). A system
            // credential naming one customer's organisation was never the thing that blocks
            // Snapchat; the app review is, and it is the one an operator must actually go and get.
            'snapchat' => 'app_submission',
            'linkedin' => 'advertising_api_product',
            'x' => 'project_tier',
            'salla' => 'oauth_mode_not_easy',
            'zid' => 'manager_token',
        ];

        foreach ($expected as $provider => $requirement) {
            $keys = array_column(ReviewCatalogue::for($provider), 'key');
            $this->assertContains($requirement, $keys, "{$provider} does not name {$requirement}");
        }
    }

    /** …and does not carry another platform's requirement. */
    public function test_a_provider_does_not_carry_another_platforms_requirement(): void
    {
        $sallaKeys = array_column(ReviewCatalogue::for('salla'), 'key');

        $this->assertNotContains('business_verification', $sallaKeys);
        $this->assertNotContains('developer_token_basic', $sallaKeys);
        $this->assertNotContains('project_tier', $sallaKeys);
    }

    /** Every requirement explains why it matters — a bare checkbox teaches nobody anything. */
    public function test_every_requirement_states_why_it_matters_in_both_languages(): void
    {
        foreach (ProviderCatalogue::all() as $definition) {
            foreach (ReviewCatalogue::for($definition->key) as $item) {
                $this->assertNotEmpty($item['why_ar'], "{$definition->key}/{$item['key']} has no Arabic reason");
                $this->assertNotEmpty($item['why_en'], "{$definition->key}/{$item['key']} has no English reason");
            }
        }
    }

    // ── derived items are answered, not asked ─────────────────────────────────────────────────

    /**
     * The redirect URI is stated, and an insecure one reads as missing.
     *
     * Every one of these platforms refuses a non-HTTPS redirect, so showing a working localhost URL
     * as satisfied would carry somebody to a submission with the one value guaranteed to fail.
     */
    public function test_the_redirect_uri_is_derived_and_an_insecure_one_is_not_satisfied(): void
    {
        // The redirect base is its OWN config key, not `app.url` — the checklist has to read the same
        // source the connector will actually send, or it would vouch for a different URL.
        config(['ad_platforms.redirect_base' => 'http://localhost:8000', 'app.url' => 'http://localhost:8000']);

        $item = $this->itemFor('meta', 'redirect_uri');

        $this->assertSame('missing', $item['status']);
        $this->assertFalse($item['editable']);
        $this->assertStringContainsString('/oauth/ads/meta/callback', $item['value']);
        $this->assertNotEmpty($item['detail_en']);
    }

    public function test_an_https_redirect_uri_is_satisfied(): void
    {
        config(['ad_platforms.redirect_base' => 'https://app.campaignshub.io']);

        $this->assertSame('ready', $this->itemFor('meta', 'redirect_uri')['status']);
    }

    /** The scopes are shown as the connector actually requests them. */
    public function test_least_privilege_lists_the_scopes_actually_requested(): void
    {
        $item = $this->itemFor('salla', 'least_privilege');

        $this->assertSame('ready', $item['status']);
        $this->assertStringContainsString('offline_access', $item['value']);
    }

    /**
     * A derived requirement cannot be ticked.
     *
     * A checklist somebody can mark complete without doing anything is a checklist that lies — and it
     * would disagree with itself on the next page load, since these are recomputed every read.
     */
    public function test_a_derived_requirement_cannot_be_set_by_hand(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson('/api/v1/admin/integrations/review/meta/redirect_uri', ['status' => 'approved'])
            ->assertStatus(422);

        $this->assertSame(0, ProviderReviewItem::query()->count());
    }

    // ── declared items ────────────────────────────────────────────────────────────────────────

    public function test_the_operator_can_record_what_happened_in_the_providers_console(): void
    {
        $res = $this->actingAs($this->owner(), 'sanctum')
            ->patchJson('/api/v1/admin/integrations/review/meta/business_verification', [
                'status' => 'submitted', 'note' => 'Documents uploaded 2026-08-07.',
            ])->assertOk();

        $item = collect($res->json('data.items'))->firstWhere('key', 'business_verification');

        $this->assertSame('submitted', $item['status']);
        $this->assertSame('Documents uploaded 2026-08-07.', $item['note']);
        $this->assertSame(1, AuditLog::query()->where('action', 'integrations.review.updated')->count());
    }

    /**
     * «Ready» and «submitted» are kept apart.
     *
     * «We have done our part» and «the platform has been asked» are different positions, and merging
     * them is how a review sits unsubmitted for a month behind a board that shows it in progress.
     */
    public function test_ready_and_submitted_are_distinct_states(): void
    {
        $this->assertContains('ready', ProviderReviewItem::STATUSES);
        $this->assertContains('submitted', ProviderReviewItem::STATUSES);
    }

    /** The summary answers the only question the screen is really for. */
    public function test_the_summary_says_whether_the_application_can_be_submitted(): void
    {
        config(['app.url' => 'https://app.campaignshub.io']);

        $summary = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/integrations/review/x')->assertOk()->json('data.summary');

        $this->assertSame(count(ReviewCatalogue::for('x')), $summary['total']);
        // Declared items start missing, so a fresh install is honestly not submittable.
        $this->assertGreaterThan(0, $summary['missing']);
        $this->assertFalse($summary['submittable']);
    }

    public function test_an_unknown_provider_is_a_404(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/integrations/review/mystery')->assertNotFound();
    }

    // ── the boundary ──────────────────────────────────────────────────────────────────────────

    public function test_a_tenant_user_cannot_read_or_change_a_review_checklist(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'rev@tenant.test', 'password' => 'secret123', 'email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/integrations/review')->assertForbidden();
        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/admin/integrations/review/meta/business_verification', ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson('/api/v1/admin/integrations/review')->assertUnauthorized();
    }

    /** @return array<string,mixed> */
    private function itemFor(string $provider, string $key): array
    {
        $res = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/admin/integrations/review/{$provider}")->assertOk();

        return collect($res->json('data.items'))->firstWhere('key', $key);
    }

    private function owner(): User
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'review-owner@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }
}
