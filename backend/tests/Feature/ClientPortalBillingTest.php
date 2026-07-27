<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\Billing\Services\BillingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Services\MessagingService;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client-facing portal for Billing / Messaging / Journey: a verified client sees ONLY their own workspace's
 * quotes, invoices and threads (fail-closed cross-client 404s), approving a quote issues an invoice, paying
 * with the Null provider is an honest `awaiting_provider_credentials` (nothing is marked paid), and a client
 * post is authored as `client`. Reuses the existing portal auth (X-Client-Token in non-production).
 */
final class ClientPortalBillingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /**
     * Provision a client: a workspace + one owned request tied to a verified contact.
     *
     * @return array{workspace: ClientWorkspace, request: ExternalRequest}
     */
    private function provisionClient(string $email, string $phone): array
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C '.$email, 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $request = ExternalRequest::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-2026-'.strtoupper(substr(md5($email), 0, 6)),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client',
            'contact_email' => $email,
            'contact_phone' => $phone,
            'client_id' => $workspace->id,
            'journey_stage' => 'awaiting_client_approval',
            'submitted_at' => now(),
        ]);

        return ['workspace' => $workspace, 'request' => $request];
    }

    /** Log into the portal via email OTP; return the raw dev token for the X-Client-Token header. */
    private function portalLogin(string $email): string
    {
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])->assertCreated();
        $verify = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->assertJsonPath('data.authenticated', true);

        return $verify->json('data.dev_token');
    }

    /** @return array<string,string> */
    private function auth(string $token): array
    {
        return ['X-Client-Token' => $token];
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    private function messaging(): MessagingService
    {
        return app(MessagingService::class);
    }

    private function quoteFor(ClientWorkspace $workspace, float $total = 1000, string $status = 'sent'): Quote
    {
        return $this->billing()->createQuote([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'subtotal' => $total, 'total' => $total, 'status' => $status,
        ]);
    }

    public function test_client_sees_only_their_own_quotes(): void
    {
        ['workspace' => $mine] = $this->provisionClient('owner@x.test', '+966500000001');
        ['workspace' => $theirs] = $this->provisionClient('stranger@x.test', '+966500000002');

        $mineQuote = $this->quoteFor($mine, 1500);
        $theirQuote = $this->quoteFor($theirs, 2500);

        $token = $this->portalLogin('owner@x.test');
        $res = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/quotes')->assertOk();

        $numbers = array_column($res->json('data.quotes'), 'number');
        $this->assertContains($mineQuote->number, $numbers);
        $this->assertNotContains($theirQuote->number, $numbers);

        // The stranger's quote is a non-revealing 404.
        $this->withHeaders($this->auth($token))->getJson("/api/v1/client/quotes/{$theirQuote->id}")->assertNotFound();
        $this->withHeaders($this->auth($token))->getJson("/api/v1/client/quotes/{$mineQuote->id}")
            ->assertOk()->assertJsonPath('data.quote.number', $mineQuote->number);
    }

    public function test_approving_a_quote_issues_an_invoice(): void
    {
        ['workspace' => $mine] = $this->provisionClient('owner@x.test', '+966500000001');
        $quote = $this->quoteFor($mine, 3000);

        $token = $this->portalLogin('owner@x.test');
        $res = $this->withHeaders($this->auth($token))
            ->postJson("/api/v1/client/quotes/{$quote->id}/approve")->assertCreated();

        $res->assertJsonPath('data.quote.status', 'approved')
            ->assertJsonPath('data.invoice.status', 'issued');

        $this->assertSame('approved', $quote->refresh()->status);
        $this->assertSame(1, Invoice::where('quote_id', $quote->id)->count());
    }

    public function test_client_can_reject_an_open_quote(): void
    {
        ['workspace' => $mine] = $this->provisionClient('owner@x.test', '+966500000001');
        $quote = $this->quoteFor($mine, 3000);

        $token = $this->portalLogin('owner@x.test');
        $this->withHeaders($this->auth($token))->postJson("/api/v1/client/quotes/{$quote->id}/reject")
            ->assertOk()->assertJsonPath('data.quote.status', 'rejected');

        $this->assertSame('rejected', $quote->refresh()->status);
        $this->assertSame(0, Invoice::where('quote_id', $quote->id)->count());
    }

    public function test_paying_with_the_null_provider_awaits_credentials_and_marks_nothing_paid(): void
    {
        ['workspace' => $mine] = $this->provisionClient('owner@x.test', '+966500000001');
        $invoice = $this->billing()->approveQuote($this->quoteFor($mine, 1000));

        $token = $this->portalLogin('owner@x.test');
        $res = $this->withHeaders($this->auth($token))
            ->postJson("/api/v1/client/invoices/{$invoice->id}/pay")->assertCreated();

        $res->assertJsonPath('data.payment.payment_state', 'awaiting_provider_credentials')
            ->assertJsonPath('data.payment.status', 'pending');

        // The invoice is untouched — nothing was marked paid.
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame('0.00', (string) $invoice->amount_paid);
    }

    public function test_client_sees_only_their_own_invoices(): void
    {
        ['workspace' => $mine] = $this->provisionClient('owner@x.test', '+966500000001');
        ['workspace' => $theirs] = $this->provisionClient('stranger@x.test', '+966500000002');

        $mineInvoice = $this->billing()->approveQuote($this->quoteFor($mine, 1000));
        $theirInvoice = $this->billing()->approveQuote($this->quoteFor($theirs, 2000));

        $token = $this->portalLogin('owner@x.test');
        $res = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/invoices')->assertOk();

        $numbers = array_column($res->json('data.invoices'), 'number');
        $this->assertContains($mineInvoice->number, $numbers);
        $this->assertNotContains($theirInvoice->number, $numbers);

        $this->withHeaders($this->auth($token))->getJson("/api/v1/client/invoices/{$theirInvoice->id}")->assertNotFound();
    }

    public function test_client_messages_are_scoped_and_posting_creates_a_client_authored_message(): void
    {
        ['workspace' => $mine] = $this->provisionClient('owner@x.test', '+966500000001');
        ['workspace' => $theirs] = $this->provisionClient('stranger@x.test', '+966500000002');

        $mineThread = $this->messaging()->openThread(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $mine->id, 'subject' => 'Mine']);
        $this->messaging()->postMessage($mineThread, 'team', 'Hello from the team');
        $theirThread = $this->messaging()->openThread(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $theirs->id, 'subject' => 'Theirs']);

        $token = $this->portalLogin('owner@x.test');

        // Only the client's own thread is listed.
        $res = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/messages')->assertOk();
        $ids = array_column($res->json('data.threads'), 'id');
        $this->assertContains($mineThread->id, $ids);
        $this->assertNotContains($theirThread->id, $ids);

        // Another client's thread is a 404, and cannot be posted to.
        $this->withHeaders($this->auth($token))->getJson("/api/v1/client/messages/{$theirThread->id}")->assertNotFound();
        $this->withHeaders($this->auth($token))->postJson("/api/v1/client/messages/{$theirThread->id}", ['body' => 'sneaky'])->assertNotFound();

        // Posting into the owned thread creates a client-authored message.
        $this->withHeaders($this->auth($token))->postJson("/api/v1/client/messages/{$mineThread->id}", ['body' => 'Any update please?'])
            ->assertCreated()->assertJsonPath('data.message.author_type', 'client');
        $this->assertDatabaseHas('messages', ['thread_id' => $mineThread->id, 'author_type' => 'client', 'body' => 'Any update please?']);
    }

    public function test_client_can_open_a_thread(): void
    {
        ['workspace' => $mine] = $this->provisionClient('owner@x.test', '+966500000001');

        $token = $this->portalLogin('owner@x.test');
        $res = $this->withHeaders($this->auth($token))
            ->postJson('/api/v1/client/messages', ['subject' => 'Question', 'body' => 'When do we start?'])
            ->assertCreated();

        $threadId = $res->json('data.thread.id');
        $this->assertDatabaseHas('message_threads', ['id' => $threadId, 'client_workspace_id' => $mine->id]);
        $this->assertDatabaseHas('messages', ['thread_id' => $threadId, 'author_type' => 'client']);
    }

    public function test_journey_exposes_stage_and_client_actions_and_is_scoped(): void
    {
        ['request' => $mineReq] = $this->provisionClient('owner@x.test', '+966500000001');
        ['request' => $theirReq] = $this->provisionClient('stranger@x.test', '+966500000002');

        $token = $this->portalLogin('owner@x.test');
        $res = $this->withHeaders($this->auth($token))->getJson("/api/v1/client/requests/{$mineReq->reference}/journey")->assertOk();

        $res->assertJsonPath('data.stage', 'awaiting_client_approval');
        $actions = array_column($res->json('data.client_actions'), 'key');
        $this->assertContains('approve_quote', $actions);
        $this->assertContains('reject_quote', $actions);

        // Another client's request journey is a 404.
        $this->withHeaders($this->auth($token))->getJson("/api/v1/client/requests/{$theirReq->reference}/journey")->assertNotFound();
    }

    public function test_billing_and_messaging_require_a_session(): void
    {
        $this->getJson('/api/v1/client/quotes')->assertUnauthorized();
        $this->getJson('/api/v1/client/invoices')->assertUnauthorized();
        $this->getJson('/api/v1/client/messages')->assertUnauthorized();
    }
}
