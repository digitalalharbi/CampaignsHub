<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * MAIL-014 — the operator's answer to «did that email go out?».
 *
 * The assertions are about the three ways this console could mislead the person reading it:
 *
 * 1. **Answering from one ledger.** Transactional mail and digests are recorded in different tables;
 *    a page built on either alone shows a healthy install while the other half is failing.
 * 2. **Letting `sandbox` read as success.** The `log` mailer succeeds at everything and delivers
 *    nothing. An operator who tells a customer their invoice went out has been misled by their own
 *    console.
 * 3. **Being reachable by somebody who is not the platform owner.** The ledger spans every tenant's
 *    recipients.
 */
final class PlatformEmailOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-mailops', 'status' => 'active']);

        $this->owner = User::create([
            'name' => 'Platform', 'email' => 'owner@campaignshub.io',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        // `is_platform_admin` is guarded on the model — deliberately, so no mass-assign can mint an
        // owner. Force-filled here exactly as `PlatformConsoleTest` does.
        $this->owner->forceFill(['is_platform_admin' => true])->save();
    }

    private function delivery(array $over = []): void
    {
        DB::table('mail_deliveries')->insert($over + [
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'kind' => 'password_reset',
            'recipient' => 'sara@example.com',
            'locale' => 'ar',
            'template' => 'credential',
            'status' => 'sent',
            'transport' => 'log',
            'attempts' => 1,
            'dedup_key' => (string) Uuid::uuid4(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function digest(array $over = []): void
    {
        DB::table('digest_sends')->insert($over + [
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->owner->id,
            'kind' => 'daily',
            // 24 characters is the column's whole width — the same limit that made MAIL-006 hash
            // its cooldown key rather than write a readable one.
            'period_key' => substr((string) Uuid::uuid4(), 0, 24),
            'status' => 'failed',
            'reason' => 'exception',
            'attempts' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The ledger spans every tenant's recipients, so it is the owner's console and nobody else's. */
    public function test_the_ledger_is_closed_to_anybody_who_is_not_the_platform_owner(): void
    {
        $member = User::create(['name' => 'M', 'email' => 'm@acme.test', 'password' => 'secret123']);

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/admin/email/deliveries')->assertStatus(403);
        // A guest is refused by the same gate rather than sent to a login — the console does not
        // acknowledge that these routes exist.
        $this->getJson('/api/v1/admin/email/deliveries')->assertStatus(403);
    }

    /**
     * **The one that matters.** Both ledgers answer the same question.
     *
     * A console reading `mail_deliveries` alone would show one healthy send here and miss the digest
     * that failed — and «is mail working?» would be answered «yes».
     */
    public function test_a_failed_digest_appears_beside_a_successful_transactional_message(): void
    {
        $this->delivery();
        $this->digest();

        $rows = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries')->assertOk()->json('data.deliveries');

        $sources = array_column($rows, 'source');
        $this->assertContains('transactional', $sources);
        $this->assertContains('digest', $sources, 'the digest ledger was not read at all');
        $byState = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries')->json('data.by_state');

        $this->assertSame(1, $byState['sent'] ?? 0);
        $this->assertSame(1, $byState['failed'] ?? 0, 'the failed digest was not counted');
    }

    /**
     * The transport is stated rather than inferred from a table of identical states.
     *
     * `sandbox` is the dangerous one: every row says «sent» and not one message reached a human. The
     * test suite runs on the `array` mailer, which is exactly that case.
     */
    public function test_the_response_says_what_this_install_can_actually_do_with_an_email(): void
    {
        $transport = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries')->assertOk()->json('data.transport');

        $this->assertContains($transport['state'], ['sandbox', 'awaiting_credentials', 'live']);
        $this->assertFalse($transport['provider_configured'], 'no email provider is wired in tests');
        $this->assertNotEmpty($transport['driver']);
    }

    public function test_the_filters_narrow_by_status_recipient_and_ledger(): void
    {
        $this->delivery(['recipient' => 'sara@example.com', 'status' => 'sent']);
        $this->delivery(['recipient' => 'omar@example.com', 'status' => 'failed', 'error' => 'mailbox full']);
        $this->digest();

        $onlyFailed = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries?status=failed')->assertOk()->json('data.deliveries');
        $this->assertSame(['failed', 'failed'], array_column($onlyFailed, 'status'));

        $onlySara = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries?recipient=sara')->assertOk()->json('data.deliveries');
        $this->assertCount(1, $onlySara);
        $this->assertSame('sara@example.com', $onlySara[0]['recipient']);

        $onlyDigests = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries?source=digest')->assertOk()->json('data.deliveries');
        $this->assertSame(['digest'], array_unique(array_column($onlyDigests, 'source')));
    }

    /** The reason travels with the row, because «failed» on its own is not something anybody can act on. */
    public function test_a_failure_carries_the_reason_it_failed(): void
    {
        $this->delivery(['status' => 'failed', 'error' => 'SMTP 550 mailbox unavailable']);

        $row = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries')->assertOk()->json('data.deliveries.0');

        $this->assertSame('SMTP 550 mailbox unavailable', $row['reason']);
        $this->assertSame(1, $row['attempts']);
    }

    // ── The gallery ─────────────────────────────────────────────────────────────────────────────

    /**
     * The gallery shows exactly what `notifications:preview` writes.
     *
     * Two callers with their own fixtures is how the page an operator opens and the files a developer
     * renders stop being the same product. They read one definition, and this is the assertion that
     * keeps it that way.
     */
    public function test_the_gallery_offers_every_message_the_preview_command_writes(): void
    {
        $keys = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/previews')->assertOk()->json('data.keys');

        $this->assertSame(MailGallery::keys(), $keys);
        $this->assertContains('alerts-bundle', $keys);
        $this->assertContains('account-password-reset', $keys);
    }

    public function test_a_preview_renders_in_both_languages(): void
    {
        $ar = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/previews/alerts-bundle?locale=ar')->assertOk()->json('data.html');
        $en = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/previews/alerts-bundle?locale=en')->assertOk()->json('data.html');

        $this->assertStringContainsString('dir="rtl"', $ar);
        $this->assertStringContainsString('dir="ltr"', $en);
        $this->assertStringContainsString('CampaignsHub', $ar);
    }

    public function test_a_preview_that_does_not_exist_is_a_refusal_rather_than_a_blank_page(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/previews/not-a-message')->assertStatus(404);
    }

    /**
     * The gallery renders FIXTURES, never a customer's mail.
     *
     * The ledger deliberately carries no body column and the preview endpoint reads only the
     * catalogue — an operator's console that could open somebody's actual reset email would be a
     * different product, and a worse one.
     */
    public function test_the_ledger_never_carries_a_message_body(): void
    {
        $this->delivery();

        $row = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/email/deliveries')->assertOk()->json('data.deliveries.0');

        foreach (['body', 'html', 'content', 'subject'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "the ledger exposed «{$forbidden}»");
        }
    }
}
