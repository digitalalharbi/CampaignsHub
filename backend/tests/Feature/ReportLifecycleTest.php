<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * Owner file 2026-11-03 section 8 — the life of a report and its link: rename and audit, duplicate, Live and Snapshot
 * conversion, copying the current link, and pause / resume across every door.
 */
final class ReportLifecycleTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Life', 'slug' => 'life-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Sales push', 'status' => 'active', 'objective' => 'sales',
        ]);

        $this->owner = $this->userWith(Permission::pluck('key')->all(), 'owner');
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $slug): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => $slug, 'slug' => $slug.'-'.uniqid()]);
        $role->givePermissionTo(...array_unique(array_merge($permissions, ['projects.view', 'projects.view.all'])));
        $user = User::create(['name' => $slug, 'email' => $slug.'-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function report(array $over = []): Report
    {
        $data = [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-31'], 'currency' => 'SAR', 'objective' => 'sales',
            'kpis' => ['spend' => 1200.0, 'conversions' => 30.0, 'impressions' => 90000.0, 'clicks' => 900.0],
            'timeseries' => [['date' => '2026-08-01', 'spend' => 400.0]],
            'platforms' => [['provider' => 'meta', 'spend' => 700.0, 'conversions' => 20.0]],
        ];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        return Report::create($over + [
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR', 'data' => $data,
            'generated_at' => '2026-09-01 10:00:00',
            'config' => ['branding' => ['prefer' => 'agency'], 'period_mode' => 'fixed'],
            'section_settings' => ['sections' => ['trends' => false]],
        ]);
    }

    /** @return array{0: ReportShare, 1: string} */
    private function link(Report $r, array $opts = []): array
    {
        return app(ShareService::class)->create($r, ['allow_download' => true] + $opts, null);
    }

    /** @return array<string, mixed> a ceiling that makes the link live */
    private function ceiling(): array
    {
        return ['project_id' => (string) $this->project->getKey(), 'campaign_ids' => [(string) $this->campaign->getKey()], 'providers' => ['meta'],
            'creative_ids' => [], 'creative_group_ids' => [], 'excluded_creative_ids' => [], 'objectives' => [], 'paths' => [],
            'earliest' => '2026-08-01', 'latest' => '2026-08-31'];
    }

    private function base(Report $r): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports/{$r->getKey()}";
    }

    private function as(User $u): self
    {
        $this->actingAs($u, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());

        return $this;
    }

    // ---------------------------------------------------------------- rename and audit (8.2 / 8.6)

    public function test_a_rename_is_audited_with_who_when_before_and_after(): void
    {
        $r = $this->report();
        $this->as($this->owner)->patchJson($this->base($r), ['name' => 'August final'])->assertOk()->assertJsonPath('data.name', 'August final');

        $row = AuditLog::query()->where('action', 'report.updated')->where('entity_id', (string) $r->getKey())->first();
        $this->assertNotNull($row, 'an actual edit leaves an audit row');
        $this->assertSame($this->owner->id, $row->user_id);
        $this->assertNotNull($row->created_at);
        $this->assertSame('August', $row->before['name']);
        $this->assertSame('August final', $row->after['name']);
    }

    public function test_an_edit_that_changes_nothing_writes_no_audit_row(): void
    {
        $r = $this->report();
        $this->as($this->owner)->patchJson($this->base($r), ['name' => 'August'])->assertOk();
        $this->assertSame(0, AuditLog::query()->where('action', 'report.updated')->count());
    }

    public function test_report_mode_cannot_be_changed_through_a_plain_edit(): void
    {
        $r = $this->report(['mode' => 'snapshot']);
        $this->as($this->owner)->patchJson($this->base($r), ['mode' => 'live'])->assertStatus(422);
        $this->assertSame('snapshot', $r->fresh()->mode, 'a Snapshot is never converted silently');
        // Naming the mode it already has is not a conversion.
        $this->as($this->owner)->patchJson($this->base($r), ['mode' => 'snapshot', 'name' => 'x'])->assertOk();
    }

    public function test_only_a_manager_can_rename_and_never_across_projects_or_tenants(): void
    {
        $r = $this->report();
        $viewer = $this->userWith(['reports.view'], 'viewer');
        $this->as($viewer)->patchJson($this->base($r), ['name' => 'nope'])->assertForbidden();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $stranger = $this->userWithin($other);
        $this->actingAs($stranger, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey())
            ->patchJson($this->base($r), ['name' => 'nope'])->assertStatus(404);
        $this->assertSame('August', $r->fresh()->name);
    }

    private function userWithin(Tenant $t): User
    {
        $this->holdingTenant((string) $t->getKey());
        $role = Role::create(['tenant_id' => $t->getKey(), 'name' => 'o', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $u = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($u, $t);
        $u->assignRole($role);
        $this->holdingTenant((string) $this->tenant->getKey());

        return $u;
    }

    // ---------------------------------------------------------------- duplicate (8.3)

    public function test_duplicate_makes_an_independent_report_and_link_and_leaves_the_original_alone(): void
    {
        $r = $this->report();
        [$src, $rawSrc] = $this->link($r, ['hide_spend' => true, 'watermark' => true, 'password' => 'secret1']);
        $beforeReport = $r->fresh()->toArray();
        $beforeShare = $src->fresh()->toArray();

        $res = $this->as($this->owner)->postJson($this->base($r).'/duplicate', ['name' => 'August copy', 'share_id' => (string) $src->getKey()])->assertCreated();
        $newId = $res->json('data.id');
        $this->assertNotSame((string) $r->getKey(), $newId);
        $res->assertJsonPath('data.name', 'August copy');

        $copy = Report::query()->findOrFail($newId);
        $this->assertSame($r->config, $copy->config, 'identity and settings are copied');
        $this->assertSame($r->section_settings, $copy->section_settings, 'sections are copied');
        $this->assertSame($r->period_start->toDateString(), $copy->period_start->toDateString());

        $newLink = ReportShare::query()->where('report_id', $newId)->get();
        $this->assertCount(1, $newLink, 'a new link was made');
        $this->assertNotSame($src->token_hash, $newLink[0]->token_hash, 'a new token');
        $this->assertTrue($newLink[0]->watermark);
        $this->assertNotNull($newLink[0]->password_hash, 'a copied link is never more open than its source');
        $this->assertNotNull($res->json('data.link.url'));
        $this->assertNotNull($res->json('data.link.token'));

        $this->assertEquals($beforeReport, $r->fresh()->toArray(), 'the original is unchanged');
        $this->assertEquals($beforeShare, $src->fresh()->toArray(), 'the original link is unchanged');
        $this->assertSame(1, ReportShare::query()->where('report_id', $r->getKey())->count());
        $this->withHeader('X-Report-Password', 'secret1')->getJson("/api/v1/reports/shared/{$rawSrc}")->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'report.duplicated')->count());
    }

    public function test_a_duplicate_without_a_source_link_starts_closed(): void
    {
        $r = $this->report();
        $res = $this->as($this->owner)->postJson($this->base($r).'/duplicate', [])->assertCreated();
        $link = ReportShare::query()->where('report_id', $res->json('data.id'))->firstOrFail();
        $this->assertTrue($link->getRawOriginal('hide_spend') === true || (bool) $link->getRawOriginal('hide_spend'));
        $this->assertTrue((bool) $link->hide_revenue);
        $this->assertTrue((bool) $link->hide_campaign_names, 'a client report carries no campaign names');
    }

    public function test_duplicate_does_not_widen_scope_and_refuses_a_foreign_one(): void
    {
        $foreign = (string) Str::uuid();
        $r = $this->report(['scope' => ['account_ids' => [$foreign], 'campaign_ids' => [], 'ad_ids' => [], 'project_ids' => []]]);
        $before = Report::query()->count();
        $this->as($this->owner)->postJson($this->base($r).'/duplicate', [])->assertStatus(422);
        $this->assertSame($before, Report::query()->count(), 'nothing was made');

        $ok = $this->report(['name' => 'Bound', 'scope' => null]);
        $res = $this->as($this->owner)->postJson($this->base($ok).'/duplicate', [])->assertCreated();
        $this->assertNull(Report::query()->find($res->json('data.id'))->scope, 'no scope in, no scope out: never widened or invented');
    }

    public function test_duplicate_needs_manage_and_a_link_needs_share_and_never_crosses_projects(): void
    {
        $r = $this->report();
        $viewer = $this->userWith(['reports.view'], 'viewer');
        $this->as($viewer)->postJson($this->base($r).'/duplicate', [])->assertForbidden();

        $manager = $this->userWith(['reports.view', 'reports.create'], 'manager');
        $res = $this->as($manager)->postJson($this->base($r).'/duplicate', []);
        $res->assertCreated();
        $this->assertNull($res->json('data.link'), 'no share permission, no link');
        $this->assertSame(0, ReportShare::query()->where('report_id', $res->json('data.id'))->count());

        $stranger = $this->userWithin(Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']));
        $this->actingAs($stranger, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey())
            ->postJson($this->base($r).'/duplicate', [])->assertStatus(404);

        // A source link of another report is not reachable through this one.
        $other = $this->report(['name' => 'Other']);
        [$otherLink] = $this->link($other);
        $this->as($this->owner)->postJson($this->base($r).'/duplicate', ['share_id' => (string) $otherLink->getKey()])->assertStatus(404);
    }

    // ---------------------------------------------------------------- Live <-> Snapshot (8.4)

    public function test_converting_asks_first_and_changes_nothing_until_confirmed(): void
    {
        $r = $this->report();
        [$share] = $this->link($r, ['scope' => $this->ceiling(), 'mode' => 'live']);
        $url = $this->base($r)."/shares/{$share->getKey()}/convert";

        $res = $this->as($this->owner)->postJson($url, ['to' => 'snapshot'])->assertOk();
        $res->assertJsonPath('data.changed', false)->assertJsonPath('data.requires_confirmation', true);
        $this->assertNotEmpty($res->json('data.notice'), 'the user is told what will happen');
        $this->assertSame('live', $share->fresh()->mode);
    }

    public function test_live_to_snapshot_keeps_the_same_url_and_freezes_the_stored_document(): void
    {
        $r = $this->report();
        [$share, $raw] = $this->link($r, ['scope' => $this->ceiling(), 'mode' => 'live']);
        $hash = $share->token_hash;
        $dataBefore = $r->fresh()->data;

        $this->as($this->owner)->postJson($this->base($r)."/shares/{$share->getKey()}/convert", ['to' => 'snapshot', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.changed', true)->assertJsonPath('data.link.mode', 'snapshot');

        $fresh = $share->fresh();
        $this->assertSame($hash, $fresh->token_hash, 'same link');
        $this->assertFalse($fresh->isLive());
        $this->assertSame($dataBefore, $r->fresh()->data, 'the stored document is untouched');
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk();
        $row = AuditLog::query()->where('action', 'report.shared_link_converted')->firstOrFail();
        $this->assertSame('live', $row->before['mode']);
        $this->assertSame('snapshot', $row->after['mode']);
    }

    public function test_snapshot_to_live_never_widens_the_ceiling_and_keeps_the_snapshot_document(): void
    {
        $r = $this->report();
        [$share] = $this->link($r, ['scope' => $this->ceiling(), 'mode' => 'snapshot']);
        $scopeBefore = $share->fresh()->scope;
        $dataBefore = $r->fresh()->data;
        $url = $this->base($r)."/shares/{$share->getKey()}/convert";

        $this->as($this->owner)->postJson($url, ['to' => 'live', 'confirm' => true])->assertOk()->assertJsonPath('data.link.mode', 'live');
        $this->assertSame($scopeBefore, $share->fresh()->scope, 'the same window and campaigns: nothing widened');
        $this->assertSame($dataBefore, $r->fresh()->data, 'the historical document is not overwritten');
        $this->assertSame('2026-09-01 10:00:00', $r->fresh()->generated_at->format('Y-m-d H:i:s'));
        $this->assertSame('snapshot', AuditLog::query()->where('action', 'report.shared_link_converted')->firstOrFail()->before['mode']);
    }

    public function test_a_snapshot_with_no_ceiling_gets_one_bounded_by_the_reports_project(): void
    {
        $r = $this->report();
        [$share] = $this->link($r);
        $this->assertNull($share->scope);
        $this->as($this->owner)->postJson($this->base($r)."/shares/{$share->getKey()}/convert", ['to' => 'live', 'confirm' => true])->assertOk();
        $scope = $share->fresh()->scope;
        $this->assertSame((string) $this->project->getKey(), $scope['project_id']);
        $this->assertArrayHasKey('campaign_ids', $scope);
    }

    public function test_conversion_is_refused_for_a_revoked_link_needs_share_and_a_foreign_report(): void
    {
        $r = $this->report();
        [$share] = $this->link($r, ['scope' => $this->ceiling(), 'mode' => 'live']);
        $url = $this->base($r)."/shares/{$share->getKey()}/convert";

        $viewer = $this->userWith(['reports.view', 'reports.create'], 'noshare');
        $this->as($viewer)->postJson($url, ['to' => 'snapshot', 'confirm' => true])->assertForbidden();

        $other = $this->report(['name' => 'Other']);
        $this->as($this->owner)->postJson($this->base($other)."/shares/{$share->getKey()}/convert", ['to' => 'snapshot', 'confirm' => true])->assertNotFound();

        $this->postJson($url, ['to' => 'bogus'])->assertStatus(422);
        $share->update(['revoked_at' => now()]);
        $this->postJson($url, ['to' => 'snapshot', 'confirm' => true])->assertStatus(409);
        $this->assertSame('live', $share->fresh()->mode, 'a revoked link is not quietly changed');
    }

    // ---------------------------------------------------------------- copy the current link (8.1)

    public function test_the_current_link_can_be_copied_without_making_a_new_one_or_changing_its_type(): void
    {
        $r = $this->report();
        [$share, $raw] = $this->link($r, ['scope' => $this->ceiling(), 'mode' => 'live']);
        $count = ReportShare::query()->count();

        $res = $this->as($this->owner)->getJson($this->base($r)."/shares/{$share->getKey()}/link")->assertOk();
        $res->assertJsonPath('data.available', true);
        $this->assertStringEndsWith("/r/{$raw}", $res->json('data.url'));
        $this->assertSame($count, ReportShare::query()->count());
        $this->assertSame('live', $share->fresh()->mode);
        $this->assertSame($share->token_hash, $share->fresh()->token_hash);
        $this->assertStringNotContainsString($raw, (string) $share->fresh()->makeVisible(['token_hash'])->toJson(), 'the secret is not in the model serialisation');
    }

    public function test_a_link_made_before_copy_support_says_so_and_never_mints_a_replacement(): void
    {
        $r = $this->report();
        [$share] = $this->link($r);
        $share->forceFill(['token_encrypted' => null])->save();
        $count = ReportShare::query()->count();

        $this->as($this->owner)->getJson($this->base($r)."/shares/{$share->getKey()}/link")->assertOk()->assertJsonPath('data.available', false);
        $this->assertSame($count, ReportShare::query()->count());
    }

    public function test_copying_needs_share_and_is_scoped_to_the_report_and_a_revoked_link_is_not_handed_out(): void
    {
        $r = $this->report();
        [$share] = $this->link($r);
        $url = $this->base($r)."/shares/{$share->getKey()}/link";

        $this->as($this->userWith(['reports.view', 'reports.create'], 'noshare'))->getJson($url)->assertForbidden();
        $this->as($this->owner)->getJson($this->base($this->report(['name' => 'O']))."/shares/{$share->getKey()}/link")->assertNotFound();
        $stranger = $this->userWithin(Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']));
        $this->actingAs($stranger, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey())->getJson($url)->assertNotFound();

        $share->update(['revoked_at' => now()]);
        $this->as($this->owner)->getJson($url)->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_the_token_is_stored_encrypted_not_as_plain_text(): void
    {
        $r = $this->report();
        [$share, $raw] = $this->link($r);
        $stored = (string) DB::table('report_shares')->where('id', $share->getKey())->value('token_encrypted');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($raw, $stored);
    }

    // ---------------------------------------------------------------- pause and resume (8.5)

    /** @return list<string> every public door of a link */
    private function doors(string $raw): array
    {
        return [
            "/api/v1/reports/shared/{$raw}", "/api/v1/reports/shared/{$raw}/live", "/api/v1/reports/shared/{$raw}/live/content/x",
            "/api/v1/reports/shared/{$raw}/live/platform/meta", "/api/v1/reports/shared/{$raw}/download/pdf",
            "/api/v1/reports/shared/{$raw}/download/csv", "/api/v1/reports/shared/{$raw}/download/xlsx",
            "/api/v1/reports/shared/{$raw}/branding", "/api/v1/reports/shared/{$raw}/branding/logo",
            "/api/v1/reports/shared/{$raw}/attribution", "/api/v1/reports/shared/{$raw}/widgets",
            "/api/v1/reports/shared/{$raw}/creatives", "/api/v1/reports/shared/{$raw}/creatives/summary",
            "/api/v1/reports/shared/{$raw}/creatives/comparison", "/api/v1/reports/shared/{$raw}/creatives/abc",
            "/r/{$raw}", "/r/{$raw}/preview.png",
        ];
    }

    public function test_a_paused_link_is_closed_at_every_door_and_resumes_on_the_same_url(): void
    {
        $r = $this->report();
        [$share, $raw] = $this->link($r);
        $url = $this->base($r)."/shares/{$share->getKey()}";

        $this->as($this->owner)->postJson("{$url}/pause")->assertOk();
        foreach ($this->doors($raw) as $door) {
            $this->assertSame(404, $this->get($door)->getStatusCode(), "paused link still answers at {$door}");
        }

        $this->postJson("{$url}/resume")->assertOk();
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk();
        $this->assertSame($share->token_hash, $share->fresh()->token_hash);
        $this->assertSame(1, ReportShare::query()->count(), 'resuming made no replacement link');
    }

    public function test_a_print_token_minted_for_a_link_dies_with_the_pause_at_every_print_route(): void
    {
        $r = $this->report();
        [$share] = $this->link($r);
        $tok = 'tok-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $tok), [
            'report_id' => (string) $r->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->getKey(),
        ], 600);

        $share->update(['paused_at' => now()]);
        $this->getJson("/api/v1/reports/print/{$tok}")->assertStatus(404);
        $this->getJson("/api/v1/reports/print/{$tok}/logo")->assertStatus(404);
        $this->getJson("/api/v1/reports/print/{$tok}/evidence/".Str::uuid())->assertStatus(404);
    }

    public function test_a_revoked_link_is_never_revived_by_resume_renew_edit_or_convert(): void
    {
        $r = $this->report();
        [$share, $raw] = $this->link($r);
        $url = $this->base($r)."/shares/{$share->getKey()}";
        $this->as($this->owner);
        $this->postJson("{$url}/revoke")->assertOk();

        $this->postJson("{$url}/resume")->assertStatus(409);
        $this->postJson("{$url}/renew", ['expires_at' => now()->addDays(3)->toDateString()])->assertStatus(409);
        $this->patchJson($url, ['watermark' => true])->assertStatus(409);
        $this->postJson("{$url}/convert", ['to' => 'live', 'confirm' => true])->assertStatus(409);
        $this->assertNotNull($share->fresh()->revoked_at);
        $this->assertNull($share->fresh()->paused_at);
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertStatus(404);
    }

    public function test_pause_and_resume_need_the_share_permission_and_the_right_report(): void
    {
        $r = $this->report();
        [$share] = $this->link($r);
        $url = $this->base($r)."/shares/{$share->getKey()}";
        $this->as($this->userWith(['reports.view', 'reports.create'], 'noshare'))->postJson("{$url}/pause")->assertForbidden();
        $this->as($this->owner)->postJson($this->base($this->report(['name' => 'O']))."/shares/{$share->getKey()}/pause")->assertNotFound();
        $this->assertNull($share->fresh()->paused_at);
    }
}
