<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportExportJob;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Models\ReportVersion;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\GrantsMemberships;
use Tests\Concerns\SeedsReportAccount;
use Tests\TestCase;

/**
 * Owner correction 2026-11-04, section 8.3 — «تكرار» opens the report editor on an independent DRAFT.
 *
 * Pressing Duplicate creates no report, no link and no generation job. The editor is filled from the original's settings;
 * «حفظ كمسودة» stores a row with status `draft` that no link, token, export or print route can reach; «نشر» re-validates
 * the settings and the scope and only then queues the report. The original is never written.
 */
final class ReportDuplicateDraftTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;
    use SeedsReportAccount;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    /** @var array{account_ids: list<string>} */
    private array $scope;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Dup', 'slug' => 'dup-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->scope = $this->reportScopeFor((string) $this->project->getKey());

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

    /** @param  array<string, mixed>  $over */
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
            'config' => ['branding' => ['prefer' => 'agency'], 'period_mode' => 'fixed', 'generic_names' => ['account']],
            'section_settings' => ['sections' => ['trends' => false]],
            'scope' => $this->scope + ['campaign_ids' => [], 'ad_ids' => []],
        ]);
    }

    private function base(?Report $r = null): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports".($r === null ? '' : '/'.$r->getKey());
    }

    private function as(User $u): self
    {
        $this->actingAs($u, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());

        return $this;
    }

    /**
     * What the editor posts: the prefill the server handed it, with whatever the person changed laid over.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function editorBody(array $draft, array $over = []): array
    {
        return $over + [
            'name' => 'نسخة من August',
            'type' => $draft['type'], 'mode' => $draft['mode'], 'form' => $draft['form'], 'detail_level' => $draft['detail_level'],
            'audience' => $draft['audience'], 'period_start' => $draft['period_start'], 'period_end' => $draft['period_end'],
            'period_mode' => $draft['period_mode'], 'period_kind' => $draft['period_kind'],
            'branding_identity' => $draft['branding_identity'],
            'prepared_for_name' => $draft['prepared_for_name'] ?? '', 'prepared_by_name' => $draft['prepared_by_name'] ?? '',
            'section_settings' => ['sections' => $draft['sections']],
            'config' => ['columns' => $draft['columns']],
            'scope' => $draft['scope'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function prefill(Report $r): array
    {
        return $this->as($this->owner)->postJson($this->base($r).'/duplicate', [])->assertOk()->json('data.draft');
    }

    private function savedDraft(Report $source, array $over = []): Report
    {
        $id = $this->as($this->owner)->postJson($this->base(), $this->editorBody($this->prefill($source), $over + ['draft' => true, 'source_report_id' => (string) $source->getKey()]))
            ->assertCreated()->json('data.id');

        return Report::query()->findOrFail($id);
    }

    // ---------------------------------------------------------------- criterion 1: Duplicate publishes nothing

    public function test_pressing_duplicate_creates_no_report_no_link_and_no_job_and_hands_back_a_prefilled_draft(): void
    {
        $r = $this->report();
        [$src] = app(ShareService::class)->create($r, ['allow_download' => true, 'password' => 'secret1'], null);
        $before = $r->fresh()->toArray();
        $beforeShare = $src->fresh()->toArray();
        $reports = Report::query()->count();

        $res = $this->as($this->owner)->postJson($this->base($r).'/duplicate', [])->assertOk();

        $this->assertSame($reports, Report::query()->count(), 'Duplicate creates no report');
        $this->assertSame(1, ReportShare::query()->count(), 'Duplicate creates no link');
        Queue::assertNothingPushed();
        $this->assertSame(0, AuditLog::query()->where('action', 'report.duplicated')->count(), 'nothing was made, so nothing is recorded as made');

        $res->assertJsonPath('data.source.id', (string) $r->getKey());
        $res->assertJsonPath('data.suggested_name.ar', 'نسخة من August');
        $res->assertJsonPath('data.suggested_name.en', 'Copy of August');
        $draft = $res->json('data.draft');
        $this->assertNull($draft['id'], 'the draft is not a stored row yet');
        $this->assertSame('monthly', $draft['type']);
        $this->assertSame('snapshot', $draft['mode']);
        $this->assertSame('2026-08-01', $draft['period_start']);
        $this->assertSame('agency', $draft['branding_identity']);
        $this->assertFalse($draft['sections']['trends']);
        $this->assertSame($this->scope['account_ids'], $draft['scope']['account_ids']);

        $flat = json_encode($res->json());
        foreach (['token', 'password', 'link', 'url', 'share_id'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret, (string) $flat, "the prefill carries no {$secret}");
        }
        $this->assertEquals($before, $r->fresh()->toArray(), 'the original is unchanged');
        $this->assertEquals($beforeShare, $src->fresh()->toArray(), 'the original link is unchanged');
    }

    public function test_a_live_link_report_a_snapshot_and_an_old_report_all_open_a_draft_and_nothing_else(): void
    {
        $live = $this->report(['name' => 'Live', 'type' => 'live', 'mode' => 'snapshot', 'data' => null, 'scope' => null,
            'config' => ['live' => true, 'metrics' => ['spend'], 'period_mode' => 'rolling', 'period_kind' => 'custom']]);
        app(ShareService::class)->create($live, ['mode' => 'live', 'scope' => [
            'project_id' => (string) $this->project->getKey(), 'account_ids' => $this->scope['account_ids'], 'campaign_ids' => [], 'ad_ids' => [],
            'providers' => ['meta'], 'earliest' => '2026-08-01', 'latest' => '2027-08-01',
        ]], null);
        $snapshot = $this->report(['name' => 'Snap']);
        $old = $this->report(['name' => 'Old', 'scope' => null, 'section_settings' => null, 'config' => null]);
        $reports = Report::query()->count();
        $shares = ReportShare::query()->count();

        $fromLive = $this->prefill($live);
        $this->assertSame('live', $fromLive['mode'], 'a live link report opens as Live');
        $this->assertSame('custom', $fromLive['type'], 'the editor offers no «live» type; the mode says it');
        $this->assertSame($this->scope['account_ids'], $fromLive['scope']['account_ids'], 'its sources are the accounts its link was bounded to');
        $this->assertSame('rolling', $fromLive['period_mode']);

        $this->assertSame('snapshot', $this->prefill($snapshot)['mode']);

        $fromOld = $this->prefill($old);
        $this->assertNull($fromOld['scope'], 'no scope in, no scope out: never widened or invented');
        $this->assertSame('detailed', $fromOld['detail_level']);

        $this->assertSame($reports, Report::query()->count());
        $this->assertSame($shares, ReportShare::query()->count());
        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- criteria 2 and 3: save, reopen, publish, cancel

    public function test_save_as_draft_stores_an_unpublished_copy_with_exactly_the_sources_the_editor_sent(): void
    {
        $r = $this->report();
        $before = $r->fresh()->toArray();

        $draft = $this->savedDraft($r);

        $this->assertSame('draft', $draft->status);
        $this->assertTrue($draft->isDraft());
        $this->assertNull($draft->generated_at);
        $this->assertTrue(empty($draft->data), 'no document is copied or generated for a draft');
        $this->assertSame('نسخة من August', $draft->name);
        $this->assertSame($this->scope['account_ids'], $draft->scope['account_ids'], 'the sources are stored as the editor showed them');
        $this->assertSame(['account'], $draft->config['generic_names'], 'a copy is never more open than the report it came from');
        $this->assertSame('agency', $draft->config['branding']['prefer']);
        $this->assertFalse($draft->section_settings['sections']['trends']);
        $this->assertSame(0, ReportShare::query()->where('report_id', $draft->getKey())->count());
        Queue::assertNothingPushed();
        $this->assertSame(1, AuditLog::query()->where('action', 'report.duplicated')->where('entity_id', (string) $draft->getKey())->count());
        $this->assertEquals($before, $r->fresh()->toArray(), 'the original is unchanged');
    }

    public function test_a_saved_draft_reopens_in_the_editor_and_its_edits_touch_the_copy_only(): void
    {
        $r = $this->report();
        $before = $r->fresh()->toArray();
        $draft = $this->savedDraft($r);

        $state = $this->as($this->owner)->getJson($this->base($draft).'/draft')->assertOk()->json('data.draft');
        $this->assertSame((string) $draft->getKey(), $state['id']);
        $this->assertSame('نسخة من August', $state['name']);

        $second = $this->reportScopeFor((string) $this->project->getKey());
        $this->as($this->owner)->putJson($this->base($draft).'/draft', $this->editorBody($state, [
            'name' => 'September draft', 'type' => 'weekly', 'mode' => 'live', 'detail_level' => 'summary',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'period_mode' => 'fixed', 'period_kind' => 'custom',
            'branding_identity' => null, 'prepared_for_name' => 'Client Co',
            'section_settings' => ['sections' => ['trends' => true, 'funnel' => false]],
            'config' => ['columns' => ['platforms' => ['spend']]],
            'scope' => $second,
        ]))->assertOk()->assertJsonPath('data.status', 'draft');

        $draft->refresh();
        $this->assertSame(['September draft', 'weekly', 'live', 'executive_summary'], [$draft->name, $draft->type, $draft->mode, $draft->form]);
        $this->assertSame('2026-09-30', $draft->period_end->toDateString());
        $this->assertSame($second['account_ids'], $draft->scope['account_ids']);
        $this->assertTrue($draft->section_settings['sections']['trends']);
        $this->assertSame(['platforms' => ['spend']], $draft->config['columns']);
        $this->assertArrayNotHasKey('prefer', $draft->config['branding'], '«automatic» clears the identity choice the copy started with');
        $this->assertSame('Client Co', $draft->config['branding']['prepared_for_name']);
        $this->assertSame(['account'], $draft->config['generic_names'], 'what the editor does not show is kept as it was');
        Queue::assertNothingPushed();
        $this->assertEquals($before, $r->fresh()->toArray(), 'the original is unchanged');

        // Only a draft is edited this way: a published report keeps its own edit paths.
        $this->as($this->owner)->putJson($this->base($r).'/draft', $this->editorBody($state))->assertStatus(409);
        $this->as($this->owner)->getJson($this->base($r).'/draft')->assertStatus(409);
        $this->as($this->owner)->postJson($this->base($r).'/publish', $this->editorBody($state))->assertStatus(409);
        $this->assertEquals($before, $r->fresh()->toArray());
    }

    public function test_publish_makes_an_independent_report_queues_it_once_and_creates_no_link(): void
    {
        $r = $this->report();
        [$src, $raw] = app(ShareService::class)->create($r, ['allow_download' => true], null);
        $before = $r->fresh()->toArray();
        $draft = $this->savedDraft($r);
        $state = $this->as($this->owner)->getJson($this->base($draft).'/draft')->json('data.draft');

        $this->as($this->owner)->postJson($this->base($draft).'/publish', $this->editorBody($state, ['name' => 'August for the board']))
            ->assertOk()->assertJsonPath('data.status', 'processing')->assertJsonPath('data.name', 'August for the board');

        Queue::assertPushed(GenerateReportJob::class, 1);
        Queue::assertPushed(GenerateReportJob::class, fn (GenerateReportJob $job): bool => $job->reportId === (string) $draft->getKey());
        $this->assertSame(0, ReportShare::query()->where('report_id', $draft->getKey())->count(), 'publishing a report needs no link, so none is made');
        $this->assertSame(1, AuditLog::query()->where('action', 'report.published')->where('entity_id', (string) $draft->getKey())->count());

        $this->assertEquals($before, $r->fresh()->toArray(), 'the original is unchanged');
        $this->assertSame(1, ReportShare::query()->where('report_id', $r->getKey())->count());
        $this->assertSame($src->token_hash, $src->fresh()->token_hash);
        $this->assertSame(0, ReportVersion::query()->where('report_id', $r->getKey())->count(), 'the original was not rebuilt');
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk();

        // Published once: it is no longer a draft.
        $this->as($this->owner)->postJson($this->base($draft).'/publish', $this->editorBody($state))->assertStatus(409);
        Queue::assertPushed(GenerateReportJob::class, 1);
    }

    public function test_publishing_straight_from_the_editor_without_saving_first_is_the_same_independent_report(): void
    {
        $r = $this->report();
        $before = $r->fresh()->toArray();

        $id = $this->as($this->owner)->postJson($this->base(), $this->editorBody($this->prefill($r), ['source_report_id' => (string) $r->getKey()]))
            ->assertCreated()->assertJsonPath('data.status', 'processing')->json('data.id');

        $this->assertNotSame((string) $r->getKey(), $id);
        Queue::assertPushed(GenerateReportJob::class, fn (GenerateReportJob $job): bool => $job->reportId === $id);
        $this->assertSame(0, ReportShare::query()->where('report_id', $id)->count());
        $this->assertSame(['account'], Report::query()->findOrFail($id)->config['generic_names']);
        $this->assertEquals($before, $r->fresh()->toArray());
    }

    public function test_cancel_is_the_absence_of_a_save_so_only_the_original_exists(): void
    {
        $r = $this->report();
        $before = $r->fresh()->toArray();
        $this->prefill($r);
        // The editor was closed here. Nothing else is called.
        $this->assertSame(1, Report::query()->count());
        $this->assertSame(0, ReportShare::query()->count());
        Queue::assertNothingPushed();
        $this->assertEquals($before, $r->fresh()->toArray());
    }

    public function test_what_the_editor_does_not_show_travels_with_the_copy_and_survives_its_first_generation(): void
    {
        $hidden = [['id' => 'cover', 'type' => 'cover', 'order' => 1, 'visible' => false]];
        $r = $this->report(['config' => ['generic_names' => ['account'], 'slides' => $hidden, 'platform_order' => ['meta'], 'period_mode' => 'fixed']]);

        // Same form: the original's layout, with what it hides, is the copy's layout.
        $same = $this->savedDraft($r);
        $this->assertSame($hidden, $same->config['slides'], 'a slide hidden on the original is not shown again on its copy');

        // The form changed in the editor: a layout is authored for a form, so the copy gets its own at generation.
        $other = $this->savedDraft($r, ['name' => 'Summary copy', 'detail_level' => 'summary']);
        $this->assertSame('executive_summary', $other->form);
        $this->assertArrayNotHasKey('slides', $other->config);
        $this->assertSame(['account'], $other->config['generic_names']);

        $state = $this->as($this->owner)->getJson($this->base($other).'/draft')->json('data.draft');
        $this->as($this->owner)->postJson($this->base($other).'/publish', $this->editorBody($state, ['name' => 'Summary copy']))->assertOk();
        (new GenerateReportJob((string) $other->getKey()))->handle(app(ReportGenerator::class));
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $built = Report::query()->findOrFail($other->getKey());
        $this->assertSame('completed', $built->status);
        $this->assertNotEmpty($built->config['slides'], 'the generator authored the summary’s own layout');
        $this->assertSame(['account'], $built->config['generic_names'], 'the copy is still no more open than its original once it is built');
        $this->assertSame('fixed', $built->config['period_mode']);
        $this->assertFalse($built->isDraft());
        $this->assertArrayNotHasKey(Report::DRAFT_MARK, $built->config, 'a published report keeps no draft mark');
    }

    // ---------------------------------------------------------------- the source link's preferences, as preferences only

    public function test_the_source_links_preferences_travel_as_preferences_only_and_make_no_link(): void
    {
        $r = $this->report();
        [$src, $srcRaw] = app(ShareService::class)->create($r, [
            'allow_download' => false, 'hide_spend' => true, 'hide_revenue' => true, 'hide_campaign_names' => true, 'watermark' => true,
            'password' => 'secret1', 'expires_at' => now()->addWeek(), 'form' => 'executive_summary',
            'settings' => ['generic_names' => ['campaign'], 'sections' => ['attribution' => true]],
        ], null);
        $beforeShare = $src->fresh()->toArray();

        // The press: the preferences are named in the prefill, and nothing of the link's access is.
        $res = $this->as($this->owner)->postJson($this->base($r).'/duplicate', [])->assertOk();
        $prefs = $res->json('data.draft.share_preferences');
        $this->assertIsArray($prefs, 'the editor is told which link settings the copy starts with');
        $this->assertFalse($prefs['allow_download']);
        $this->assertTrue($prefs['hide_spend']);
        $this->assertTrue($prefs['hide_revenue']);
        $this->assertTrue($prefs['hide_campaign_names']);
        $this->assertTrue($prefs['watermark']);
        $this->assertSame('executive_summary', $prefs['form']);
        $this->assertSame('snapshot', $prefs['mode']);
        $this->assertSame(['campaign'], $prefs['generic_names']);
        $this->assertTrue($prefs['sections']['attribution']);
        $this->assertTrue($prefs['was_locked'], 'that the source asked for a password is said; the password is not');
        $flat = (string) json_encode($res->json());
        foreach ([$srcRaw, (string) $src->getKey(), (string) $src->token_hash, (string) $src->password_hash, 'secret1', 'expires', 'scope_ids'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat, 'nothing of the source link’s access is in the prefill');
        }
        $this->assertSame(1, ReportShare::query()->count(), 'the press made no link');

        // Saved as a draft, reopened, edited and published: the preferences stay with the copy and still no link exists.
        $draft = $this->savedDraft($r);
        $this->assertEquals($prefs, $draft->config['share_preferences']);
        $this->as($this->owner)->getJson($this->base($draft).'/draft')->assertOk()->assertJsonPath('data.draft.share_preferences.hide_revenue', true);
        $state = $this->as($this->owner)->getJson($this->base($draft).'/draft')->json('data.draft');
        $this->as($this->owner)->putJson($this->base($draft).'/draft', $this->editorBody($state, ['name' => 'Edited copy']))->assertOk();
        $this->as($this->owner)->postJson($this->base($draft).'/publish', $this->editorBody($state, ['name' => 'Edited copy']))->assertOk()
            ->assertJsonPath('data.share_preferences.hide_campaign_names', true);
        (new GenerateReportJob((string) $draft->getKey()))->handle(app(ReportGenerator::class));
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $built = Report::query()->findOrFail($draft->getKey());
        $this->assertSame('completed', $built->status);
        $this->assertEquals($prefs, $built->config['share_preferences'], 'the preferences survive the copy’s first generation');
        $this->as($this->owner)->getJson($this->base($built))->assertOk()->assertJsonPath('data.share_preferences.watermark', true);
        $this->assertSame(0, ReportShare::query()->where('report_id', $built->getKey())->count(), 'a preference is not a link: none exists until someone shares');
        $this->assertSame(0, AuditLog::query()->where('action', 'report.shared_link_created')->count());

        // The explicit share act, with what the dialog was prefilled with: a NEW link, its own token, no password and no expiry carried.
        $made = $this->as($this->owner)->postJson($this->base($built).'/shares', array_intersect_key($prefs, array_flip(['allow_download', 'hide_spend', 'hide_revenue', 'hide_campaign_names', 'watermark', 'form', 'mode', 'generic_names', 'sections'])))->assertSuccessful();
        $link = ReportShare::query()->where('report_id', $built->getKey())->sole();
        $this->assertNotSame($srcRaw, $made->json('data.token'));
        $this->assertNotSame($src->token_hash, $link->token_hash);
        $this->assertNull($link->password_hash, 'a password is typed again by the person sharing, never copied');
        $this->assertNull($link->expires_at);
        $this->assertTrue($link->hide_revenue);
        $this->assertTrue($link->hide_campaign_names);
        $this->assertFalse($link->allow_download);
        $this->assertEquals($beforeShare, $src->fresh()->toArray(), 'the original link is unchanged');
    }

    public function test_a_source_without_a_link_carries_no_preferences_and_a_revoked_link_is_not_a_source(): void
    {
        $r = $this->report();
        $this->assertNull($this->prefill($r)['share_preferences'], 'no link, no preferences: the share dialog keeps its own defaults');

        [$gone] = app(ShareService::class)->create($r, ['hide_revenue' => true], null);
        $gone->forceFill(['revoked_at' => now()])->save();
        $this->assertNull($this->prefill($r)['share_preferences'], 'a link that was revoked for good is not what the copy starts from');

        $draft = $this->savedDraft($r);
        $this->assertArrayNotHasKey('share_preferences', (array) $draft->config);
        $this->as($this->owner)->getJson($this->base($draft))->assertOk()->assertJsonPath('data.share_preferences', null);
    }

    // ---------------------------------------------------------------- the published copy on paper

    public function test_the_published_copy_prints_and_exports_as_itself_and_the_original_is_not_rebuilt_or_exported(): void
    {
        $r = $this->report();
        $before = $r->fresh()->toArray();
        $draft = $this->savedDraft($r);
        $state = $this->as($this->owner)->getJson($this->base($draft).'/draft')->json('data.draft');
        $this->as($this->owner)->postJson($this->base($draft).'/publish', $this->editorBody($state, ['name' => 'August on paper', 'section_settings' => ['sections' => ['trends' => false]]]))->assertOk();

        // Unpublished or not yet built, nothing prints: there is no document to print.
        $this->as($this->owner)->postJson($this->base($draft).'/print-token', [])->assertStatus(422);

        (new GenerateReportJob((string) $draft->getKey()))->handle(app(ReportGenerator::class));
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $built = Report::query()->findOrFail($draft->getKey());
        $this->assertSame('completed', $built->status);

        // The PDF renderer's door, both layouts: the copy's own document, under the copy's own name.
        foreach (['presentation', 'document'] as $type) {
            $token = $this->as($this->owner)->postJson($this->base($built).'/print-token', ['type' => $type])->assertOk()->json('data.token');
            $printed = $this->getJson("/api/v1/reports/print/{$token}")->assertOk();
            $printed->assertJsonPath('data.name', 'August on paper')->assertJsonPath('data.type', $type);
            $this->assertSame((string) $built->getKey(), (string) $printed->json('data.report_id'));
            $this->assertNotSame((string) $r->getKey(), (string) $printed->json('data.report_id'), 'the printed document is the copy, not its original');
            $this->assertNotEmpty($printed->json('data.data'), 'the copy has its own stored document');
            $this->assertSame($state['period_start'], $printed->json('data.data.period.from'));
        }

        // The export queue hears of the copy, and only of the copy.
        $this->as($this->owner)->postJson($this->base($built).'/export', ['format' => 'pdf'])->assertStatus(202)->assertJsonPath('data.format', 'pdf');
        Queue::assertPushed(GenerateReportExportJob::class, 1);
        $this->assertSame(1, DB::table('report_exports')->where('report_id', $built->getKey())->count());
        $this->assertSame(0, DB::table('report_exports')->where('report_id', $r->getKey())->count(), 'the original was not exported');

        $this->assertEquals($before, $r->fresh()->toArray(), 'the original is unchanged');
        $this->assertSame(0, ReportVersion::query()->where('report_id', $r->getKey())->count(), 'the original was not rebuilt');
        $this->assertSame(0, ReportShare::query()->count(), 'printing and exporting a copy makes no link');
    }

    // ---------------------------------------------------------------- a draft is not reachable from outside

    public function test_a_draft_cannot_be_reached_by_any_link_token_export_or_rebuild(): void
    {
        $draft = $this->savedDraft($this->report());

        $this->as($this->owner)->postJson($this->base($draft).'/shares', ['allow_download' => true])->assertStatus(409);
        $this->as($this->owner)->postJson($this->base($draft).'/export', ['format' => 'pdf'])->assertStatus(409);
        $this->as($this->owner)->postJson($this->base($draft).'/print-token', [])->assertStatus(422);
        $this->as($this->owner)->postJson($this->base($draft).'/regenerate', ['confirm' => true])->assertStatus(409)->assertJsonPath('errors.code', 'report_is_draft');

        // No door that makes a link opens for a draft, whichever controller is asking.
        try {
            app(ShareService::class)->create($draft, ['allow_download' => true], null);
            $this->fail('a link was made for a draft');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, ReportShare::query()->where('report_id', $draft->getKey())->count(), 'no share row, so no token exists to open');

        // Editing the scope or the sections of a report rebuilds it; on a draft it must not publish it.
        $this->as($this->owner)->putJson($this->base($draft).'/scope', ['scope' => $this->scope])->assertSuccessful();
        $this->as($this->owner)->patchJson($this->base($draft), ['config' => ['columns' => ['platforms' => ['spend']]]])->assertOk();
        $this->assertTrue($draft->fresh()->isDraft(), 'an edit that replaces the config does not take the draft mark with it');
        $this->assertSame('draft', $draft->fresh()->status, 'an edit never takes a report out of draft');
        (new GenerateReportJob((string) $draft->getKey()))->handle(app(ReportGenerator::class));
        $this->assertSame('draft', $draft->fresh()->status, 'a generation job does nothing to a draft');
        $this->assertTrue(empty($draft->fresh()->data));
        $this->assertNull($draft->fresh()->generated_at);

        Queue::assertNotPushed(GenerateReportExportJob::class);
    }

    // ---------------------------------------------------------------- scope is checked again at save and at publish

    public function test_the_scope_is_checked_again_at_save_and_at_publish_and_a_refusal_leaves_the_draft_as_it_was(): void
    {
        $r = $this->report();
        $prefill = $this->prefill($r);
        $foreign = (string) Str::uuid();
        $reports = Report::query()->count();

        // At SAVE: a foreign account is refused and nothing is stored.
        $this->as($this->owner)->postJson($this->base(), $this->editorBody($prefill, ['draft' => true, 'source_report_id' => (string) $r->getKey(), 'scope' => ['account_ids' => [$foreign]]]))->assertStatus(422);
        $this->as($this->owner)->postJson($this->base(), $this->editorBody($prefill, ['draft' => true, 'source_report_id' => (string) $r->getKey(), 'scope' => []]))->assertStatus(422);
        $this->assertSame($reports, Report::query()->count(), 'nothing was made');

        $draft = $this->savedDraft($r);
        $stored = $draft->fresh()->toArray();
        $state = $this->as($this->owner)->getJson($this->base($draft).'/draft')->json('data.draft');

        // At a draft's EDIT.
        $this->as($this->owner)->putJson($this->base($draft).'/draft', $this->editorBody($state, ['scope' => ['account_ids' => [$foreign]]]))->assertStatus(422);
        $this->assertEquals($stored, $draft->fresh()->toArray());

        // At PUBLISH, with a scope that was valid when the draft was saved and no longer is.
        // The account leaves the project: its binding is switched off and its figures are gone.
        DailyMetric::withoutGlobalScopes()->whereIn('external_account_id', $this->scope['account_ids'])->delete();
        DB::table('project_integration_bindings')->where('project_id', $this->project->getKey())->update(['is_active' => false]);
        $this->as($this->owner)->postJson($this->base($draft).'/publish', $this->editorBody($state))->assertStatus(422);
        $this->as($this->owner)->postJson($this->base($draft).'/publish', $this->editorBody($state, ['name' => '']))->assertStatus(422);

        $this->assertEquals($stored, $draft->fresh()->toArray(), 'a refused publish does not lose or half-publish the draft');
        $this->assertSame(0, ReportShare::query()->count());
        Queue::assertNothingPushed();

        // The prefill itself refuses an original whose stored scope names an account that is not the project's.
        $bad = $this->report(['name' => 'Foreign', 'scope' => ['account_ids' => [$foreign], 'campaign_ids' => [], 'ad_ids' => [], 'project_ids' => []]]);
        $this->as($this->owner)->postJson($this->base($bad).'/duplicate', [])->assertStatus(422);
    }

    // ---------------------------------------------------------------- preview

    public function test_the_preview_is_the_published_engine_and_stores_nothing(): void
    {
        $r = $this->report();
        $body = $this->editorBody($this->prefill($r), ['source_report_id' => (string) $r->getKey(), 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'period_kind' => 'custom']);
        $reports = Report::query()->count();

        $preview = $this->as($this->owner)->postJson($this->base().'/draft-preview', $body)->assertOk()->json('data');

        $this->assertSame($reports, Report::query()->count(), 'a preview stores no report');
        $this->assertSame(0, ReportShare::query()->count(), 'and needs no link');
        Queue::assertNothingPushed();
        $this->assertArrayHasKey('report_sections', $preview['data'], 'the sections are resolved by the surfaces’ own resolver');

        // The same settings, published and generated, read through the page the operator previews a real report on.
        $id = $this->as($this->owner)->postJson($this->base(), $body)->assertCreated()->json('data.id');
        $published = Report::query()->findOrFail($id);
        $published->update(['data' => app(ReportGenerator::class)->generate($published), 'status' => 'completed', 'generated_at' => now()]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $page = $this->as($this->owner)->getJson($this->base($published))->assertOk()->json('data.data');

        $this->assertSame($page['report_sections'], $preview['data']['report_sections']);
        $this->assertEquals($page['kpis'] ?? null, $preview['data']['kpis'] ?? null);
        $this->assertEquals($page['period'] ?? null, $preview['data']['period'] ?? null);
        $this->assertEqualsCanonicalizing(array_keys($page), array_keys($preview['data']), 'the same document, key for key');
    }

    // ---------------------------------------------------------------- criterion 4: roles and isolation

    public function test_a_viewer_can_do_none_of_it_and_a_manager_without_share_gets_no_link(): void
    {
        $r = $this->report();
        $draft = $this->savedDraft($r);
        $body = $this->editorBody($this->prefill($r));
        $viewer = $this->userWith(['reports.view'], 'viewer');

        $this->as($viewer)->postJson($this->base($r).'/duplicate', [])->assertForbidden();
        $this->as($viewer)->postJson($this->base(), $body + ['draft' => true, 'source_report_id' => (string) $r->getKey()])->assertForbidden();
        $this->as($viewer)->putJson($this->base($draft).'/draft', $body)->assertForbidden();
        $this->as($viewer)->postJson($this->base($draft).'/publish', $body)->assertForbidden();
        $this->as($viewer)->postJson($this->base().'/draft-preview', $body)->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(2, Report::query()->count());

        $manager = $this->userWith(['reports.view', 'reports.create'], 'manager');
        $this->as($manager)->postJson($this->base($r).'/duplicate', [])->assertOk();
        $this->as($manager)->postJson($this->base($draft).'/publish', $body)->assertOk();
        $this->assertSame(0, ReportShare::query()->count(), 'publishing gives nobody a link');
        $draft->forceFill(['status' => 'completed', 'generated_at' => now(), 'data' => $r->data])->save();
        $this->as($manager)->postJson($this->base($draft).'/shares', ['allow_download' => true])->assertForbidden();
        $this->assertSame(0, ReportShare::query()->count(), 'a link for the published copy needs reports.share');
    }

    public function test_it_never_crosses_tenants_or_projects(): void
    {
        $r = $this->report();
        $draft = $this->savedDraft($r);
        $body = $this->editorBody($this->prefill($r));

        // Another project of the SAME tenant: the original cannot be named as a source there, and the draft cannot be opened there.
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C2', 'slug' => 'c2-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $other = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P2', 'status' => 'active']);
        $otherScope = $this->reportScopeFor((string) $other->getKey());
        $there = "/api/v1/projects/{$other->getKey()}/reports";
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', (string) $other->getKey());
        $this->postJson("{$there}/{$r->getKey()}/duplicate", [])->assertStatus(404);
        $this->postJson($there, ['scope' => $otherScope, 'draft' => true, 'source_report_id' => (string) $r->getKey()] + $body)->assertStatus(404);
        $this->getJson("{$there}/{$draft->getKey()}/draft")->assertStatus(404);
        $this->putJson("{$there}/{$draft->getKey()}/draft", ['scope' => $otherScope] + $body)->assertStatus(404);
        $this->postJson("{$there}/{$draft->getKey()}/publish", ['scope' => $otherScope] + $body)->assertStatus(404);
        // An account of the other project is not a source this project's draft may name.
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->as($this->owner)->putJson($this->base($draft).'/draft', ['scope' => $otherScope] + $body)->assertStatus(422);

        // Another tenant.
        $foreign = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $foreign->getKey());
        $role = Role::create(['tenant_id' => $foreign->getKey(), 'name' => 'o', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $foreign);
        $stranger->assignRole($role);
        $this->holdingTenant((string) $this->tenant->getKey());
        $this->actingAs($stranger, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());
        $this->postJson($this->base($r).'/duplicate', [])->assertStatus(404);
        $this->getJson($this->base($draft).'/draft')->assertStatus(404);
        $this->postJson($this->base($draft).'/publish', $body)->assertStatus(404);

        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->assertSame('draft', Report::query()->findOrFail($draft->getKey())->status);
        $this->assertSame(2, Report::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }
}
