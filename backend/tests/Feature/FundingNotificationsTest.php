<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingNotifier;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * FIN-LOOP-001 part 2 — the right people hear about a project budget, once per cycle, and an ad
 * account feeds one budget only. Gate F11 (scoped recipients) and the Owner's decision of 2026-11-13.
 */
final class FundingNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $finance;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = $this->tenant('A');
        $this->finance = $this->user($this->tenant, ['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'clients.create', 'projects.view.all']);
        $this->manager = $this->user($this->tenant, ['budget.view', 'clients.view', 'clients.view_all', 'projects.view', 'projects.view.all']);
        $this->holdingTenant((string) $this->tenant->id);
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
    }

    /** @param list<string> $permissions */
    private function user(Tenant $tenant, array $permissions): User
    {
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', $permissions)->pluck('key')->all());
        $user = User::create(['name' => 'U '.uniqid(), 'email' => 'u-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }

    private function project(string $client = 'Club', string $name = 'Launch'): Project
    {
        $c = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $client, 'slug' => Str::slug($client).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $c->id, 'name' => $name,
            'status' => 'active', 'account_manager_id' => $this->manager->id]);
    }

    private function day(int $ago): string
    {
        return Carbon::today('Asia/Riyadh')->subDays($ago)->toDateString();
    }

    private function spend(Project $project, string $account, int $ago, float $amount): void
    {
        DailyMetric::query()->updateOrCreate(
            ['project_id' => $project->id, 'external_account_id' => $account, 'external_campaign_id' => $account,
                'metric_key' => 'spend', 'metric_date' => $this->day($ago), 'attribution_window' => 'default'],
            ['provider' => 'snapchat', 'value' => $amount, 'original_amount' => $amount, 'original_currency' => 'SAR', 'project_currency' => 'SAR'],
        );
    }

    private function add(Project $project, float $amount, int $ago = 0)
    {
        return $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', [
            'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'amount' => $amount, 'paid_on' => $this->day($ago), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('funding:refresh')->assertSuccessful();
        $this->holdingTenant((string) $this->tenant->id);
    }

    /** @return list<string> entity types of the budget notices a user holds */
    private function notices(User $user, bool $openOnly = false): array
    {
        return AppNotification::query()->where('user_id', $user->id)->where('type', FundingNotifier::TYPE)
            ->when($openOnly, fn ($q) => $q->whereNull('resolved_at'))
            ->orderBy('created_at')->pluck('entity_type')->all();
    }

    public function test_an_added_budget_reaches_the_project_manager_and_team_not_the_person_who_added_it(): void
    {
        $project = $this->project();
        $member = $this->user($this->tenant, ['budget.view', 'clients.view', 'clients.view_all', 'projects.view']);
        ProjectMembership::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'user_id' => $member->id, 'role' => 'manager', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $this->add($project, 10_000)->assertCreated();

        $this->assertSame(['funding:added'], $this->notices($this->manager));
        $this->assertSame(['funding:added'], $this->notices($member));
        $this->assertSame([], $this->notices($this->finance));
        $n = AppNotification::query()->where('user_id', $this->manager->id)->firstOrFail();
        $this->assertStringContainsString('Club', (string) $n->title_ar);
        $this->assertStringContainsString('10,000 ر.س', (string) $n->message_ar);
        $this->assertSame('/budgets?project='.$project->id, $n->action_url);
        $this->assertSame(2, DB::table('notification_deliveries')->where('channel', 'email')->count(), 'the mail half is decided for each person');
    }

    public function test_running_low_is_said_once_per_cycle_and_a_renewal_closes_it(): void
    {
        $project = $this->project();
        $account = (string) Str::uuid();
        $this->add($project, 5_000, 20)->assertCreated();
        $this->spend($project, $account, 15, 3_500);   // 1,500 left: under the 2,000 line

        $this->sweep();
        $this->sweep();
        $this->assertSame(['funding:low'], $this->notices($this->finance), 'said once, however often the spend is read');
        $this->assertSame(['funding:added', 'funding:low'], $this->notices($this->manager));

        $this->add($project, 10_000)->assertCreated();
        $this->assertSame([], array_values(array_filter($this->notices($this->manager, true), fn ($t) => $t === 'funding:low')), 'closed by the renewal');

        $this->spend($project, $account, 2, 10_000);   // 1,500 left again, new cycle
        $this->sweep();
        $this->assertSame(['funding:low', 'funding:low'], $this->notices($this->finance));
    }

    public function test_a_short_runway_counts_as_running_low_and_over_outranks_it(): void
    {
        $project = $this->project();
        $account = (string) Str::uuid();
        $this->add($project, 20_000, 20)->assertCreated();
        foreach (range(1, 7) as $ago) {
            $this->spend($project, $account, $ago, 2_500); // 2,500 left at 2,500 a day: one day
        }
        ProjectFunding::query()->update(['alert_floor' => 0]);
        $this->sweep();
        $this->assertSame(['funding:low'], $this->notices($this->finance));
        $this->assertStringContainsString('يكفي تقريبًا 1 يوم', (string) AppNotification::query()->where('user_id', $this->finance->id)->value('message_ar'));

        $this->spend($project, $account, 0, 4_000);
        $this->sweep();
        $this->sweep();
        $this->assertSame(['funding:low', 'funding:over'], $this->notices($this->finance));
    }

    public function test_only_people_who_reach_the_client_hear_about_it(): void
    {
        $project = $this->project('Mine');
        $other = $this->project('Theirs');
        $scopedFinance = $this->user($this->tenant, ['funding.manage', 'clients.view']);
        DB::table('client_workspace_user')->insert(['client_workspace_id' => $other->client_workspace_id, 'user_id' => $scopedFinance->id, 'created_at' => now(), 'updated_at' => now()]);
        $withheld = $this->user($this->tenant, ['funding.manage', 'clients.view', 'clients.view_all', 'projects.view']);
        ProjectMembership::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'user_id' => $withheld->id,
            'role' => 'viewer', 'status' => 'active', 'denied' => ['money.view']]);
        $elsewhere = $this->user($this->tenant('B'), ['funding.manage', 'clients.view', 'clients.view_all']);
        $this->holdingTenant((string) $this->tenant->id);

        $this->add($project, 1_000, 10)->assertCreated();
        $this->spend($project, (string) Str::uuid(), 5, 900);
        $this->sweep();

        $this->assertSame(['funding:low'], $this->notices($this->finance));
        $this->assertSame([], $this->notices($scopedFinance), 'finance for another client only');
        $this->assertSame([], $this->notices($withheld), 'a membership that withholds money');
        $this->assertSame([], $this->notices($elsewhere), 'another agency');
    }

    public function test_an_account_feeds_one_project_budget_at_a_time(): void
    {
        $first = $this->project('Club', 'One');
        $second = $this->project('Club B', 'Two');
        $conn = ProviderConnection::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'credential_id' => $this->credential(),
            'provider' => 'snapchat', 'connection_name' => 'S', 'status' => 'connected']);
        $account = ExternalAccount::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $conn->id,
            'provider' => 'snapchat', 'account_type' => 'ad_account', 'external_id' => 'acc-1', 'name' => 'Snap 1', 'currency' => 'SAR']);
        $bind = fn (Project $p) => ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $p->id,
            'client_workspace_id' => $p->client_workspace_id, 'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => true]);

        $bind($first);
        $this->add($first, 5_000)->assertCreated();

        // Reporting on another project without a budget is still allowed…
        $bind($second);
        // …but that project cannot start a budget while the account feeds the first one.
        $this->add($second, 5_000)->assertUnprocessable()->assertJsonValidationErrors('project_id');

        // A renewal on the first project is never blocked by the rule.
        $this->add($first, 1_000)->assertCreated();

        // And a third project that already tracks a budget cannot take the account.
        $third = $this->project('Club C', 'Three');
        $this->add($third, 1_000)->assertCreated();
        try {
            $bind($third);
            $this->fail('one account fed two budgets');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('One', (string) collect($e->errors())->flatten()->first());
        }
    }

    private function credential(): string
    {
        $id = (string) Str::uuid();
        DB::table('integration_credentials')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'provider' => 'snapchat',
            'credential_scope' => 'tenant_shared', 'credential_type' => 'oauth', 'encrypted_payload' => encrypt('{}'), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_the_refresher_and_notifier_leave_direct_pay_projects_alone(): void
    {
        $project = $this->project();
        $this->add($project, 1_000, 10)->assertCreated();
        $this->actingAs($this->finance, 'sanctum')->patchJson('/api/v1/funding/projects/'.$project->id, ['payment_method' => 'direct'])->assertOk();
        $this->spend($project, (string) Str::uuid(), 2, 5_000);
        app(FundingSpendRefresher::class)->refresh(ProjectFunding::query()->firstOrFail());
        $this->assertSame(0, app(FundingNotifier::class)->evaluate(ProjectFunding::query()->firstOrFail()));
        $this->assertSame([], $this->notices($this->finance));
    }

    /** A finance user confined to ONE project of a client hears nothing about the client's other project. */
    public function test_a_membership_confined_to_another_project_hears_nothing_about_this_one(): void
    {
        $project = $this->project('Shared', 'Here');
        $sibling = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'name' => 'There', 'status' => 'active']);
        $confined = $this->user($this->tenant, ['funding.manage', 'clients.view', 'clients.view_all', 'projects.view.all']);
        $membership = Membership::query()->where('user_id', $confined->id)->where('tenant_id', $this->tenant->id)->firstOrFail();
        MembershipScope::query()->create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_PROJECT, 'scope_id' => $sibling->id]);
        $this->holdingTenant((string) $this->tenant->id);

        $this->add($project, 1_000, 10)->assertCreated();
        $this->spend($project, (string) Str::uuid(), 5, 900);
        $this->sweep();

        $this->assertSame(['funding:low'], $this->notices($this->finance));
        $this->assertSame([], $this->notices($confined));
    }

    /** A team member whose membership withholds money is not told the figure; a top-up short of the spend says so. */
    public function test_withheld_members_hear_nothing_and_a_short_top_up_never_reads_as_money_left(): void
    {
        $project = $this->project();
        $withheld = $this->user($this->tenant, ['budget.view', 'clients.view', 'clients.view_all', 'projects.view']);
        ProjectMembership::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'user_id' => $withheld->id,
            'role' => 'manager', 'status' => 'active', 'denied' => ['money.view']]);
        $this->holdingTenant((string) $this->tenant->id);

        $this->add($project, 1_000, 10)->assertCreated();
        $this->spend($project, (string) Str::uuid(), 5, 4_000);
        $this->add($project, 1_000, 0)->assertCreated();

        $this->assertSame([], $this->notices($withheld));
        $messages = AppNotification::query()->where('user_id', $this->manager->id)->where('entity_type', 'funding:added')->pluck('message_ar')->implode(' | ');
        $this->assertStringContainsString('أعلى من الميزانية بـ 2,000 ر.س', $messages);
        $this->assertStringNotContainsString('المتبقي 2,000', $messages);
    }
}
