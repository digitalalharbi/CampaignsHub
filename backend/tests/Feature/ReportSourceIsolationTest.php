<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Models\ReportVersion;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportSourceContainment;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Widgets\Services\SharedWidgetData;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BindsSeededAccounts;
use Tests\TestCase;
use ZipArchive;

/**
 * REPORT-SOURCE-ISOLATION-001 — a report holds the sources it was made for, and nothing beside them.
 *
 * The Owner opened a stored report made for ONE ad account and found content of another. Its figures, platforms and account
 * rows were that account's; its content section was the whole project's, because the generator never handed the account axis to
 * the content reader. The world below is that case and the ones next to it:
 *
 *   - project A (the report's), with account X (chosen), account Y (same project, same platform, NOT chosen — the Owner's case)
 *     and account S, which is ALSO bound to project B (a shared account);
 *   - project B of the same tenant, with its own campaigns and content under S;
 *   - a creative filed under A whose campaign belongs to B;
 *   - a second tenant with its own project, account and content.
 *
 * Every foreign row carries a label, ids and figures that appear nowhere else, so «not in the payload» is a search of the whole
 * serialised document, not of the lists a reader happens to know about.
 */
final class ReportSourceIsolationTest extends TestCase
{
    use BindsSeededAccounts;
    use RefreshDatabase;

    /** This world states its own bindings: one account on two projects is the case, and the automatic binder would not make it. */
    protected bool $selectsSeededAccounts = false;

    private const FROM = '2026-10-01';

    private const TO = '2026-10-31';

    private const DAY = '2026-10-10';

    private Tenant $tenant;

    private Project $a;

    private Project $b;

    private User $owner;

    private string $x;

    private string $y;

    private string $s;

    private string $foreignAccount;

    /** @var array<string, list<string>> label => every string that identifies that set of rows */
    private array $needles = [];

    /** @var array<string, array{campaign: string, external: string, creative: string}> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency one', 'slug' => 'iso-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'Project A', 'status' => 'active']);
        $this->b = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'Project B', 'status' => 'active']);

        $other = Tenant::create(['name' => 'Agency two', 'slug' => 'iso2-'.uniqid(), 'status' => 'active']);
        $otherWs = (string) Str::uuid();
        DB::table('client_workspaces')->insert(['id' => $otherWs, 'tenant_id' => $other->getKey(), 'name' => 'Z', 'slug' => 'z-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $z = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $z, 'tenant_id' => $other->getKey(), 'client_workspace_id' => $otherWs, 'name' => 'Project Z', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->x = $this->bindSeededAccount((string) $this->a->getKey(), null, 'snapchat');
        $this->y = $this->bindSeededAccount((string) $this->a->getKey(), null, 'snapchat');
        $this->s = $this->bindSeededAccount((string) $this->a->getKey(), null, 'snapchat');
        $this->bindSeededAccount((string) $this->b->getKey(), $this->s, 'snapchat');
        $this->foreignAccount = $this->bindSeededAccount($z, null, 'snapchat');

        $ta = (string) $this->tenant->getKey();
        $this->seedSet('KEEPX', $ta, (string) $this->a->getKey(), (string) $ws->getKey(), $this->x, 111.5, 11.5);
        $this->seedSet('KEEPSA', $ta, (string) $this->a->getKey(), (string) $ws->getKey(), $this->s, 205.5, 20.5);
        $this->seedSet('LEAKY', $ta, (string) $this->a->getKey(), (string) $ws->getKey(), $this->y, 3333.25, 3337.75);
        $this->seedSet('LEAKSB', $ta, (string) $this->b->getKey(), (string) $ws->getKey(), $this->s, 4444.25, 4447.75);
        $this->seedSet('LEAKZ', (string) $other->getKey(), $z, $otherWs, $this->foreignAccount, 5555.25, 5557.75);

        // Content filed under project A whose campaign — unified and external — belongs to project B.
        $cross = (string) Str::uuid();
        DB::table('external_creatives')->insert([
            'id' => $cross, 'tenant_id' => $ta, 'project_id' => $this->a->getKey(), 'provider' => 'snapchat',
            'external_campaign_id' => $this->ids['LEAKSB']['external'], 'campaign_id' => $this->ids['LEAKSB']['campaign'],
            'external_creative_id' => 'cr-LEAKCROSS', 'name' => 'LEAKCROSS-creative', 'format' => 'image',
            'asset_url' => 'https://cdn.example/LEAKCROSS.jpg', 'last_active_at' => Carbon::parse(self::DAY), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $ta, 'project_id' => $this->a->getKey(), 'creative_id' => $cross,
            'campaign_id' => $this->ids['LEAKSB']['campaign'], 'metric_date' => self::DAY, 'spend' => 6667.75, 'impressions' => 900, 'clicks' => 9,
            'conversions' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->needles['LEAKCROSS'] = ['LEAKCROSS', $cross, '6667.75'];

        app(ProjectContext::class)->setProjectId((string) $this->a->getKey());
        $this->owner = $this->userWith(Permission::pluck('key')->all(), 'owner');
    }

    private function seedSet(string $label, string $tenant, string $project, string $workspace, string $account, float $spend, float $creativeSpend): void
    {
        $base = ['tenant_id' => $tenant, 'project_id' => $project, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        $external = (string) Str::uuid();
        $creative = (string) Str::uuid();

        DB::table('unified_campaigns')->insert($base + [
            'id' => $campaign, 'client_workspace_id' => $workspace, 'name' => "{$label}-campaign", 'objective' => 'sales',
            'objective_source' => 'declared', 'status' => 'active', 'platforms' => json_encode(['snapchat']),
        ]);
        DB::table('external_campaigns')->insert($base + [
            'id' => $external, 'external_account_id' => $account, 'unified_campaign_id' => $campaign, 'provider' => 'snapchat',
            'external_id' => "ext-{$label}", 'name' => "{$label}-external", 'status' => 'active', 'objective' => 'sales',
        ]);
        foreach (['spend' => $spend, 'impressions' => 5000, 'clicks' => 60, 'conversions' => 4] as $key => $value) {
            DB::table('daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account, 'external_campaign_id' => $external,
                'unified_campaign_id' => $campaign, 'provider' => 'snapchat', 'metric_key' => $key, 'metric_date' => self::DAY,
                'value' => $value, 'project_currency' => 'SAR', 'original_currency' => 'SAR', 'original_amount' => $value,
            ]);
        }
        DB::table('external_creatives')->insert($base + [
            'id' => $creative, 'external_campaign_id' => $external, 'campaign_id' => $campaign, 'provider' => 'snapchat',
            'external_creative_id' => "cr-{$label}", 'name' => "{$label}-creative", 'format' => 'image',
            'asset_url' => "https://cdn.example/{$label}.jpg", 'last_active_at' => Carbon::parse(self::DAY),
        ]);
        DB::table('creative_daily_metrics')->insert($base + [
            'id' => (string) Str::uuid(), 'creative_id' => $creative, 'campaign_id' => $campaign, 'metric_date' => self::DAY,
            'spend' => $creativeSpend, 'impressions' => 1200, 'clicks' => 20, 'conversions' => 2,
        ]);

        $this->ids[$label] = ['campaign' => $campaign, 'external' => $external, 'creative' => $creative];
        $this->needles[$label] = [$label, $campaign, $external, $creative, (string) $spend, (string) $creativeSpend];
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

    private function as(User $user): self
    {
        $this->actingAs($user, 'sanctum')->withHeader('X-Project-Id', (string) $this->a->getKey());

        return $this;
    }

    private function base(?Report $report = null): string
    {
        return "/api/v1/projects/{$this->a->getKey()}/reports".($report === null ? '' : '/'.$report->getKey());
    }

    /** @param  list<string>  $accounts */
    private function report(array $accounts, array $over = []): Report
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->a->getKey());

        return Report::create($over + [
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->a->getKey(), 'name' => 'October', 'type' => 'executive',
            'form' => 'detailed', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => self::FROM, 'period_end' => self::TO, 'scope' => ['account_ids' => $accounts], 'data' => [],
            'created_by' => $this->owner->id,
        ]);
    }

    private function generate(Report $report): Report
    {
        (new GenerateReportJob((string) $report->getKey()))->handle(app(ReportGenerator::class));
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->a->getKey());

        return Report::withoutGlobalScopes()->findOrFail($report->getKey());
    }

    private static function text(mixed $payload): string
    {
        return is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<string>  $kept  labels that MUST be present, so the surface is shown to carry content at all
     * @param  list<string>  $foreign  labels none of whose names, ids or figures may be present
     */
    private function assertIsolated(mixed $payload, array $kept, array $foreign, string $surface): void
    {
        $text = self::text($payload);

        foreach ($kept as $label) {
            $this->assertStringContainsString($label, $text, "«{$surface}» does not carry the chosen source {$label}, so its silence about the others proves nothing.");
        }
        foreach ($foreign as $label) {
            foreach ($this->needles[$label] as $needle) {
                $this->assertStringNotContainsString($needle, $text, "«{$surface}» carries «{$needle}» of {$label}, a source the report was never made for.");
            }
        }
    }

    /** @return list<string> */
    private function everythingBut(string ...$kept): array
    {
        return array_values(array_diff(array_keys($this->needles), $kept));
    }

    // ---------------------------------------------------------------- the stored snapshot

    public function test_the_owners_case_one_account_chosen_and_the_other_accounts_content_stays_out_of_the_snapshot(): void
    {
        $report = $this->generate($this->report([$this->x]));
        $data = (array) $report->data;

        $this->assertIsolated($data, ['KEEPX'], $this->everythingBut('KEEPX'), 'stored data');
        $this->assertSame(1, $data['creatives_in_scope'], 'the count of what ran describes another set than the lists do');
        $this->assertSame([$this->ids['KEEPX']['creative']], array_column($data['ads_roster'], 'id'));
        $this->assertEqualsWithDelta(111.5, (float) $data['kpis']['spend'], 0.001);
    }

    public function test_a_shared_account_brings_only_the_reports_own_projects_rows_and_content_filed_under_a_foreign_campaign_stays_out(): void
    {
        $report = $this->generate($this->report([$this->x, $this->s]));
        $data = (array) $report->data;

        $this->assertIsolated($data, ['KEEPX', 'KEEPSA'], $this->everythingBut('KEEPX', 'KEEPSA'), 'stored data');
        $this->assertSame(2, $data['creatives_in_scope']);
        $this->assertEqualsWithDelta(317.0, (float) $data['kpis']['spend'], 0.001, 'the totals are not the two chosen accounts inside this project');
    }

    // ---------------------------------------------------------------- every surface a stored report leaves by

    public function test_the_shared_page_the_print_document_the_csv_and_the_xlsx_carry_the_same_sources(): void
    {
        $report = $this->generate($this->report([$this->x, $this->s]));
        // The optional per-platform section of the PDF reads the content again at print time; it is switched on so it is covered.
        $report->forceFill(['config' => array_merge((array) $report->config, ['breakdowns' => ['pdf' => ['platform_drilldown' => true]]])])->save();
        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true], null);
        $foreign = $this->everythingBut('KEEPX', 'KEEPSA');

        $page = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data');
        $this->assertSame('snapshot', $page['mode']);
        $this->assertIsolated($page, ['KEEPX', 'KEEPSA'], $foreign, 'shared page');

        $token = 'tok-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->getKey(),
        ], 600);
        $print = $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json();
        $this->assertIsolated($print, ['KEEPX', 'KEEPSA'], $foreign, 'print document');
        $drilldown = self::find($print, 'platform_drilldowns');
        $this->assertNotEmpty($drilldown, 'the per-platform section was not printed, so it was not checked');
        $this->assertIsolated($drilldown, ['KEEPX', 'KEEPSA'], $foreign, 'print drill-down content');

        $csv = $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent();
        $this->assertIsolated($csv, [], $foreign, 'csv');

        $xlsx = $this->get("/api/v1/reports/shared/{$raw}/download/xlsx")->assertOk()->streamedContent();
        $this->assertIsolated(self::xlsxText($xlsx), [], $foreign, 'xlsx');
    }

    /** The first value stored under `$key`, at any depth. */
    private static function find(mixed $node, string $key): mixed
    {
        if (! is_array($node)) {
            return null;
        }
        if (array_key_exists($key, $node)) {
            return $node[$key];
        }
        foreach ($node as $value) {
            if (($found = self::find($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function xlsxText(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iso').'.xlsx';
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true, 'the download is not a spreadsheet');
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $text .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);
        self::assertNotSame('', $text);

        return $text;
    }

    public function test_a_live_link_over_the_same_sources_lists_the_same_content_however_the_request_is_shaped(): void
    {
        $report = $this->report([$this->x, $this->s]);
        [, $raw] = app(ShareService::class)->create($report, ['scope' => [
            'project_id' => (string) $this->a->getKey(), 'campaign_ids' => [], 'account_ids' => [$this->x, $this->s], 'dynamic_campaigns' => true,
            'providers' => [], 'earliest' => self::FROM, 'latest' => self::TO,
        ]], null);
        $foreign = $this->everythingBut('KEEPX', 'KEEPSA');
        $window = 'from='.self::FROM.'&to='.self::TO;

        $live = $this->getJson("/api/v1/reports/shared/{$raw}/live?{$window}")->assertOk()->json('data');
        $this->assertIsolated($live, ['KEEPX', 'KEEPSA'], $foreign, 'live payload');
        $this->assertSame(2, $live['creatives_in_scope']);

        // A reader who writes the other project, its campaign, the unchosen account and nonsense into the address gets no more.
        $tampered = "/api/v1/reports/shared/{$raw}/live?{$window}"
            .'&project_id='.$this->b->getKey().'&project_ids[]='.$this->b->getKey()
            .'&account_ids[]='.$this->y.'&account_ids[]='.$this->foreignAccount
            .'&campaign_ids[]='.$this->ids['LEAKSB']['campaign'].'&campaign_ids[]='.$this->ids['LEAKY']['campaign']
            .'&scope=&scope[account_ids]=';
        $this->assertIsolated($this->getJson($tampered)->assertOk()->json('data'), [], $foreign, 'live payload, tampered');

        $library = $this->getJson("/api/v1/reports/shared/{$raw}/creatives?{$window}&campaign_ids[]=".$this->ids['LEAKSB']['campaign'])->json();
        $this->assertIsolated($library, [], $foreign, 'live content library, tampered');
    }

    // ---------------------------------------------------------------- the operator's own doors

    public function test_the_builder_preview_a_duplicate_and_a_regenerate_stay_inside_the_sources(): void
    {
        $report = $this->generate($this->report([$this->x]));
        $foreign = $this->everythingBut('KEEPX');

        $preview = $this->as($this->owner)->getJson($this->base($report))->assertOk()->json('data');
        $this->assertIsolated($preview, ['KEEPX'], $foreign, 'builder preview');

        $copy = $this->as($this->owner)->postJson($this->base($report).'/duplicate', ['name' => 'Copy'])->assertCreated()->json('data');
        $stored = Report::withoutGlobalScopes()->findOrFail($copy['id']);
        $this->assertSame(['account_ids' => [$this->x]], $stored->scope, 'a copy names other sources than the report it was made from');
        $this->assertIsolated((array) $stored->data, ['KEEPX'], $foreign, 'duplicate, stored data');
        if (is_string($copy['link']['token'] ?? null)) {
            $this->assertIsolated($this->getJson('/api/v1/reports/shared/'.$copy['link']['token'])->assertOk()->json('data'), ['KEEPX'], $foreign, 'duplicate, shared page');
        }

        $this->as($this->owner)->postJson($this->base($report).'/regenerate', ['confirm' => true])->assertOk();
        $rebuilt = Report::withoutGlobalScopes()->findOrFail($report->getKey());
        $this->assertSame(['account_ids' => [$this->x]], $rebuilt->scope, 'a rebuild widened the sources');
        $this->assertIsolated((array) $rebuilt->data, ['KEEPX'], $foreign, 'regenerated data');
        $this->assertSame(1, ReportVersion::withoutGlobalScopes()->where('report_id', $report->getKey())->count(), 'the copy a rebuild replaced was not kept');
    }

    public function test_converting_a_link_between_snapshot_and_live_keeps_it_inside_the_sources(): void
    {
        $report = $this->generate($this->report([$this->x]));
        [$share, $raw] = app(ShareService::class)->create($report, [], null);
        $foreign = $this->everythingBut('KEEPX');
        $convert = $this->base($report)."/shares/{$share->getKey()}/convert";

        $this->as($this->owner)->postJson($convert, ['to' => 'live', 'confirm' => true])->assertOk();
        $this->assertTrue(ReportShare::withoutGlobalScopes()->findOrFail($share->getKey())->isLive());
        $live = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=".self::FROM.'&to='.self::TO)->assertOk()->json('data');
        $this->assertIsolated($live, ['KEEPX'], $foreign, 'converted to live');

        $this->as($this->owner)->postJson($convert, ['to' => 'snapshot', 'confirm' => true])->assertOk();
        $page = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data');
        $this->assertSame('snapshot', $page['mode']);
        $this->assertIsolated($page, ['KEEPX'], $foreign, 'converted back to snapshot');
    }

    public function test_a_link_with_no_scope_of_its_own_is_as_narrow_as_its_report_for_the_sections_that_answer_for_a_whole_project(): void
    {
        $opts = ['settings' => ['sections' => ['attribution' => true]]];

        $narrow = $this->generate($this->report([$this->x]));
        [$share, $raw] = app(ShareService::class)->create($narrow, $opts, null);
        $this->assertNull($share->scope, 'the link has a ceiling of its own, so this asserts nothing about the report\'s');
        $this->assertFalse($this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.sections.attribution'), 'a whole-project section is offered on a report made for one account');
        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertStatus(404);
        $this->assertSame([], app(SharedWidgetData::class)->forShare($share, $narrow));

        // A report whose sources are every account the project selects is not narrower than it, and keeps the section.
        $whole = $this->generate($this->report([$this->x, $this->y, $this->s]));
        [, $wide] = app(ShareService::class)->create($whole, $opts, null);
        $this->assertTrue($this->getJson("/api/v1/reports/shared/{$wide}")->assertOk()->json('data.sections.attribution'));
    }

    // ---------------------------------------------------------------- what may be saved at all

    public function test_a_scope_is_verified_when_it_is_saved_on_creation_and_on_every_edit(): void
    {
        $body = ['name' => 'New', 'type' => 'executive', 'period_start' => self::FROM, 'period_end' => self::TO];
        $refused = [
            'no sources at all' => [],
            'an empty account list' => ['account_ids' => []],
            'an account of another tenant' => ['account_ids' => [$this->x, $this->foreignAccount]],
            'another project named beside its own account' => ['account_ids' => [$this->x], 'project_ids' => [(string) $this->b->getKey()]],
            'a campaign of the other project under a shared account' => ['account_ids' => [$this->s], 'campaign_ids' => [$this->ids['LEAKSB']['campaign']]],
            'a campaign of an account that was not chosen' => ['account_ids' => [$this->x], 'campaign_ids' => [$this->ids['LEAKY']['campaign']]],
        ];

        foreach ($refused as $case => $scope) {
            $this->as($this->owner)->postJson($this->base(), $body + ($scope === [] ? [] : ['scope' => $scope]))->assertStatus(422);
            $this->assertSame(0, Report::withoutGlobalScopes()->where('name', 'New')->count(), "creation accepted {$case}");
        }

        $report = $this->generate($this->report([$this->x, $this->s]));
        foreach ($refused as $case => $scope) {
            $this->as($this->owner)->putJson($this->base($report).'/scope', ['scope' => $scope])->assertStatus(422);
            $this->assertSame(['account_ids' => [$this->x, $this->s]], Report::withoutGlobalScopes()->findOrFail($report->getKey())->scope, "an edit accepted {$case}");
        }

        // A valid narrowing is applied to the data, not only to the saved choice.
        $this->as($this->owner)->putJson($this->base($report).'/scope', ['scope' => ['account_ids' => [$this->x]]])->assertOk();
        $narrowed = Report::withoutGlobalScopes()->findOrFail($report->getKey());
        $this->assertSame(['account_ids' => [$this->x]], $narrowed->scope);
        $this->assertIsolated((array) $narrowed->data, ['KEEPX'], $this->everythingBut('KEEPX'), 'data after a scope edit');
        $this->assertEqualsWithDelta(111.5, (float) $narrowed->data['kpis']['spend'], 0.001);
    }

    /**
     * Each source filter moves the rows AND the totals; a filter that only changed a label would pass a test that looked at one list.
     */
    public function test_each_source_filter_changes_the_rows_and_the_totals_it_names(): void
    {
        $spend = fn (array $scope): float => (float) ((array) $this->generate($this->report([], ['scope' => $scope]))->data)['kpis']['spend'];
        $content = fn (array $scope): array => array_column((array) ((array) $this->generate($this->report([], ['scope' => $scope]))->data)['ads_roster'], 'name');

        // Accounts.
        $this->assertEqualsWithDelta(205.5, $spend(['account_ids' => [$this->s]]), 0.001);
        $this->assertSame(['KEEPSA-creative'], $content(['account_ids' => [$this->s]]));
        $this->assertEqualsWithDelta(3650.25, $spend(['account_ids' => [$this->x, $this->s, $this->y]]), 0.001);

        // Campaigns, inside the chosen accounts.
        $one = ['account_ids' => [$this->x, $this->s], 'campaign_ids' => [$this->ids['KEEPSA']['campaign']]];
        $this->assertEqualsWithDelta(205.5, $spend($one), 0.001);
        $this->assertSame(['KEEPSA-creative'], $content($one));

        // Providers: every row here is Snapchat, so naming another platform leaves nothing, and naming Snapchat leaves it all.
        $this->assertEqualsWithDelta(0.0, $spend(['account_ids' => [$this->x, $this->s], 'providers' => ['meta']]), 0.001);
        $this->assertSame([], $content(['account_ids' => [$this->x, $this->s], 'providers' => ['meta']]));
        $this->assertEqualsWithDelta(317.0, $spend(['account_ids' => [$this->x, $this->s], 'providers' => ['snapchat']]), 0.001);

        // Projects: the axis can only ever say the report's own project, whatever a stored value claims.
        $this->assertEqualsWithDelta(111.5, $spend(['account_ids' => [$this->x], 'project_ids' => [(string) $this->b->getKey()]]), 0.001);
        $this->assertSame(['KEEPX-creative'], $content(['account_ids' => [$this->x, $this->s], 'project_ids' => [(string) $this->b->getKey()], 'campaign_ids' => [$this->ids['KEEPX']['campaign'], $this->ids['LEAKSB']['campaign']]]));
    }

    // ---------------------------------------------------------------- a copy stored before the fix

    public function test_a_copy_stored_with_foreign_content_is_served_without_it_and_is_not_rewritten(): void
    {
        /*
         * What the generator stored before the fix: the figures of the chosen account, and the content of every account of the
         * project. Built from two real generations rather than by hand, so every content-bearing key the document has is in it.
         */
        $data = (array) $this->generate($this->report([$this->x]))->data;
        $figures = ExportReadinessGate::checksum($data);
        $wide = (array) $this->generate($this->report([$this->x, $this->y]))->data;
        $content = ['ads', 'ads_roster', 'ads_groups', 'ads_platform_groups', 'ads_reading', 'ads_level', 'creatives_in_scope', 'creatives_unmeasured', 'creatives_withheld', 'slides'];
        foreach ($content as $key) {
            $data[$key] = $wide[$key] ?? null;
        }
        $this->assertSame($figures, ExportReadinessGate::checksum($data), 'the fixture changed a figure; it must only differ in content');
        $this->assertStringContainsString('LEAKY', self::text($data), 'the fixture holds no foreign content, so containing it proves nothing');

        $report = $this->report([$this->x], ['data' => $data, 'generated_at' => '2026-11-01 10:00:00']);
        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true], null);

        $page = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data');
        $this->assertIsolated($page, ['KEEPX'], ['LEAKY'], 'shared page of an older copy');

        $served = app(ReportSourceContainment::class)->apply($data, $report);
        $this->assertSame(1, $served['creatives_in_scope'], 'the document still counts the content it no longer lists');
        $this->assertSame(1, $served['creatives_source_contained']);
        $this->assertNull($served['ads_reading'], 'a sentence written about the removed content is still served');

        $this->assertIsolated($this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent(), [], ['LEAKY'], 'csv of an older copy');
        $this->assertIsolated(self::xlsxText($this->get("/api/v1/reports/shared/{$raw}/download/xlsx")->assertOk()->streamedContent()), [], ['LEAKY'], 'xlsx of an older copy');

        $token = 'tok-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->getKey(),
        ], 600);
        $this->assertIsolated($this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json(), ['KEEPX'], ['LEAKY'], 'print of an older copy');

        $this->assertIsolated($this->as($this->owner)->getJson($this->base($report))->assertOk()->json('data'), ['KEEPX'], ['LEAKY'], 'builder preview of an older copy');

        // The read-only audit counts exactly what the served document left out, and prints no name, token or full id.
        Artisan::call('reports:source-isolation-audit', ['--json' => true]);
        $audit = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $audit['summary']['reports_holding_outside_content']);
        $this->assertSame(1, $audit['summary']['creatives_outside']);
        $this->assertSame(1, $audit['reports'][0]['snapshot_links']);
        $this->assertSame(substr((string) $report->getKey(), 0, 8), $audit['reports'][0]['report']);
        $this->assertStringNotContainsString('LEAKY', Artisan::output());
        $this->assertStringNotContainsString($raw, Artisan::output());

        $this->assertEquals($data, Report::withoutGlobalScopes()->findOrFail($report->getKey())->data, 'the stored copy was rewritten; containment is a read, never a write');
    }

    public function test_a_report_that_names_no_account_is_read_back_as_it_was_stored(): void
    {
        $row = ['id' => $this->ids['LEAKY']['creative'], 'name' => 'LEAKY-creative', 'provider' => 'snapchat'];
        $data = ['period' => ['from' => self::FROM, 'to' => self::TO], 'currency' => 'SAR', 'objective' => 'sales', 'kpis' => ['spend' => 1.0], 'ads_roster' => [$row]];
        $report = $this->report([], ['scope' => null, 'data' => $data]);

        $this->assertSame($data, app(ReportSourceContainment::class)->apply($data, $report));
    }
}
