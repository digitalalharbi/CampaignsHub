<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * REPORT-LINK-COPY-001 and REPORT-SHARE-MODES-001 — an existing link can be copied again, and the manager says what each link is.
 *
 * Tokens are stored one-way, so «copy» cannot read an old link back. It must not mint a replacement either — a second link has its own
 * expiry, password and audit trail and quietly leaves the first one alive. An alias is a second way into the SAME share row.
 */
final class ReportShareCopyLinkTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'cp-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'cp-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->report = $this->makeReport();
    }

    private function makeReport(): Report
    {
        $data = ['period' => ['from' => '2026-10-18', 'to' => '2026-10-24'], 'currency' => 'SAR', 'kpis' => ['spend' => 10.0], 'slides' => []];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        return Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'completed', 'period_start' => '2026-10-18', 'period_end' => '2026-10-24', 'currency' => 'SAR', 'data' => $data,
        ]);
    }

    /** A link as every link was made before aliases existed: token hash only. */
    private function legacyLink(array $over = []): array
    {
        $raw = 'legacy'.bin2hex(random_bytes(8));
        $share = ReportShare::create(array_merge([
            'tenant_id' => $this->tenant->id, 'report_id' => $this->report->id, 'mode' => 'snapshot',
            'token_hash' => hash('sha256', $raw), 'allow_download' => true,
        ], $over));

        return [$share, $raw];
    }

    private function copy(ReportShare $share, ?Report $report = null)
    {
        $report ??= $this->report;

        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares/{$share->id}/link");
    }

    public function test_a_new_link_is_copied_as_the_exact_url_that_was_sent_and_no_second_link_exists(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, ['allow_download' => true], null);
        $before = ReportShare::withoutGlobalScopes()->count();

        $res = $this->copy($share)->assertOk();

        $this->assertSame(ShareService::urlFor($raw), $res->json('data.url'));
        $this->assertFalse($res->json('data.alias_minted'));
        $this->assertSame($before, ReportShare::withoutGlobalScopes()->count(), 'copying never creates a link');
    }

    public function test_an_older_link_keeps_its_url_and_gains_one_stable_alias_into_the_same_share(): void
    {
        [$share, $raw] = $this->legacyLink(['expires_at' => now()->addDays(10), 'password_hash' => Hash::make('pw-1234')]);
        $before = ReportShare::withoutGlobalScopes()->count();

        $first = $this->copy($share)->assertOk();
        $this->assertTrue($first->json('data.alias_minted'));
        $alias = basename((string) $first->json('data.path'));
        $this->assertNotSame($raw, $alias);

        $again = $this->copy($share)->assertOk();
        $this->assertFalse($again->json('data.alias_minted'));
        $this->assertSame($first->json('data.url'), $again->json('data.url'), 'the copied address is stable');
        $this->assertSame($before, ReportShare::withoutGlobalScopes()->count());

        // The original URL still works, and the alias is gated by the SAME password and expiry — one share, one set of restrictions.
        $this->getJson("/api/v1/reports/shared/{$alias}")->assertStatus(401);
        $this->withHeader('X-Report-Password', 'pw-1234')->getJson("/api/v1/reports/shared/{$raw}")->assertOk();
        $this->withHeader('X-Report-Password', 'pw-1234')->getJson("/api/v1/reports/shared/{$alias}")->assertOk();

        // Opening by alias is logged against the one link, not a new one.
        $this->assertGreaterThan(0, $share->fresh()->logs()->count());

        $share->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->withHeader('X-Report-Password', 'pw-1234')->getJson("/api/v1/reports/shared/{$alias}")->assertStatus(404);
    }

    public function test_pause_and_revoke_govern_the_alias_exactly_as_they_govern_the_link(): void
    {
        [$share] = $this->legacyLink();
        $alias = basename((string) $this->copy($share)->assertOk()->json('data.path'));
        $this->getJson("/api/v1/reports/shared/{$alias}")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares/{$share->id}/pause")->assertOk();
        $this->assertContains($this->getJson("/api/v1/reports/shared/{$alias}")->status(), [403, 404, 410, 423]);
        $this->copy($share)->assertOk(); // a paused link can still be copied; it just does not open

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares/{$share->id}/resume")->assertOk();
        $this->getJson("/api/v1/reports/shared/{$alias}")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares/{$share->id}/revoke")->assertOk();
        $this->assertContains($this->getJson("/api/v1/reports/shared/{$alias}")->status(), [403, 404, 410]);
        $this->copy($share)->assertStatus(409);
    }

    public function test_copying_is_audited_and_the_secret_is_never_serialised(): void
    {
        [$share] = $this->legacyLink();
        $this->copy($share)->assertOk();

        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'report.shared_link_copied')->where('entity_id', (string) $share->id)->exists());

        $index = (string) $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares")->assertOk()->getContent();
        foreach (['alias_cipher', 'alias_hash', 'token_hash', (string) $share->fresh()->alias_cipher] as $secret) {
            $this->assertStringNotContainsString($secret, $index);
        }
    }

    public function test_another_reports_or_projects_link_is_a_404_and_a_member_without_sharing_rights_is_refused(): void
    {
        [$share] = $this->legacyLink();
        $other = $this->makeReport();
        $this->copy($share, $other)->assertNotFound();

        $viewer = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant, Portal::Agency);
        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares/{$share->id}/link")->assertForbidden();
    }

    public function test_the_manager_states_each_links_mode_and_period(): void
    {
        $snapshot = $this->legacyLink()[0];
        $fixed = $this->legacyLink(['mode' => 'live', 'scope' => ['project_id' => (string) $this->project->id, 'campaign_ids' => ['x'], 'providers' => ['meta'], 'earliest' => '2026-10-18', 'latest' => '2026-10-24']])[0];
        $this->report->forceFill(['config' => ['period_mode' => 'fixed']])->save();

        $rows = collect($this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['kind' => 'frozen', 'from' => '2026-10-18', 'to' => '2026-10-24'], $rows[$snapshot->id]['period']);
        $this->assertSame(['kind' => 'fixed', 'from' => '2026-10-18', 'to' => '2026-10-24'], $rows[$fixed->id]['period']);

        $this->report->forceFill(['config' => ['period_mode' => 'rolling']])->save();
        $rolling = collect($this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares")->json('data'))->keyBy('id');
        $this->assertSame('rolling', $rolling[$fixed->id]['period']['kind']);
        $this->assertNull($rolling[$fixed->id]['period']['to']);
    }
}
