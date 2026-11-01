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
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CP-ISOLATION-001 — the WRITE half of the client-portal isolation probe.
 *
 * `ClientPortalIsolationProbeTest` signs in as one client and reads every `/client` GET. This signs in as the same client and WRITES to every
 * `/client` endpoint with the OTHER client's ids — pay, approve, reject, reply, open a thread, file a request against their project or
 * their invoice, invite into their workspace, remove their member — and asserts two things for each: the answer is a refusal, and the other
 * client's records are exactly what they were. A refusal that still changed something would pass the first test and be the worst bug here.
 * A control proves the probe can change Alpha's OWN records, so a refusal is not just a broken harness; and a guard fails if a `/client`
 * write route exists that the probe does not cover.
 */
final class ClientPortalWriteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'BETA-SECRET';

    /** @var list<string> every GET under /client the probe covers, written as the route is */
    private const COVERED = [
        'api/v1/client/invoices/{invoice}/pay', 'api/v1/client/messages', 'api/v1/client/messages/{thread}',
        'api/v1/client/quotes/{quote}/approve', 'api/v1/client/quotes/{quote}/reject', 'api/v1/client/requests',
        'api/v1/client/requests/{reference}/reply', 'api/v1/client/team', 'api/v1/client/team/{membership}',
    ];

    /** Session endpoints that carry no client id at all. */
    private const SESSION_ONLY = ['api/v1/client/login/start', 'api/v1/client/login/verify', 'api/v1/client/logout'];

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private ClientWorkspace $alpha;

    private ClientWorkspace $beta;

    /** @var array<string, string> the other client's ids by kind */
    private array $theirs = [];

    /** @var array<string, string> the caller's own ids by kind */
    private array $ownIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->alpha = $this->client('Alpha', 'alpha');
        $this->beta = $this->client(self::MARKER.' Co', 'beta');

        // Alpha has records of its own, so «nothing came back» cannot pass for «nothing exists».
        $this->ownIds = $this->records($this->alpha, 'Alpha');
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
        // An ADMIN of the client's portal is one an agency operator granted (`invited_by`), and the team endpoints also need the agency's
        // `team_admin` grant on the space — both are set, so the team probes below are refused for ISOLATION and not for a missing role.
        $staff = User::create(['name' => 'Agency staff', 'email' => 'staff@x.test', 'password' => 'secret123']);
        foreach ([$this->alpha, $this->beta] as $space) {
            $space->forceFill(['settings' => ['portal' => ['capabilities' => ['team_admin' => true, 'create_requests' => true]]]])->save();
        }
        $user = User::create(['name' => 'Alpha user', 'email' => 'alpha-user@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin',
            clientScopeIds: [(string) $this->alpha->id], grantedBy: $staff,
        ));
        $this->actingAs($user);
    }

    private function betaState(): array
    {
        return [
            'invoice' => Invoice::query()->whereKey($this->theirs['invoice'])->first()->only(['status', 'amount_paid']),
            'payments' => DB::table('payments')->count(),
            'quote' => Quote::query()->whereKey($this->theirs['quote'])->value('status'),
            'invoices_beta' => Invoice::query()->where('client_workspace_id', $this->beta->id)->count(),
            'threads_beta' => MessageThread::query()->where('client_workspace_id', $this->beta->id)->count(),
            'messages_in_their_thread' => DB::table('messages')->where('thread_id', $this->theirs['thread'])->count(),
            'requests_beta' => ExternalRequest::query()->where('client_id', $this->beta->id)->count(),
            'comments_on_their_request' => DB::table('request_comments')->whereIn('request_id', ExternalRequest::query()->where('reference', $this->theirs['reference'])->pluck('id'))->count(),
            'memberships' => Membership::query()->where('tenant_id', $this->tenant->id)->count(),
        ];
    }

    public function test_every_write_with_the_other_clients_ids_is_refused_and_changes_nothing_of_theirs(): void
    {
        $this->signInAsAlpha();
        $t = $this->theirs;
        $betaProject = (string) Project::query()->where('client_workspace_id', $this->beta->id)->value('id');
        $betaMembership = $this->betaMembership();
        $before = $this->betaState();

        $attempts = [
            ['POST', "invoices/{$t['invoice']}/pay", []],
            ['POST', "quotes/{$t['quote']}/approve", []],
            ['POST', "quotes/{$t['quote']}/reject", []],
            ['POST', "messages/{$t['thread']}", ['body' => 'hello from Alpha']],
            ['POST', "requests/{$t['reference']}/reply", ['message' => 'hello from Alpha']],
            ['POST', 'requests', ['type' => RequestType::query()->value('key'), 'message' => 'about your project', 'project_id' => $betaProject]],
            ['POST', 'requests', ['type' => RequestType::query()->value('key'), 'message' => 'about your invoice', 'related' => ['kind' => 'invoice', 'id' => $t['invoice']]]],
            ['POST', 'team', ['client_workspace_id' => (string) $this->beta->id, 'email' => 'intruder@x.test', 'role' => 'client_viewer']],
            ['DELETE', 'team/'.$betaMembership, []],
        ];

        foreach ($attempts as [$method, $path, $body]) {
            $status = $this->json($method, "/api/v1/client/{$path}", $body, $this->spa)->status();
            $this->assertContains($status, [403, 404, 422], "{$method} /client/{$path} answered {$status} to a client acting on another's records");
        }

        $this->assertSame($before, $this->betaState(), 'a write the portal refused still changed the other client\'s records');
        $this->assertSame(0, DB::table('workspace_invitations')->where('email', 'intruder@x.test')->count(), 'an invitation into the other client\'s workspace was created');
    }

    /** A thread opened with the other client's workspace id is anchored to the caller's OWN workspace — never to theirs. */
    public function test_opening_a_thread_with_the_other_clients_workspace_never_creates_one_in_it(): void
    {
        $this->signInAsAlpha();
        $beforeBeta = MessageThread::query()->where('client_workspace_id', $this->beta->id)->count();

        $this->postJson('/api/v1/client/messages', ['subject' => 'Hello', 'body' => 'x', 'client_workspace_id' => (string) $this->beta->id], $this->spa)->assertCreated();

        $this->assertSame($beforeBeta, MessageThread::query()->where('client_workspace_id', $this->beta->id)->count());
        $this->assertTrue(MessageThread::query()->where('client_workspace_id', $this->alpha->id)->where('subject', 'Hello')->exists(), 'the thread was not anchored to the caller\'s own workspace');
    }

    /** The control: the same writes on Alpha's OWN records go through, so a refusal above is a refusal and not a broken harness. */
    public function test_the_same_writes_on_the_callers_own_records_are_accepted(): void
    {
        $this->signInAsAlpha();
        $own = $this->ownIds;

        $this->postJson("/api/v1/client/messages/{$own['thread']}", ['body' => 'hello own'], $this->spa)->assertCreated();
        $this->assertSame(1, DB::table('messages')->where('thread_id', $own['thread'])->count());

        // Filing a request about the caller's OWN project works with the capability on — so the refusal above, for THEIR project and THEIR
        // invoice, is isolation and not a closed door.
        $ownProject = (string) Project::query()->where('client_workspace_id', $this->alpha->id)->value('id');
        $this->postJson('/api/v1/client/requests', ['type' => RequestType::query()->value('key'), 'message' => 'about my project', 'project_id' => $ownProject], $this->spa)->assertSuccessful();
        $this->postJson('/api/v1/client/requests', ['type' => RequestType::query()->value('key'), 'message' => 'about my invoice', 'related' => ['kind' => 'invoice', 'id' => $own['invoice']]], $this->spa)->assertSuccessful();

        // Likewise the team: an invitation into the caller's own workspace is accepted.
        $this->postJson('/api/v1/client/team', ['client_workspace_id' => (string) $this->alpha->id, 'email' => 'colleague@x.test', 'role' => 'client_viewer'], $this->spa)->assertSuccessful();

        $this->postJson("/api/v1/client/requests/{$own['reference']}/reply", ['message' => 'hello own request'], $this->spa)->assertSuccessful();
        $this->assertSame(1, DB::table('request_comments')->whereIn('request_id', ExternalRequest::query()->where('reference', $own['reference'])->pluck('id'))->where('visibility', 'client')->count());
    }

    /** CP-REQ-ONE-001 — a client files a NEW request with a file: uploaded into a temporary session, claimed by the request. */
    public function test_a_new_request_carries_the_files_uploaded_for_it(): void
    {
        Storage::fake((string) config('requests.uploads.disk', 'local'));
        $this->signInAsAlpha();
        $token = (string) $this->postJson('/api/v1/requests/uploads/start', [], $this->spa)->assertCreated()->json('data.upload_token');
        $this->post('/api/v1/requests/uploads', ['upload_token' => $token, 'file' => UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf')],
            $this->spa + ['Accept' => 'application/json'])->assertCreated();

        $ref = (string) $this->postJson('/api/v1/client/requests', ['type' => RequestType::query()->value('key'), 'message' => 'brief attached', 'upload_token' => $token], $this->spa)
            ->assertCreated()->assertJsonPath('data.files', 1)->json('data.reference');

        $req = ExternalRequest::withoutGlobalScopes()->where('reference', $ref)->firstOrFail();
        $this->assertSame((string) $this->alpha->id, (string) $req->client_id, 'filed in the session\'s own client space');
        $this->assertSame(['brief.pdf'], DB::table('request_files')->where('request_id', $req->id)->where('is_client_visible', true)->pluck('original_name')->all());
        $this->assertSame(0, DB::table('request_upload_sessions')->count(), 'the session is spent');
    }

    public function test_the_write_probe_knows_every_client_write_endpoint(): void
    {
        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r): bool => array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $r->methods()) !== [] && str_starts_with($r->uri(), 'api/v1/client/'))
            ->map(fn ($r): string => $r->uri())->unique()->sort()->values()->all();
        $expected = array_merge(self::COVERED, self::SESSION_ONLY);
        sort($expected);

        $this->assertSame($expected, $actual, 'a /client write endpoint exists that the isolation probe does not cover — add it');
    }

    private function betaMembership(): string
    {
        $user = User::create(['name' => 'Beta user', 'email' => 'beta-user@x.test', 'password' => 'secret123']);

        return (string) app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin',
            clientScopeIds: [(string) $this->beta->id],
        ))->getKey();
    }
}
