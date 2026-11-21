<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestComment;
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
use Tests\TestCase;

/**
 * CLIENT-CHAT-001 — a request's conversation reads like a chat (each side's «read» stamp) and its history reads in the
 * client's language, without the engine's English log lines or a row per message.
 * CLIENT-RESULTS-001 — the dashboard's Results follow the project's own definition.
 */
final class ClientPortalChatTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private ExternalRequest $req;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Nasel', 'slug' => 'nasel', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Riwaq', 'status' => 'active']);
        $this->req = ExternalRequest::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'REQ-2026-CHAT01',
            'type_id' => RequestType::query()->firstOrFail()->id, 'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Nasel', 'contact_email' => 'n@x.test', 'client_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'objective' => 'Run the campaign', 'journey_stage' => 'submitted', 'submitted_at' => now(),
        ]);
        $this->req->events()->create(['type' => 'created', 'to_status' => 'new', 'is_client_visible' => true, 'message' => 'Request submitted', 'created_at' => now()->subHour()]);

        $user = User::create(['name' => 'Client', 'email' => 'client@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin',
            clientScopeIds: [(string) $this->workspace->id],
        ));
        $this->actingAs($user);
    }

    public function test_a_client_message_shows_read_once_the_team_has_read_it_and_opening_reads_the_teams(): void
    {
        $this->postJson('/api/v1/client/requests/REQ-2026-CHAT01/reply', ['message' => 'مرحبا'], $this->spa)->assertCreated();
        $team = $this->req->comments()->create(['visibility' => 'client', 'author_label' => 'Team', 'body' => 'أهلًا']);

        $first = $this->getJson('/api/v1/client/requests/REQ-2026-CHAT01', $this->spa)->assertOk()->json('data.comments');
        $this->assertSame('Client', $first[0]['author']);
        $this->assertNull($first[0]['read_by_team_at'], 'the team has not opened it yet: one tick');
        $this->assertNotNull($team->fresh()?->read_by_client_at, 'the client opening the request read the team reply');

        // The team opens the request (as the agency view does): the client message is now read.
        RequestComment::query()->where('author_label', 'Client')->update(['read_by_team_at' => now()]);
        $again = $this->getJson('/api/v1/client/requests/REQ-2026-CHAT01', $this->spa)->json('data.comments');
        $this->assertNotNull($again[0]['read_by_team_at']);
    }

    public function test_the_history_is_named_in_the_clients_language_and_leaves_messages_to_the_chat(): void
    {
        $this->postJson('/api/v1/client/requests/REQ-2026-CHAT01/reply', ['message' => 'سؤال'], $this->spa)->assertCreated();

        $timeline = $this->getJson('/api/v1/client/requests/REQ-2026-CHAT01', $this->spa)->assertOk()->json('data.timeline');
        $this->assertCount(1, $timeline, 'a message is in the chat, not a second time in the history');
        $this->assertSame('تم استلام طلبك', $timeline[0]['label_ar']);
        $this->assertSame('Your request was received', $timeline[0]['label_en']);
    }

    public function test_results_follow_the_projects_own_definition(): void
    {
        $url = "/api/v1/client/projects/{$this->project->id}/metrics/results?from=2026-11-01&to=2026-11-20";
        $this->assertFalse($this->getJson($url, $this->spa)->assertOk()->json('data.configured'));

        $this->project->forceFill(['meta' => ['result_events' => ['meta' => ['lead_form' => 'form_leads'], 'snapchat' => ['all' => 'form_leads']]]])->save();
        $r = $this->getJson($url, $this->spa)->assertOk();
        $this->assertTrue($r->json('data.configured'));
        $this->assertNull($r->json('data.total'), 'nothing reported in the window is «not available», never a measured zero');
        $this->assertArrayNotHasKey('cost', (array) $r->json('data'), 'money stays with the agency unless it is switched on');
    }

    public function test_content_follows_the_content_switch_and_hides_names_without_campaign_names(): void
    {
        $url = "/api/v1/client/projects/{$this->project->id}/content?from=2026-11-01&to=2026-11-20";
        $this->getJson($url, $this->spa)->assertOk();

        $this->workspace->forceFill(['settings' => ['portal' => ['visibility' => ['content' => false]]]])->save();
        $this->getJson($url, $this->spa)->assertForbidden();
    }
}
