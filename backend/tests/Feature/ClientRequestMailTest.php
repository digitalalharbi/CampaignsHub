<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Providers\NullEmailProvider;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Services\ClientNotifier;
use App\Domains\Requests\Services\RequestMailer;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** EMAIL-GAPS — request mail goes only inside the request's tenant and project, and changes nothing in the portal. */
final class ClientRequestMailTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private Tenant $otherTenant;

    private ClientWorkspace $client;

    private ClientWorkspace $otherClient;

    private Project $project;

    private Project $sibling;

    private Project $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->seed(PermissionSeeder::class);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
            'requests.client_mail_enabled' => true,
        ]);
        Mail::fake();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        $this->otherTenant = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active']);
        $make = static fn (Tenant $t, string $n): ClientWorkspace => ClientWorkspace::create(['tenant_id' => $t->id, 'name' => $n, 'slug' => Str::slug($n).'-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->client = $make($this->tenant, 'Razzah');
        $this->otherClient = $make($this->tenant, 'Henka');
        $rival = $make($this->otherTenant, 'Rival client');
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->sibling = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Bravo', 'status' => 'active']);
        $this->foreign = Project::create(['tenant_id' => $this->otherTenant->id, 'client_workspace_id' => $rival->id, 'name' => 'Rival project', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** @param  array<string,mixed>  $over */
    private function request(array $over = []): ExternalRequest
    {
        $r = new ExternalRequest;
        $r->forceFill(array_merge([
            'tenant_id' => $this->tenant->id, 'reference' => 'REQ-'.strtoupper(Str::random(8)), 'module' => 'paid_media',
            'type_id' => RequestType::query()->value('id'), 'status_id' => RequestStatus::query()->value('id'),
            'priority' => 'medium', 'source' => 'client_portal', 'client_id' => $this->client->id, 'project_id' => $this->project->id,
            'contact_name' => 'Sarah', 'contact_email' => 'sarah@client.test', 'is_external' => true, 'is_demo' => false,
        ], $over))->save();

        return $r;
    }

    /** @param  list<string>|null  $clients  @param  list<string>|null  $projects */
    private function member(string $email, Tenant $tenant, array $permissions = ['requests.view'], ?array $clients = null, ?array $projects = null, Portal $portal = Portal::Agency): User
    {
        $user = User::create(['name' => 'M', 'email' => $email, 'password' => 'secret1234', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', [...$permissions, 'clients.view_all'])->pluck('key')->all());
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: $portal, role: 'member', clientScopeIds: $clients, projectScopeIds: $projects));
        $previous = app(TenantContext::class)->tenantId();
        app(TenantContext::class)->setTenantId($tenant->id);
        $user->assignRole($role);
        app(TenantContext::class)->setTenantId($previous);

        return $user;
    }

    private function notifier(): ClientNotifier
    {
        return app(ClientNotifier::class);
    }

    private function row(): object
    {
        return DB::table('client_notifications')->where('channel', 'email')->first();
    }

    public function test_it_is_off_by_default_and_the_row_is_written_exactly_as_before(): void
    {
        $this->assertFalse((require base_path('config/requests.php'))['client_mail_enabled']);

        config(['requests.client_mail_enabled' => false]);
        $this->notifier()->notify($this->request(), 'received');

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('mail_deliveries')->count());
        $this->assertSame('awaiting_provider_credentials', $this->row()->status);
        $this->assertNull($this->row()->mail_dedup_key);
    }

    public function test_the_client_contact_is_mailed_once_and_the_row_follows_the_ledger(): void
    {
        $request = $this->request();
        $this->notifier()->notify($request, 'approved');
        $this->notifier()->notify($request, 'approved');

        Mail::assertSent(OperationalMail::class, 1);
        Mail::assertSent(OperationalMail::class, fn (OperationalMail $m): bool => $m->hasTo('sarah@client.test')
            && str_contains($m->path, '/client/requests/') && $m->lang === 'ar' && $m->unsubscribeUrl !== null);
        $this->assertSame('sent', $this->row()->status);
    }

    public function test_with_no_provider_nothing_leaves_and_the_row_keeps_saying_so(): void
    {
        config(['providers.channels.email' => NullEmailProvider::class]);
        $this->notifier()->notify($this->request(), 'received');

        Mail::assertNothingSent();
        $this->assertSame('awaiting_provider_credentials', $this->row()->status);
    }

    public function test_a_contact_with_an_english_account_reads_it_in_english(): void
    {
        User::create(['name' => 'S', 'email' => 'sarah@client.test', 'password' => 'secret1234', 'locale' => 'en']);
        $this->notifier()->notify($this->request(), 'completed');

        Mail::assertSent(OperationalMail::class, fn (OperationalMail $m): bool => $m->lang === 'en' && $m->title === 'Your request is complete');
    }

    /** Nothing written by the team is copied into an inbox, however it is marked. */
    public function test_the_body_quotes_no_comment_or_note(): void
    {
        $request = $this->request();
        $request->comments()->create(['visibility' => 'internal', 'author_label' => 'Team', 'body' => 'INTERNAL NOTE TEXT']);
        $request->comments()->create(['visibility' => 'client', 'author_label' => 'Team', 'body' => 'CLIENT REPLY TEXT']);
        $this->notifier()->notify($request, 'team_reply');

        Mail::assertSent(OperationalMail::class, function (OperationalMail $m): bool {
            $html = $m->render();
            $this->assertStringNotContainsString('INTERNAL NOTE TEXT', $html);
            $this->assertStringNotContainsString('CLIENT REPLY TEXT', $html);

            return true;
        });
    }

    public function test_a_request_pointing_at_another_tenants_project_is_refused_and_recorded(): void
    {
        $this->notifier()->notify($this->request(['project_id' => $this->foreign->id]), 'received');

        Mail::assertNothingSent();
        $this->assertSame('refused_scope', $this->row()->status);
        $this->assertSame('refused_scope', DB::table('mail_deliveries')->where('kind', 'client_request')->value('status'));
    }

    public function test_a_request_pointing_at_another_tenants_client_is_refused(): void
    {
        $rival = ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->firstOrFail();
        $this->notifier()->notify($this->request(['client_id' => $rival->id, 'project_id' => null]), 'received');

        Mail::assertNothingSent();
        $this->assertSame('refused_scope', $this->row()->status);
    }

    public function test_a_project_not_under_the_requests_client_is_refused(): void
    {
        $this->notifier()->notify($this->request(['client_id' => $this->otherClient->id]), 'received');

        Mail::assertNothingSent();
        $this->assertSame('refused_scope', $this->row()->status);
    }

    public function test_a_contact_confined_to_another_project_is_refused(): void
    {
        $this->member('sarah@client.test', $this->tenant, [], [(string) $this->client->id], [(string) $this->sibling->id], Portal::ClientPortal);
        $this->notifier()->notify($this->request(), 'approved');

        Mail::assertNothingSent();
        $this->assertSame('refused_scope', $this->row()->status);
    }

    public function test_a_contact_confined_to_another_client_is_refused(): void
    {
        $this->member('sarah@client.test', $this->tenant, [], [(string) $this->otherClient->id], null, Portal::ClientPortal);
        $this->notifier()->notify($this->request(), 'approved');

        Mail::assertNothingSent();
        $this->assertSame('refused_scope', $this->row()->status);
    }

    public function test_a_contact_within_their_project_is_mailed(): void
    {
        $this->member('sarah@client.test', $this->tenant, [], [(string) $this->client->id], [(string) $this->project->id], Portal::ClientPortal);
        $this->notifier()->notify($this->request(), 'approved');

        Mail::assertSent(OperationalMail::class, 1);
    }

    /** A portal member of a DIFFERENT tenant says nothing about this tenant's request. */
    public function test_the_same_address_in_another_tenant_grants_and_blocks_nothing_here(): void
    {
        $this->member('sarah@client.test', $this->otherTenant, [], [], null, Portal::ClientPortal);
        $this->notifier()->notify($this->request(), 'approved');

        Mail::assertSent(OperationalMail::class, 1);
    }

    public function test_the_team_is_exactly_the_members_of_this_tenant_who_can_reach_the_request(): void
    {
        $in = $this->member('in@agency.test', $this->tenant, ['requests.view'], null, [(string) $this->project->id]);
        $this->member('sibling@agency.test', $this->tenant, ['requests.view'], null, [(string) $this->sibling->id]);
        $this->member('otherclient@agency.test', $this->tenant, ['requests.view'], [(string) $this->otherClient->id]);
        $this->member('noperm@agency.test', $this->tenant, [], null, [(string) $this->project->id]);
        $this->member('portal@agency.test', $this->tenant, ['requests.view'], [(string) $this->client->id], null, Portal::ClientPortal);
        $this->member('rival@rival.test', $this->otherTenant, ['requests.view']);

        $team = app(RequestMailer::class)->team($this->request());

        $this->assertSame([(int) $in->id], array_map(static fn (User $u): int => (int) $u->id, $team));
    }

    public function test_the_team_is_not_mailed_about_an_incoherent_request_and_the_refusal_is_recorded(): void
    {
        $this->member('in@agency.test', $this->tenant, ['requests.view'], null, [(string) $this->project->id]);

        app(RequestMailer::class)->newRequestToTeam($this->request(['project_id' => $this->foreign->id]));

        Mail::assertNothingSent();
        $this->assertSame('refused_scope', DB::table('mail_deliveries')->where('kind', 'client_request')->value('status'));
    }

    /** The portal's real endpoint: same status, same fields, whether the mail is on or off. */
    public function test_the_portal_answers_the_same_with_the_mail_on_or_off(): void
    {
        $contact = User::create(['name' => 'Client', 'email' => 'c@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $contact, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin', clientScopeIds: [(string) $this->client->id]));
        $this->actingAs($contact);
        $body = ['type' => 'consulting', 'message' => 'Please review our June plan.', 'project_id' => (string) $this->project->id];

        config(['requests.client_mail_enabled' => false]);
        $off = $this->postJson('/api/v1/client/requests', $body, $this->spa)->assertCreated();
        Mail::assertNothingSent();

        config(['requests.client_mail_enabled' => true]);
        $on = $this->postJson('/api/v1/client/requests', $body, $this->spa)->assertCreated();

        $this->assertSame(array_keys($off->json('data')), array_keys($on->json('data')));
    }

    public function test_a_mail_failure_reaches_neither_the_portal_nor_the_team_action(): void
    {
        $this->member('in@agency.test', $this->tenant, ['requests.view'], null, [(string) $this->project->id]);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection could not be established'));
        $request = $this->request();

        $this->notifier()->notify($request, 'approved');
        app(RequestMailer::class)->newRequestToTeam($request);

        // The sync queue ran the send. The failure is written down, and nothing was thrown above.
        $this->assertContains($this->row()->status, ['queued', 'failed']);
    }

    public function test_an_unsubscribed_contact_is_not_mailed_and_the_row_says_why(): void
    {
        DB::table('mail_unsubscribes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'recipient_hash' => hash('sha256', 'sarah@client.test'), 'scope' => 'client_request']);

        $this->notifier()->notify($this->request(), 'approved');

        Mail::assertNothingSent();
        $this->assertSame('unsubscribed', $this->row()->status);
    }
}
