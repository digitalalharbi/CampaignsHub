<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-VIEWS-001 — how often a project's shared links were opened, across all of them: opens, never people.
 */
final class ProjectReportViewsTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        [$this->tenant, $this->owner, $this->project, $this->report] = $this->workspace();
    }

    /** @return array{0: Tenant, 1: User, 2: Project, 3: Report} */
    private function workspace(): array
    {
        $tenant = Tenant::create(['name' => 'V', 'slug' => 'rv-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => uniqid().'@rv.test', 'password' => 'secret1234']);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::App, role: 'owner'));
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'timezone' => 'Asia/Riyadh']);
        $project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $report = Report::create([
            'project_id' => $project->id, 'name' => 'October', 'type' => 'executive', 'status' => 'completed', 'currency' => 'USD',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'data' => [],
        ]);
        app(TenantContext::class)->forget();

        return [$tenant, $user, $project, $report];
    }

    private function open(string $shareId, string $ua, string $utc, string $action = 'view'): void
    {
        DB::table('report_share_access_logs')->insert([
            'id' => (string) Str::uuid(), 'share_id' => $shareId, 'action' => $action, 'ip' => '203.0.113.1', 'user_agent' => $ua, 'created_at' => Carbon::parse($utc, 'UTC'),
        ]);
    }

    /** @return array<string,mixed> */
    private function summary(?User $as = null, ?Project $project = null, string $query = 'from=2026-10-14&to=2026-10-21'): array
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->withHeaders(self::SPA)
            ->getJson('/api/v1/projects/'.($project ?? $this->project)->id."/report-views/summary?{$query}")->assertOk()->json('data');
    }

    private function share(Report $report): string
    {
        app(TenantContext::class)->setTenantId((string) $report->tenant_id);
        [$share] = app(ShareService::class)->create($report, [], null);
        app(TenantContext::class)->forget();

        return (string) $share->id;
    }

    public function test_a_project_nobody_opened_says_so_and_a_project_with_opens_adds_every_link_up_without_machines(): void
    {
        $this->assertSame('no_views', $this->summary()['state']);

        $a = $this->share($this->report);
        $b = $this->share($this->report);
        $chrome = 'Mozilla/5.0 Chrome/120';
        $this->open($a, $chrome, '2026-10-15 09:00:00');
        $this->open($a, $chrome, '2026-10-15 10:00:00');
        $this->open($b, 'Mozilla/5.0 Safari/17', '2026-10-17 21:30:00');   // 00:30 on the 18th in Riyadh
        $this->open($b, 'WhatsApp/2.23', '2026-10-17 09:00:00');           // a link preview
        $this->open($b, 'Googlebot/2.1', '2026-10-17 09:05:00');           // a crawler
        $this->open($a, $chrome, '2026-10-16 09:00:00', 'download');       // not a view
        $this->open($a, $chrome, '2026-09-01 09:00:00');                   // outside the window

        $s = $this->summary();

        $this->assertSame('measured', $s['state'], 'it was opened at some point, even outside this window');
        $this->assertSame(3, $s['opens']);
        $this->assertSame(2, $s['machine_opens']);
        $byDay = collect($s['series'])->keyBy('period');
        $this->assertSame(8, count($s['series']), 'every day of the window is present');
        $this->assertSame(2, $byDay['2026-10-15']['opens']);
        $this->assertSame(0, $byDay['2026-10-17']['opens']);
        $this->assertSame(1, $byDay['2026-10-18']['opens'], 'an open at 00:30 local belongs to the day the client lived it in');
        $this->assertSame(3, array_sum(array_column($s['series'], 'opens')));
        $this->assertSame('October', $s['top_reports'][0]['name']);
        $this->assertSame(3, $s['top_reports'][0]['opens']);
        $this->assertSame('opens_not_people', $s['basis']['counts']);

        $json = (string) json_encode($s);
        foreach (['203.0.113.1', 'Chrome', 'WhatsApp', 'visitor', 'unique'] as $private) {
            $this->assertStringNotContainsString($private, $json, "the summary carries {$private}");
        }
    }

    public function test_it_agrees_with_the_per_link_analytics_it_adds_up(): void
    {
        $a = $this->share($this->report);
        foreach (['2026-10-15 09:00:00', '2026-10-16 09:00:00'] as $when) {
            $this->open($a, 'Mozilla/5.0 Chrome/120', $when);
        }
        $this->open($a, 'curl/8.4.0', '2026-10-16 09:30:00');

        $project = $this->summary();
        $link = $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA)
            ->getJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares/{$a}/analytics?from=2026-10-14&to=2026-10-21")->assertOk()->json('data');

        $this->assertSame($link['views'], $project['opens'], 'one definition of a machine, so the totals cannot disagree');
        $this->assertSame($link['bot_opens'], $project['machine_opens']);
    }

    public function test_another_workspaces_opens_and_another_projects_opens_are_never_counted(): void
    {
        $mine = $this->share($this->report);
        $this->open($mine, 'Mozilla/5.0 Chrome/120', '2026-10-15 09:00:00');

        [, , , $otherReport] = $this->workspace();
        $theirs = $this->share($otherReport);
        $this->open($theirs, 'Mozilla/5.0 Chrome/120', '2026-10-15 09:00:00');
        $this->open($theirs, 'Mozilla/5.0 Chrome/120', '2026-10-15 10:00:00');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2-'.uniqid(), 'mode' => 'managed']);
        $second = Project::create(['client_workspace_id' => $client->id, 'name' => 'P2', 'status' => 'active']);
        $secondReport = Report::create(['project_id' => $second->id, 'name' => 'Other', 'type' => 'executive', 'status' => 'completed', 'currency' => 'USD', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'data' => []]);
        app(TenantContext::class)->forget();
        $this->open($this->share($secondReport), 'Mozilla/5.0 Chrome/120', '2026-10-15 11:00:00');

        $this->assertSame(1, $this->summary()['opens'], 'this project\'s own link only');
    }

    public function test_it_needs_a_project_permission_and_a_valid_range(): void
    {
        [, $stranger, $theirProject] = $this->workspace();
        $url = "/api/v1/projects/{$this->project->id}/report-views/summary";

        $this->actingAs($stranger, 'sanctum')->withHeaders(self::SPA)->getJson($url)->assertStatus(404);
        $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA)->getJson($url.'?from=not-a-date')->assertStatus(422);
        $this->assertSame('no_views', $this->summary($stranger, $theirProject)['state']);
    }
}
