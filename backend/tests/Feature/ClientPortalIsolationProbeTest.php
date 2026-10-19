<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CP-ISOLATION-001 — the cross-endpoint probe.
 *
 * Two clients of one agency, every record of the second carrying a marker the first must never read.
 * The first client signs in and every client-portal GET is called:
 *
 *  - the endpoints that take no id must return nothing carrying the other client's marker;
 *  - the endpoints that take an id are given the OTHER client's ids and must answer 404 or 403.
 *
 * The third test is the one that keeps this honest over time: it lists every GET under `/client` and
 * fails when a route exists that this probe does not know. A new endpoint therefore cannot ship without
 * being added here, which is how isolation stays a property of the whole surface rather than of the
 * endpoints somebody remembered to test.
 */
final class ClientPortalIsolationProbeTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'BETA-SECRET';

    /** @var list<string> every GET under /client the probe covers, written as the route is */
    private const COVERED = [
        'api/v1/client/branding', 'api/v1/client/branding/logo', 'api/v1/client/campaigns', 'api/v1/client/files',
        'api/v1/client/invoices', 'api/v1/client/invoices/{invoice}', 'api/v1/client/invoices/{invoice}/pdf',
        'api/v1/client/messages', 'api/v1/client/messages/{thread}', 'api/v1/client/overview',
        'api/v1/client/quotes', 'api/v1/client/quotes/{quote}', 'api/v1/client/reports', 'api/v1/client/requests',
        'api/v1/client/requests/{reference}', 'api/v1/client/requests/{reference}/files/{file}',
        'api/v1/client/requests/{reference}/journey', 'api/v1/client/session', 'api/v1/client/spaces', 'api/v1/client/visibility',
    ];

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private ClientWorkspace $alpha;

    private ClientWorkspace $beta;

    /** @var array<string, string> the other client's ids by kind */
    private array $theirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->alpha = $this->client('Alpha', 'alpha');
        $this->beta = $this->client(self::MARKER.' Co', 'beta');

        // Alpha has records of its own, so «nothing came back» cannot pass for «nothing exists».
        $this->records($this->alpha, 'Alpha');
        $this->theirs = $this->records($this->beta, self::MARKER);
    }

    private function client(string $name, string $slug): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => $slug, 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    /** @return array<string, string> */
    private function records(ClientWorkspace $w, string $label): array
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $w->id, 'name' => $label.' project', 'status' => 'active']);
        UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $w->id, 'name' => $label.' campaign', 'objective' => 'conversions', 'status' => 'active']);
        $report = Report::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $label.' report', 'type' => 'executive', 'audience' => 'client', 'status' => 'completed']);
        ReportShare::create(['tenant_id' => $this->tenant->id, 'report_id' => $report->id, 'token_hash' => hash('sha256', Str::random(48)), 'allow_download' => true]);
        $invoice = Invoice::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $w->id, 'number' => $label.'-INV-1', 'currency' => 'SAR', 'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'amount_paid' => 0, 'status' => 'issued', 'due_date' => '2026-12-01']);
        $quote = Quote::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $w->id, 'number' => $label.'-QUO-1', 'currency' => 'SAR', 'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'status' => 'sent']);
        $thread = MessageThread::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $w->id, 'subject' => $label.' thread', 'status' => 'open']);
        $request = ExternalRequest::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'REQ-2026-'.strtoupper(substr(md5($label), 0, 6)),
            'type_id' => RequestType::query()->firstOrFail()->id, 'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => $label, 'contact_email' => strtolower(substr($label, 0, 5)).'@x.test', 'client_id' => $w->id, 'project_id' => $project->id,
            'objective' => $label.' objective', 'journey_stage' => 'in_progress', 'submitted_at' => now(),
        ]);

        return ['invoice' => (string) $invoice->id, 'quote' => (string) $quote->id, 'thread' => (string) $thread->id, 'reference' => $request->reference];
    }

    private function signInAsAlpha(): void
    {
        $user = User::create(['name' => 'Alpha user', 'email' => 'alpha-user@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin',
            clientScopeIds: [(string) $this->alpha->id],
        ));
        $this->actingAs($user);
    }

    public function test_no_parameterless_endpoint_returns_anything_of_the_other_client(): void
    {
        $this->signInAsAlpha();

        foreach (['campaigns', 'files', 'invoices', 'messages', 'overview', 'quotes', 'reports', 'requests', 'spaces', 'visibility', 'branding', 'session'] as $path) {
            $res = $this->getJson("/api/v1/client/{$path}", $this->spa);

            $this->assertContains($res->status(), [200, 403, 404], "/client/{$path} answered {$res->status()}");
            $this->assertStringNotContainsString(self::MARKER, $res->getContent(), "/client/{$path} carried the other client's data");
            $this->assertStringNotContainsString($this->theirs['reference'], $res->getContent(), "/client/{$path} named the other client's request");
        }

        // The control: the probe can see Alpha's own records, so an empty answer is not a false pass.
        $this->assertStringContainsString('Alpha campaign', $this->getJson('/api/v1/client/campaigns', $this->spa)->getContent());
        $this->assertStringContainsString('Alpha-INV-1', $this->getJson('/api/v1/client/invoices', $this->spa)->getContent());
    }

    public function test_every_endpoint_that_takes_an_id_refuses_the_other_clients_id(): void
    {
        $this->signInAsAlpha();
        $t = $this->theirs;

        $probes = [
            "invoices/{$t['invoice']}", "invoices/{$t['invoice']}/pdf", "messages/{$t['thread']}", "quotes/{$t['quote']}",
            "requests/{$t['reference']}", "requests/{$t['reference']}/journey", "requests/{$t['reference']}/files/1",
        ];

        foreach ($probes as $path) {
            $status = $this->getJson("/api/v1/client/{$path}", $this->spa)->status();
            $this->assertContains($status, [403, 404], "/client/{$path} answered {$status} to a client that does not own it");
        }
    }

    public function test_the_probe_knows_every_client_get_endpoint(): void
    {
        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r): bool => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'api/v1/client/'))
            ->map(fn ($r): string => $r->uri())->sort()->values()->all();
        $covered = self::COVERED;
        sort($covered);

        $this->assertSame($covered, $actual, 'a /client GET endpoint exists that the isolation probe does not cover — add it');
    }
}
