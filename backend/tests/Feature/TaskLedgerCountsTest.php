<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TASKS-LEDGER-001 — the tasks endpoint returned every task, and the page counted the ones it got.
 *
 * `TaskController::index()` ended in `$query->get()` with no limit — the file's own comment cites
 * 2,105 tasks in one tenant, and every visit to the page fetched and rendered all of them. The page
 * then filtered that array in the browser and computed «open», «overdue» and «done» from it.
 *
 * Both halves have to move together. Paginating alone would leave the same three counts describing
 * whichever page arrived — the exact defect the alerts queue was fixed for («the queue counts what
 * exists, not what fitted»), reintroduced on the next screen along. So the counts are computed over
 * the whole scope, server-side, and travel in `meta` beside the page.
 */
final class TaskLedgerCountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($this->user, $this->tenant, Portal::App);
        $this->user->assignRole($role);
    }

    private function task(string $status, ?string $due = null): Task
    {
        return Task::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'title' => 'T-'.uniqid(), 'status' => $status, 'priority' => 'medium',
            'created_by' => $this->user->id, 'due_date' => $due,
        ]);
    }

    /** @return array{0: array<int, mixed>, 1: array<string, mixed>} */
    private function list(string $query = ''): array
    {
        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/tasks'.($query ? '?'.$query : ''))->assertOk();

        return [(array) $res->json('data'), (array) $res->json('meta')];
    }

    public function test_the_page_is_bounded_and_says_how_many_there_are(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->task('todo');
        }

        [$rows, $meta] = $this->list('per_page=25');

        $this->assertCount(25, $rows, 'a list endpoint does not hand over the whole table');
        $this->assertSame(60, $meta['total'], 'and it says how many there are, so nothing is silently dropped');
        $this->assertSame(3, $meta['last_page']);
    }

    /**
     * The counts describe the LEDGER, not the page.
     *
     * Fifty open tasks against a twenty-five-row page: a count derived from the page would read 25,
     * and the reader has no way to tell that from a workspace with 25 open tasks.
     */
    public function test_the_counts_describe_everything_not_the_page(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->task('todo');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->task('completed');
        }
        $this->task('in_progress', Carbon::now()->subWeek()->toDateString());

        [, $meta] = $this->list('per_page=25');

        $this->assertSame(51, $meta['counts']['open'], 'fifty todo plus the overdue in-progress one');
        $this->assertSame(5, $meta['counts']['done']);
        $this->assertSame(1, $meta['counts']['overdue']);
    }

    /** A completed task past its due date is not overdue — it is done. */
    public function test_a_finished_task_is_never_counted_overdue(): void
    {
        $this->task('completed', Carbon::now()->subWeek()->toDateString());
        $this->task('cancelled', Carbon::now()->subWeek()->toDateString());

        [, $meta] = $this->list();

        $this->assertSame(0, $meta['counts']['overdue']);
    }

    /** A filter narrows the page AND the total, so the pager cannot offer pages that render empty. */
    public function test_a_status_filter_narrows_the_total_too(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->task('todo');
        }
        $this->task('completed');

        [$rows, $meta] = $this->list('status=completed&per_page=25');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $meta['total']);
    }

    /**
     * The search moved to the server because it had to.
     *
     * The page filtered the whole table in memory, which worked only while the whole table was sent.
     * Against a PAGE, a browser-side search answers «no results» about a task the workspace is
     * holding — it simply was not in the twenty-five rows that arrived.
     */
    public function test_the_search_reaches_past_the_first_page(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->task('todo');
        }

        $needle = Task::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'title' => 'Rename the Riyadh landing page', 'status' => 'todo', 'priority' => 'high',
            'created_by' => $this->user->id,
        ]);

        [$rows, $meta] = $this->list('q=Riyadh&per_page=25');

        $this->assertSame(1, $meta['total']);
        $this->assertSame($needle->id, $rows[0]['id']);
    }

    /** A priority filter is the server's too, for the same reason. */
    public function test_the_priority_filter_reaches_past_the_first_page(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->task('todo');
        }

        Task::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'title' => 'Urgent', 'status' => 'todo', 'priority' => 'critical',
            'created_by' => $this->user->id,
        ]);

        [$rows, $meta] = $this->list('priority=critical&per_page=25');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $meta['total']);
    }
}
