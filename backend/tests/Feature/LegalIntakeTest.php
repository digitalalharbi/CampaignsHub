<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Legal\Models\ContactMessage;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Legal\Models\SupportTicket;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEGAL-002 — the three things a visitor can send, and what happens to each.
 *
 * The claim this suite defends is that none of them is a form that pretends. A contact message is
 * stored and appears in a queue a human opens. A support ticket comes back with a reference the
 * sender can quote. And a deletion request is neither silently executed nor silently discarded: it
 * records what is standing in the way, and refuses to complete while that remains true.
 */
final class LegalIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ── contact ───────────────────────────────────────────────────────────────────────────────

    public function test_a_contact_message_is_stored_and_reaches_the_operators_queue(): void
    {
        $this->postJson('/api/v1/contact', [
            'name' => 'سارة', 'email' => 'sara@example.test',
            'subject' => 'سؤال عن الباقات', 'message' => 'أريد معرفة الفرق بين الباقات قبل الاشتراك.',
        ])->assertOk()->assertJsonPath('data.received', true);

        $this->assertSame(1, ContactMessage::query()->count());

        $queue = $this->actingAs($this->platformOwner(), 'sanctum')
            ->getJson('/api/v1/admin/legal/contact-messages')->assertOk();

        $this->assertSame(1, $queue->json('data.unhandled'));
        $this->assertSame('sara@example.test', $queue->json('data.messages.0.email'));
    }

    /**
     * No tracking code is invented for an enquiry.
     *
     * Handing one back implies a queue the sender can chase; there is none, and a reference for a
     * process that does not exist is a promise nobody can keep.
     */
    public function test_a_contact_message_returns_no_tracking_reference(): void
    {
        $res = $this->postJson('/api/v1/contact', [
            'name' => 'سارة', 'email' => 'sara@example.test',
            'subject' => 'سؤال', 'message' => 'نص الرسالة يكفي طوله للتحقق.',
        ])->assertOk();

        $this->assertNull($res->json('data.reference'));
    }

    public function test_the_contact_form_refuses_a_bot(): void
    {
        $this->postJson('/api/v1/contact', [
            'name' => 'Bot', 'email' => 'bot@example.test', 'subject' => 'x',
            'message' => 'a message long enough to pass', 'website' => 'http://spam.example',
        ])->assertStatus(422);

        $this->assertSame(0, ContactMessage::query()->count());
    }

    // ── support ───────────────────────────────────────────────────────────────────────────────

    /** A ticket comes back with a handle, because «we received it» with no handle is silence. */
    public function test_a_support_ticket_returns_a_reference_the_sender_can_quote(): void
    {
        $res = $this->postJson('/api/v1/support/tickets', [
            'name' => 'خالد', 'email' => 'khaled@example.test',
            'subject' => 'المزامنة متوقفة', 'message' => 'حسابي الإعلاني مربوط ولم تصل أرقام منذ يومين.',
            'category' => 'integrations',
        ])->assertOk();

        $reference = $res->json('data.reference');

        $this->assertMatchesRegularExpression('/^CH-[A-Z0-9]{6}$/', $reference);
        $this->assertSame($reference, SupportTicket::query()->first()->reference);
    }

    /** The reference is readable aloud — no O/0 or I/1 to disambiguate down a phone. */
    public function test_the_reference_avoids_characters_that_are_confused_when_read_aloud(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[O0I1L]/', SupportTicket::makeReference());
        }
    }

    public function test_the_operator_can_work_a_ticket_and_resolve_it(): void
    {
        $reference = $this->postJson('/api/v1/support/tickets', [
            'name' => 'خالد', 'email' => 'k@example.test',
            'subject' => 'سؤال', 'message' => 'رسالة كافية الطول للتحقق منها.',
        ])->json('data.reference');

        $ticket = SupportTicket::query()->where('reference', $reference)->firstOrFail();

        $this->actingAs($this->platformOwner(), 'sanctum')
            ->patchJson("/api/v1/admin/legal/support-tickets/{$ticket->id}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertNotNull($ticket->fresh()->resolved_at);
    }

    // ── data requests, and the refusal that matters ───────────────────────────────────────────

    public function test_a_data_request_is_recorded_with_a_reference_and_audited(): void
    {
        $res = $this->postJson('/api/v1/data-requests', [
            'type' => 'export', 'name' => 'نورة', 'email' => 'noura@example.test',
        ])->assertOk();

        $this->assertMatchesRegularExpression('/^DR-[A-Z0-9]{6}$/', $res->json('data.reference'));
        $this->assertSame(1, AuditLog::query()->where('action', 'legal.data_request.submitted')->count());
    }

    /**
     * A deletion request from an unidentified sender does not read as ready to execute.
     *
     * It is the normal case from a public page, and it means the operator must first establish who
     * they are — not that there is nothing standing in the way.
     */
    public function test_a_deletion_request_with_no_account_is_blocked_pending_identification(): void
    {
        $res = $this->postJson('/api/v1/data-requests', [
            'type' => 'delete_account', 'name' => 'نورة', 'email' => 'noura@example.test',
        ])->assertOk();

        $this->assertSame('blocked', $res->json('data.status'));
        $this->assertSame('identity_unverified', $res->json('data.blockers.0.code'));
    }

    /** An export is not destructive, so it is not held behind the blocker check. */
    public function test_an_export_request_is_not_blocked(): void
    {
        $res = $this->postJson('/api/v1/data-requests', [
            'type' => 'export', 'name' => 'نورة', 'email' => 'noura@example.test',
        ])->assertOk();

        $this->assertSame('pending', $res->json('data.status'));
        $this->assertSame([], $res->json('data.blockers'));
    }

    /**
     * The refusal this whole table exists for.
     *
     * A deletion cannot destroy an accounting record the operator is obliged to keep — and it must
     * not be silently dropped either. The operator is told which invoices, and the request stays.
     */
    public function test_a_deletion_cannot_be_completed_while_an_invoice_is_open(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        $this->issueInvoice($tenant->id);

        $record = DataRequest::create([
            'reference' => DataRequest::makeReference(),
            'type' => 'delete_account', 'name' => 'نورة', 'email' => 'n@example.test',
            'status' => 'in_review', 'tenant_id' => $tenant->id,
        ]);

        $res = $this->actingAs($this->platformOwner(), 'sanctum')
            ->patchJson("/api/v1/admin/legal/data-requests/{$record->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->assertSame('open_invoices', $res->json('meta.blockers.0.code'));
        // Not discarded, and not completed — recorded as blocked, with the reason kept.
        $this->assertSame('blocked', $record->fresh()->status);
        $this->assertNotEmpty($record->fresh()->blockers);
    }

    /** Once nothing stands in the way, the same request completes and is audited. */
    public function test_a_deletion_completes_once_the_blockers_are_gone(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        $record = DataRequest::create([
            'reference' => DataRequest::makeReference(),
            'type' => 'delete_account', 'name' => 'نورة', 'email' => 'n@example.test',
            'status' => 'in_review', 'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($this->platformOwner(), 'sanctum')
            ->patchJson("/api/v1/admin/legal/data-requests/{$record->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($record->fresh()->completed_at);
        $this->assertNull($record->fresh()->blockers, 'a stale blocker list would read as current');
        $this->assertSame(1, AuditLog::query()->where('action', 'legal.data_request.completed')->count());
    }

    /**
     * The blockers are re-checked at completion, not trusted from submission.
     *
     * An invoice may have been raised since the request arrived. Completing on the strength of a
     * stale check would delete a workspace that acquired an obligation an hour ago.
     */
    public function test_blockers_are_re_evaluated_at_completion_rather_than_trusted_from_submission(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        // Submitted clean.
        $record = DataRequest::create([
            'reference' => DataRequest::makeReference(),
            'type' => 'delete_account', 'name' => 'ن', 'email' => 'n@example.test',
            'status' => 'pending', 'tenant_id' => $tenant->id, 'blockers' => null,
        ]);

        // …then an invoice appears.
        $this->issueInvoice($tenant->id);

        $this->actingAs($this->platformOwner(), 'sanctum')
            ->patchJson("/api/v1/admin/legal/data-requests/{$record->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->assertSame('blocked', $record->fresh()->status);
    }

    // ── the boundary ──────────────────────────────────────────────────────────────────────────

    /** These queues name people who are not customers; no tenant may read them. */
    public function test_a_tenant_user_cannot_read_the_operators_queues(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@tenant.test', 'password' => 'secret123', 'email_verified_at' => now()]);

        foreach (['contact-messages', 'support-tickets', 'data-requests'] as $queue) {
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/v1/admin/legal/{$queue}")
                ->assertForbidden();
        }
    }

    public function test_an_anonymous_caller_cannot_read_the_operators_queues(): void
    {
        $this->getJson('/api/v1/admin/legal/data-requests')->assertUnauthorized();
    }

    /**
     * An open invoice against a tenant — the blocker the deletion path has to respect.
     *
     * Written through the query builder with every NOT NULL column the real table demands, because
     * the point of the test is that the CHECK reads a real row: a fixture that skipped the required
     * columns would be testing a table this application does not have.
     */
    private function issueInvoice(string $tenantId): void
    {
        // The workspace autofills `tenant_id` from context, so the context has to be the one whose
        // invoice we are about to raise — otherwise the blocker check looks at a different tenant.
        $this->holdingTenant($tenantId);
        $workspace = ClientWorkspace::create([
            'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);
        app(TenantContext::class)->forget();

        DB::table('invoices')->insert([
            'tenant_id' => $tenantId,
            'client_workspace_id' => $workspace->id,
            'number' => 'INV-'.Str::upper(Str::random(6)),
            'currency' => 'SAR',
            'subtotal' => 100, 'tax' => 15, 'discount' => 0, 'total' => 115, 'amount_paid' => 0,
            'status' => 'issued',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function platformOwner(): User
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'legal-inbox@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }
}
