<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Engine\ModelClient;
use App\Domains\Agents\Engine\ModelTurn;
use App\Domains\Agents\Models\AgentRoutine;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AGENTS-ROUTINES-001 — a routine runs only where a project switched it on, says why when it cannot,
 * and is an ordinary conversation once it starts.
 */
final class AgentRoutinesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'o', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $test = $this;
        $this->app->instance(ModelClient::class, new class($test) implements ModelClient
        {
            public function __construct(private readonly AgentRoutinesTest $test) {}

            public function respond(string $model, string $system, array $history, array $tools, int $maxTokens = 16000): ModelTurn
            {
                $this->test->record((string) ($history[0]['blocks'][0]['text'] ?? ''));

                return new ModelTurn([['type' => 'text', 'text' => 'Nothing needs action.']], 'Nothing needs action.', [], 'end', [], $model);
            }
        });
    }

    public function record(string $text): void
    {
        $this->asked[] = $text;
    }

    private function key(): void
    {
        $c = new AIProviderCredential(['tenant_id' => $this->tenant->id, 'provider' => 'anthropic', 'credential_scope' => 'tenant', 'status' => 'active']);
        $c->setSecret('test-key-not-real');
        $c->save();
    }

    private function url(): string
    {
        return "/api/v1/projects/{$this->project->id}/agent-routines";
    }

    public function test_nothing_runs_until_a_project_switches_a_routine_on(): void
    {
        $this->key();
        $this->artisan('agents:run-routines', ['routine' => 'budget_guard'])->assertSuccessful();

        $this->assertSame(0, AgentThread::withoutGlobalScopes()->count());
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('data.routines.1.key', 'budget_guard')->assertJsonPath('data.routines.1.enabled', false);
    }

    public function test_a_switched_on_routine_opens_a_thread_with_its_fixed_instruction_and_replies(): void
    {
        $this->key();
        $this->actingAs($this->owner)->putJson($this->url(), ['routine' => 'budget_guard', 'enabled' => true])->assertOk()->assertJsonPath('data.routines.1.enabled', true);

        $this->artisan('agents:run-routines', ['routine' => 'budget_guard'])->assertSuccessful();

        $thread = AgentThread::withoutGlobalScopes()->sole();
        $this->assertSame('campaign', $thread->agent_key);
        $this->assertSame('idle', $thread->fresh()->status);
        $this->assertStringStartsWith('Routine: budget guard.', $this->asked[0]);
        $row = AgentRoutine::withoutGlobalScopes()->sole();
        $this->assertSame('queued', $row->last_outcome);
        $this->assertSame((string) $thread->getKey(), (string) $row->last_thread_id);
    }

    public function test_a_project_without_a_key_is_skipped_with_the_reason(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['routine' => 'daily_analysis', 'enabled' => true])->assertOk();

        $this->artisan('agents:run-routines', ['routine' => 'daily_analysis'])->assertSuccessful();

        $this->assertSame(0, AgentThread::withoutGlobalScopes()->count());
        $this->assertSame('no_ai_credential', AgentRoutine::withoutGlobalScopes()->sole()->last_outcome);
        $this->assertSame([], $this->asked);
    }

    public function test_a_run_still_answering_is_not_started_twice(): void
    {
        $this->key();
        $thinking = AgentThread::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'agent_key' => 'campaign', 'status' => 'thinking']);
        AgentRoutine::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'routine' => 'budget_guard', 'enabled' => true, 'last_thread_id' => $thinking->getKey()]);

        $this->artisan('agents:run-routines', ['routine' => 'budget_guard'])->assertSuccessful();

        $this->assertSame(1, AgentThread::withoutGlobalScopes()->count());
        $this->assertSame('skipped_busy', AgentRoutine::withoutGlobalScopes()->sole()->last_outcome);
    }

    public function test_switching_a_routine_needs_ai_manage(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $role->givePermissionTo(...Permission::where('key', '!=', 'ai.manage')->pluck('key')->all());
        $user = User::create(['name' => 'v', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        $this->actingAs($user)->putJson($this->url(), ['routine' => 'budget_guard', 'enabled' => true])->assertForbidden();
        $this->actingAs($user)->getJson($this->url())->assertOk();
    }

    public function test_an_unknown_routine_is_refused(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['routine' => 'delete_everything', 'enabled' => true])->assertStatus(422);
        $this->artisan('agents:run-routines', ['routine' => 'delete_everything'])->assertFailed();
    }
}
