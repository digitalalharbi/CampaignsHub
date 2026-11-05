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
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\HiddenMoney;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Concerns\BindsSeededAccounts;
use Tests\TestCase;
use ZipArchive;

/**
 * REPORT-FILTERS-FUNCTIONAL-001 — every report option acts on the data the server sends, on every surface.
 *
 * The Owner's rule (§19.3, §20.3): an option with no applied and tested backend logic is not implemented. The source filters
 * (accounts, projects, providers, campaigns) are held by {@see ReportSourceIsolationTest}; this holds the rest: the period, the
 * comparison, the objective and the metric set, the currency, every show/hide switch, the form and the audience.
 *
 * One small world whose every figure and name is a string that appears nowhere else, so «hidden» is a search of the whole
 * serialised answer of each surface (page, live payload, drill-downs, content library, print document, CSV, XLSX), never of a
 * list a test happens to know about:
 *
 *   - two ad accounts of one project, Snapchat (a sales campaign with revenue) and Meta (a leads campaign);
 *   - two days inside October, one day in the window before it (the comparison), one day after it (outside every window);
 *   - one creative per campaign with its own figures inside and outside the window.
 */
final class ReportFiltersFunctionalTest extends TestCase
{
    use BindsSeededAccounts;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private const FROM = '2026-10-01';

    private const TO = '2026-10-31';

    private const IN1 = '2026-10-10';

    private const IN2 = '2026-10-20';

    private const BEFORE = '2026-09-15';

    private const AFTER = '2026-11-02';

    /** Every amount of ad spend the world holds inside the window, and their sums. */
    private const SPEND = ['1111.11', '222.22', '1333.33', '3030.3', '404.04', '3434.34', '4767.67', '131.31', '242.42'];

    /** Every amount of revenue inside the window, and its sum. */
    private const REVENUE = ['20000.5', '3000.25', '23000.75', '909.09'];

    /** Figures that belong to the window before, or to days after the window. */
    private const BEFORE_FIGURES = ['717.17', '818.18', '1535.35', '15000.75'];

    private const AFTER_FIGURES = ['9191.91', '9292.92', '99000.99', '8181.81', '123456', '234567'];

    private const CAMPAIGNS = ['QCAMPSNAP', 'QCAMPMETA'];

    private const ACCOUNTS = ['QACCOUNTSNAP', 'QACCOUNTMETA'];

    private const CONTENT = ['QCREATIVESNAP', 'QCREATIVEMETA'];

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    private string $snap;

    private string $meta;

    /** @var array<string, array{campaign: string, external: string, creative: string}> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'flt-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'Project', 'status' => 'active']);

        $this->snap = $this->bindSeededAccount((string) $this->project->getKey(), null, 'snapchat');
        $this->meta = $this->bindSeededAccount((string) $this->project->getKey(), null, 'meta');
        DB::table('external_accounts')->where('id', $this->snap)->update(['name' => 'QACCOUNTSNAP', 'currency' => 'SAR']);
        DB::table('external_accounts')->where('id', $this->meta)->update(['name' => 'QACCOUNTMETA', 'currency' => 'SAR']);

        $this->seedCampaign('SNAP', 'snapchat', $this->snap, 'sales', (string) $ws->getKey(), [
            self::IN1 => ['spend' => 1111.11, 'revenue' => 20000.5, 'impressions' => 50000, 'clicks' => 510, 'conversions' => 41],
            self::IN2 => ['spend' => 222.22, 'revenue' => 3000.25, 'impressions' => 7000, 'clicks' => 73, 'conversions' => 7],
            self::BEFORE => ['spend' => 717.17, 'revenue' => 15000.75, 'impressions' => 31000, 'clicks' => 333, 'conversions' => 29],
            self::AFTER => ['spend' => 9191.91, 'revenue' => 99000.99, 'impressions' => 123456, 'clicks' => 5555, 'conversions' => 777],
        ], [self::IN1 => [131.31, 909.09], self::AFTER => [8181.81, 0.0]]);
        $this->seedCampaign('META', 'meta', $this->meta, 'leads', (string) $ws->getKey(), [
            self::IN1 => ['spend' => 3030.3, 'impressions' => 80000, 'clicks' => 820, 'conversions' => 62],
            self::IN2 => ['spend' => 404.04, 'impressions' => 9000, 'clicks' => 94, 'conversions' => 9],
            self::BEFORE => ['spend' => 818.18, 'impressions' => 41000, 'clicks' => 444, 'conversions' => 38],
            self::AFTER => ['spend' => 9292.92, 'impressions' => 234567, 'clicks' => 6666, 'conversions' => 888],
        ], [self::IN1 => [242.42, 0.0]]);

        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...array_unique(array_merge(Permission::pluck('key')->all(), ['projects.view', 'projects.view.all'])));
        $this->owner = User::create(['name' => 'owner', 'email' => 'owner-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, array<string, float|int>>  $days  date => metric => value
     * @param  array<string, array{0: float, 1: float}>  $creativeDays  date => [spend, revenue]
     */
    private function seedCampaign(string $label, string $provider, string $account, string $objective, string $workspace, array $days, array $creativeDays): void
    {
        $base = ['tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        $external = (string) Str::uuid();
        $creative = (string) Str::uuid();

        DB::table('unified_campaigns')->insert($base + [
            'id' => $campaign, 'client_workspace_id' => $workspace, 'name' => "QCAMP{$label}", 'objective' => $objective,
            'objective_source' => 'declared', 'status' => 'active', 'platforms' => json_encode([$provider]),
        ]);
        DB::table('external_campaigns')->insert($base + [
            'id' => $external, 'external_account_id' => $account, 'unified_campaign_id' => $campaign, 'provider' => $provider,
            'external_id' => "ext-{$label}", 'name' => "QCAMP{$label}", 'status' => 'active', 'objective' => $objective,
        ]);
        foreach ($days as $date => $metrics) {
            foreach ($metrics as $key => $value) {
                DB::table('daily_metrics')->insert($base + [
                    'id' => (string) Str::uuid(), 'external_account_id' => $account, 'external_campaign_id' => $external,
                    'unified_campaign_id' => $campaign, 'provider' => $provider, 'metric_key' => $key, 'metric_date' => $date,
                    'value' => $value, 'project_currency' => 'SAR', 'original_currency' => 'SAR', 'original_amount' => $value,
                ]);
            }
        }
        DB::table('external_creatives')->insert($base + [
            'id' => $creative, 'external_campaign_id' => $external, 'campaign_id' => $campaign, 'provider' => $provider,
            'external_creative_id' => "cr-{$label}", 'name' => "QCREATIVE{$label}", 'format' => 'image',
            'asset_url' => "https://cdn.example/{$label}.jpg", 'last_active_at' => Carbon::parse(self::IN1),
        ]);
        foreach ($creativeDays as $date => [$spend, $revenue]) {
            DB::table('creative_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'creative_id' => $creative, 'campaign_id' => $campaign, 'metric_date' => $date,
                'spend' => $spend, 'revenue' => $revenue ?: null, 'impressions' => 1200, 'clicks' => 20, 'conversions' => 2,
            ]);
        }

        $this->ids[$label] = ['campaign' => $campaign, 'external' => $external, 'creative' => $creative];
    }

    // ---------------------------------------------------------------- building a report and its links

    /** @param  array<string, mixed>  $over */
    private function report(array $over = []): Report
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $report = Report::create($over + [
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'name' => 'October', 'type' => 'executive',
            'form' => 'detailed', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => self::FROM, 'period_end' => self::TO, 'scope' => ['account_ids' => [$this->snap, $this->meta]], 'data' => [],
            'config' => ['period_mode' => 'fixed'], 'created_by' => $this->owner->id,
        ]);

        return $this->generate($report);
    }

    private function generate(Report $report): Report
    {
        (new GenerateReportJob((string) $report->getKey()))->handle(app(ReportGenerator::class));
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        return Report::withoutGlobalScopes()->findOrFail($report->getKey());
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{0: ReportShare, 1: string}
     */
    private function snapshotLink(Report $report, array $opts = []): array
    {
        return app(ShareService::class)->create($report, $opts + ['allow_download' => true], null);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @param  array<string, mixed>  $scope
     * @return array{0: ReportShare, 1: string}
     */
    private function liveLink(Report $report, array $opts = [], array $scope = []): array
    {
        return app(ShareService::class)->create($report, $opts + ['allow_download' => true, 'scope' => $scope + [
            'project_id' => (string) $this->project->getKey(), 'campaign_ids' => [], 'account_ids' => [$this->snap, $this->meta],
            'dynamic_campaigns' => true, 'providers' => [], 'earliest' => self::FROM, 'latest' => self::TO,
        ]], null);
    }

    // ---------------------------------------------------------------- the surfaces

    /**
     * Every answer a link gives, serialised.
     *
     * @return array<string, string>
     */
    private function surfaces(Report $report, ReportShare $share, string $raw, bool $files = true): array
    {
        // One reader, many addresses: the public limiter counts per address and this is one test process.
        RateLimiter::clear('share:127.0.0.1');
        $out = ['page' => self::text($this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json())];

        if ($share->isLive()) {
            $window = 'from='.self::FROM.'&to='.self::TO;
            $out['live'] = self::text($this->getJson("/api/v1/reports/shared/{$raw}/live?{$window}")->assertOk()->json());
            foreach (['snapchat', 'meta'] as $provider) {
                $drawer = $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/{$provider}?{$window}");
                $out["platform:{$provider}"] = $drawer->status() === 200 ? self::text($drawer->json()) : '';
            }
        }

        foreach (['creatives', 'creatives/summary', 'widgets', 'attribution'] as $address) {
            $answer = $this->getJson("/api/v1/reports/shared/{$raw}/{$address}");
            $out[$address] = $answer->status() === 200 ? self::text($answer->json()) : '';
        }

        if ($files) {
            $token = 'tok-'.uniqid();
            Cache::put('report-print:'.hash('sha256', $token), [
                'report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->getKey(),
            ], 600);
            $out['print'] = self::text($this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json());
            $out['csv'] = $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent();
            $out['xlsx'] = self::xlsxText($this->get("/api/v1/reports/shared/{$raw}/download/xlsx")->assertOk()->streamedContent());
        }

        return $out;
    }

    private static function text(mixed $payload): string
    {
        return is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function xlsxText(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'flt').'.xlsx';
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true, 'the download is not a spreadsheet');
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $text .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $text;
    }

    /** @param  array<string, string>  $surfaces */
    private function assertAbsent(array $surfaces, array $needles, string $why): void
    {
        foreach ($surfaces as $surface => $text) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString((string) $needle, $text, "«{$surface}» carries «{$needle}»: {$why}");
            }
        }
    }

    /**
     * Every numeric value stored under one of `$keys`, at any depth of a JSON surface: `path => value`.
     *
     * @param  list<string>  $keys
     * @return array<string, float|int>
     */
    private static function figuresUnder(string $json, array $keys): array
    {
        $found = [];
        $walk = static function (mixed $node, string $path, bool $inside) use (&$walk, &$found, $keys): void {
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    $walk($value, $path.'.'.$key, $inside || (is_string($key) && in_array($key, $keys, true)));
                }

                return;
            }
            if ($inside && (is_int($node) || is_float($node))) {
                $found[$path] = $node;
            }
        };
        $walk(json_decode($json, true), '', false);

        return $found;
    }

    /** @return array<string, mixed> */
    private function live(string $raw, string $query = ''): array
    {
        RateLimiter::clear('share:127.0.0.1');

        return $this->getJson("/api/v1/reports/shared/{$raw}/live?".($query !== '' ? $query : 'from='.self::FROM.'&to='.self::TO))->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- the period

    public function test_the_period_bounds_every_figure_and_a_reader_can_only_narrow_it(): void
    {
        $report = $this->report();
        $content = ['settings' => ['creatives' => ['creatives' => true, 'spend' => true, 'revenue' => true]]];

        // Nothing dated after the window is on any surface of either kind of link, and a stored copy holds none of it.
        $this->assertAbsent(['stored' => self::text($report->data)], self::AFTER_FIGURES, 'a day after the report\'s period');
        [$share, $raw] = $this->snapshotLink($report, $content);
        $this->assertAbsent($this->surfaces($report, $share, $raw), self::AFTER_FIGURES, 'a day after the report\'s period');
        [$share, $raw] = $this->liveLink($report, $content);
        $this->assertAbsent($this->surfaces($report, $share, $raw), self::AFTER_FIGURES, 'a day after the link\'s window');

        // The whole window, then a narrowing inside it: the totals, the platform rows and the trend all move.
        $whole = $this->live($raw);
        $this->assertEqualsWithDelta(4767.67, $whole['totals']['spend'], 0.001);
        $this->assertSame(1497, (int) $whole['totals']['clicks']);

        $narrow = $this->live($raw, 'from=2026-10-15&to=2026-10-25');
        $this->assertSame(['from' => '2026-10-15', 'to' => '2026-10-25'], array_intersect_key($narrow['period'], ['from' => 1, 'to' => 1]));
        $this->assertEqualsWithDelta(626.26, $narrow['totals']['spend'], 0.001, 'narrowing the dates did not narrow the totals');
        $this->assertEqualsWithDelta(3000.25, $narrow['totals']['revenue'], 0.001);
        $this->assertSame(167, (int) $narrow['totals']['clicks']);
        $this->assertEqualsWithDelta(626.26, array_sum(array_column($narrow['platforms'], 'spend')), 0.001, 'the platform rows cover another window than the totals');
        $this->assertEqualsWithDelta(626.26, array_sum(array_column($narrow['timeseries'], 'spend')), 0.001, 'the trend covers another window than the totals');
        $this->assertAbsent(['live, narrowed' => self::text($narrow)], ['1111.11', '3030.3', '20000.5', '131.31', '242.42'], 'a day outside the dates the reader asked for');

        // A request for more than the window, for dates in the wrong order, or for nonsense, answers with the window and never more.
        foreach (['from=2026-01-01&to=2026-12-31', 'from=2026-12-31&to=2026-01-01', 'from=nonsense&to=', 'from[]=2026-01-01&to[]=2027-01-01', 'earliest=2026-01-01&latest=2027-01-01'] as $query) {
            $answer = $this->live($raw, $query);
            $this->assertSame([self::FROM, self::TO], [$answer['period']['from'], $answer['period']['to']], "«{$query}» moved the window");
            $this->assertEqualsWithDelta(4767.67, $answer['totals']['spend'], 0.001, "«{$query}» changed the totals");
        }

        // The content library reads the same window, and the same clamp.
        RateLimiter::clear('share:127.0.0.1');
        $library = $this->getJson("/api/v1/reports/shared/{$raw}/creatives?from=2026-01-01&to=2026-12-31")->assertOk()->json('data');
        $this->assertSame(['from' => self::FROM, 'to' => self::TO], $library['period']);
        $this->assertAbsent(['content library, wide request' => self::text($library)], self::AFTER_FIGURES, 'a day after the link\'s window');

        // A report made for a shorter period holds only that period, in its stored copy and in its files.
        $short = $this->report(['period_start' => '2026-10-15', 'period_end' => '2026-10-25']);
        $this->assertEqualsWithDelta(626.26, (float) $short->data['kpis']['spend'], 0.001);
        [$share, $raw] = $this->snapshotLink($short, ['settings' => ['sections' => ['previous_comparison' => false]]]);
        $this->assertAbsent($this->surfaces($short, $share, $raw), ['1111.11', '3030.3', '4767.67', '20000.5', ...self::AFTER_FIGURES], 'a day outside the report\'s period');
    }

    // ---------------------------------------------------------------- the comparison with the previous period

    /** The keys under which a comparison with the previous period travels. */
    private const COMPARISON_KEYS = ['delta', 'deltas', 'previous', 'movement', 'objective_performance_previous'];

    /** Movement ratios of this world: spend, clicks and revenue against the window before, and Meta's own spend. */
    private const MOVEMENT = ['2.1053', '0.9266', '0.5333', '3.1975'];

    public function test_the_comparison_switch_decides_whether_the_previous_period_travels_at_all(): void
    {
        $report = $this->report();

        // On: the previous window and the movement are there to be drawn.
        [, $raw] = $this->snapshotLink($report, ['settings' => ['sections' => ['previous_comparison' => true]]]);
        RateLimiter::clear('share:127.0.0.1');
        $page = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');
        $this->assertEqualsWithDelta(1535.35, $page['previous']['spend'], 0.001);
        $this->assertEqualsWithDelta(2.1053, $page['delta']['spend'], 0.0001);
        [, $raw] = $this->liveLink($report, ['settings' => ['sections' => ['previous_comparison' => true]]]);
        $live = $this->live($raw);
        $this->assertEqualsWithDelta(2.1053, $live['deltas']['spend'], 0.0001);
        $this->assertEqualsWithDelta(3.1975, collect($live['platforms'])->firstWhere('provider', 'meta')['movement']['spend'], 0.0001);

        // Off — the default of every client link the builder makes: no figure of the window before, and no ratio that returns one.
        foreach (['snapshot' => $this->snapshotLink(...), 'live' => $this->liveLink(...)] as $kind => $make) {
            [$share, $raw] = $make($report, ['settings' => ['sections' => ['previous_comparison' => false]]]);
            $surfaces = $this->surfaces($report, $share, $raw);

            $this->assertAbsent($surfaces, [...self::BEFORE_FIGURES, ...self::MOVEMENT], "the {$kind} link switched the comparison off");
            foreach (array_diff_key($surfaces, ['csv' => 1, 'xlsx' => 1]) as $surface => $json) {
                $this->assertSame([], self::figuresUnder($json, self::COMPARISON_KEYS), "the {$kind} link's «{$surface}» still carries comparison figures");
            }
            // The figures themselves stay: the switch removes the comparison, never the report.
            $this->assertStringContainsString('4767.67', $kind === 'live' ? $surfaces['live'] : $surfaces['page']);
        }
    }

    // ---------------------------------------------------------------- hidden money

    public function test_hidden_spend_and_hidden_revenue_are_absent_from_every_surface_and_cannot_be_divided_back(): void
    {
        $report = $this->report();
        // The content section is on, with its own spend and revenue permissions on: the link's switch still decides.
        $content = ['creatives' => ['creatives' => true, 'spend' => true, 'revenue' => true, 'cpa' => true, 'roas' => true], 'sections' => ['previous_comparison' => true, 'account_names' => true]];
        $spendKeys = HiddenMoney::SPEND;
        $revenueKeys = HiddenMoney::REVENUE;
        // Counters and labels that share a money key's name but state no amount.
        $notAmounts = static fn (array $found): array => array_filter($found, static fn ($v, string $path): bool => $v != 0 && ! str_contains($path, '.reported.') && ! str_contains($path, '.availability.') && ! str_contains($path, '.columns.') && ! str_contains($path, '.report_columns.') && ! str_contains($path, '.permissions.') && ! str_contains($path, '.creatives.spend') && ! str_contains($path, '.creatives.revenue') && ! str_contains($path, '.creatives.roas') && ! str_contains($path, '.creatives.cpa'), ARRAY_FILTER_USE_BOTH);

        foreach (['snapshot' => $this->snapshotLink(...), 'live' => $this->liveLink(...)] as $kind => $make) {
            // Spend hidden, revenue shown.
            [$share, $raw] = $make($report, ['hide_spend' => true, 'settings' => $content]);
            $surfaces = $this->surfaces($report, $share, $raw);
            $this->assertAbsent($surfaces, self::SPEND, "the {$kind} link hides spend");
            foreach (array_diff_key($surfaces, ['csv' => 1, 'xlsx' => 1]) as $surface => $json) {
                $this->assertSame([], $notAmounts(self::figuresUnder($json, array_values(array_diff($spendKeys, ['spend_withheld_rows'])))), "the {$kind} link's «{$surface}» still states spend or a figure spend can be divided back from");
            }
            $this->assertStringContainsString('23000.75', $kind === 'live' ? $surfaces['live'] : $surfaces['page'], 'hiding spend took the revenue with it');

            // Revenue hidden, spend shown.
            [$share, $raw] = $make($report, ['hide_revenue' => true, 'settings' => $content]);
            $surfaces = $this->surfaces($report, $share, $raw);
            $this->assertAbsent($surfaces, self::REVENUE, "the {$kind} link hides revenue");
            foreach (array_diff_key($surfaces, ['csv' => 1, 'xlsx' => 1]) as $surface => $json) {
                $this->assertSame([], $notAmounts(self::figuresUnder($json, array_values(array_diff($revenueKeys, ['revenue_withheld_rows'])))), "the {$kind} link's «{$surface}» still states revenue or a ratio of it");
            }
            $this->assertStringContainsString('4767.67', $kind === 'live' ? $surfaces['live'] : $surfaces['page'], 'hiding revenue took the spend with it');
        }
    }

    // ---------------------------------------------------------------- names

    public function test_names_a_link_does_not_disclose_are_absent_from_every_surface(): void
    {
        $report = $this->report(['section_settings' => ['sections' => ['account_breakdown' => true]]]);
        $content = ['creatives' => ['creatives' => true, 'spend' => true, 'revenue' => true]];

        foreach (['snapshot' => $this->snapshotLink(...), 'live' => $this->liveLink(...)] as $kind => $make) {
            // A client link, as made: no campaign is named, and no ad account — on the accounts table or on a piece of content.
            [$share, $raw] = $make($report, ['settings' => $content]);
            $surfaces = $this->surfaces($report, $share, $raw);
            $this->assertAbsent($surfaces, self::CAMPAIGNS, "a client {$kind} link names no campaign");
            $this->assertAbsent($surfaces, self::ACCOUNTS, "the {$kind} link did not disclose ad-account names");
            $this->assertStringContainsString('QCREATIVESNAP', $kind === 'live' ? $surfaces['live'] : $surfaces['page'], 'the content the link shows is not named at all, so the absences above prove nothing');

            // Content under generic labels: no content name anywhere, and still no campaign or account.
            [$share, $raw] = $make($report, ['settings' => $content + ['generic_names' => ['content']]]);
            $surfaces = $this->surfaces($report, $share, $raw);
            $this->assertAbsent($surfaces, [...self::CONTENT, ...self::CAMPAIGNS, ...self::ACCOUNTS], "the {$kind} link shows content under generic labels");
        }

        // The disclosure, when the Owner switches it on, names the accounts on the accounts table of a detailed live link.
        [, $raw] = $this->liveLink($report, ['settings' => ['sections' => ['account_names' => true]]]);
        $this->assertEqualsCanonicalizing(self::ACCOUNTS, array_column($this->live($raw)['accounts'], 'account_name'));
        [, $raw] = $this->liveLink($report, ['settings' => ['sections' => ['account_names' => true], 'generic_names' => ['account']]]);
        $this->assertAbsent(['live, generic account names' => self::text($this->live($raw))], self::ACCOUNTS, 'a generic-name switch outranks the disclosure');
    }

    // ---------------------------------------------------------------- sections

    public function test_a_section_switched_off_on_the_link_leaves_every_surface(): void
    {
        $report = $this->report();
        $off = ['sections' => array_fill_keys(['platform_comparison', 'objective_breakdown', 'creatives', 'budget', 'funnel_store'], false) + ['previous_comparison' => true]];
        // What only the switched-off sections state: each platform's own total and every piece of content.
        $theirs = ['1333.33', '3434.34', '131.31', '242.42', '909.09', ...self::CONTENT];

        foreach (['snapshot' => $this->snapshotLink(...), 'live' => $this->liveLink(...)] as $kind => $make) {
            [$share, $raw] = $make($report, []);
            $shown = $this->surfaces($report, $share, $raw);
            $main = $kind === 'live' ? $shown['live'] : $shown['page'];
            foreach ($theirs as $needle) {
                $this->assertStringContainsString($needle, $main, "with every section on, the {$kind} link does not carry «{$needle}», so its absence below proves nothing");
            }

            [$share, $raw] = $make($report, ['settings' => $off]);
            $surfaces = $this->surfaces($report, $share, $raw);
            $this->assertAbsent($surfaces, $theirs, "the {$kind} link switched the platform comparison and the content off");
            $this->assertStringContainsString('4767.67', $kind === 'live' ? $surfaces['live'] : $surfaces['page'], 'switching sections off removed the headline figures');
            if ($kind === 'live') {
                $live = json_decode($surfaces['live'], true)['data'];
                foreach (['platforms', 'ads', 'ads_roster', 'ads_groups', 'budget', 'funnel'] as $key) {
                    $this->assertSame([], $live[$key] ?? [], "«{$key}» of a switched-off section still travels");
                }
                foreach (['objective_leaders', 'objective_analytics', 'store_funnel', 'content_formats'] as $key) {
                    $this->assertNull($live[$key] ?? null, "«{$key}» of a switched-off section still travels");
                }
                $this->assertSame('', $surfaces['platform:meta'], 'a platform drawer opens on a link whose platform comparison is off');
            }
            $this->assertSame('', $surfaces['creatives'], 'the content library answers on a link whose content is off');
        }

        // The same switch on the REPORT: the builder preview, the link, the PDF document and the files all lose the section.
        $noContent = $this->report(['section_settings' => ['sections' => ['content_performance' => false, 'platform_comparison' => false]]]);
        [$share, $raw] = $this->snapshotLink($noContent);
        $surfaces = $this->surfaces($noContent, $share, $raw) + [
            'builder preview' => self::text($this->as()->getJson("/api/v1/projects/{$this->project->getKey()}/reports/{$noContent->getKey()}")->assertOk()->json('data.data')),
        ];
        $this->assertAbsent($surfaces, ['131.31', '242.42', '909.09', ...self::CONTENT], 'the report switched its content section off');
    }

    // ---------------------------------------------------------------- the metric set and the columns

    public function test_the_chosen_metrics_and_columns_are_the_servers_list_on_every_surface_and_cut_the_files(): void
    {
        $picked = $this->report(['scope' => ['account_ids' => [$this->snap, $this->meta], 'metrics' => ['clicks', 'impressions']]]);
        $this->assertSame(['clicks', 'impressions'], $picked->data['metric_set'], 'the stored copy lists other KPIs, or another order, than the operator chose');

        [$share, $raw] = $this->snapshotLink($picked, ['settings' => ['sections' => ['previous_comparison' => false], 'columns' => ['platforms' => ['impressions', 'clicks'], 'days' => ['clicks']]]]);
        $surfaces = $this->surfaces($picked, $share, $raw);
        $page = json_decode($surfaces['page'], true)['data']['data'];
        $print = json_decode($surfaces['print'], true)['data']['data'];
        $this->assertSame(['clicks', 'impressions'], $page['metric_set']);
        $this->assertSame(['clicks', 'impressions'], $print['metric_set']);
        $this->assertSame(['clicks', 'impressions'], $print['kpi_allowlist']);
        $this->assertSame(['platforms' => ['impressions', 'clicks'], 'days' => ['clicks']], $page['report_columns']);
        $this->assertSame($page['report_columns'], $print['report_columns']);
        // The files are cut by the server: no KPI row and no column the operator did not choose.
        $this->assertAbsent(['csv' => $surfaces['csv'], 'xlsx' => $surfaces['xlsx']], [...self::SPEND, ...self::REVENUE], 'spend and revenue are neither a chosen KPI nor a chosen column');
        $this->assertStringContainsString('1497', $surfaces['csv']);
        $this->assertStringContainsString('146000', $surfaces['csv']);

        // A live link: the list and the order are the link's own, and an unknown or foreign key never enters it.
        [, $raw] = $this->liveLink($picked, ['settings' => ['columns' => ['platforms' => ['clicks', 'nonsense'], 'unknown_table' => ['spend']]]], ['metrics' => ['clicks', 'impressions']]);
        $live = $this->live($raw, 'from='.self::FROM.'&to='.self::TO.'&metrics[]=spend&columns[platforms][]=spend');
        $this->assertSame(['clicks', 'impressions'], $live['metrics'], 'a reader changed the KPI list from the address');
        $this->assertSame(['platforms' => ['clicks']], $live['columns'], 'a reader changed the columns from the address, or an unknown key was stored');
    }

    // ---------------------------------------------------------------- form and audience

    public function test_the_form_and_the_audience_decide_what_the_document_holds(): void
    {
        $report = $this->report();

        // A summary link of a detailed report carries no detailed-only block, on the live payload and its drawers.
        [, $raw] = $this->liveLink($report, ['form' => 'detailed']);
        $detailed = $this->live($raw);
        $this->assertNotSame([], $detailed['ads_platform_groups']);
        $this->assertNotSame([], $detailed['funnel']);
        [, $raw] = $this->liveLink($report, ['form' => 'executive_summary']);
        $summary = $this->live($raw);
        $this->assertSame([], $summary['ads_platform_groups'] ?? []);
        $this->assertSame([], $summary['ads_weakest'] ?? []);
        $this->assertSame([], $summary['funnel'] ?? []);
        $this->assertNull($summary['store_funnel'] ?? null);
        $this->assertEqualsWithDelta($detailed['totals']['spend'], $summary['totals']['spend'], 0.001, 'the form changed a figure; it changes the composition only');

        // The audience: an internal report keeps the campaign names for the operator, and cannot be shared at all.
        $internal = $this->report(['audience' => 'internal']);
        $operator = self::text($this->as()->getJson("/api/v1/projects/{$this->project->getKey()}/reports/{$internal->getKey()}")->assertOk()->json('data.data'));
        $this->assertStringContainsString('QCAMPSNAP', $operator, 'the internal document lost its campaigns');
        $this->as()->postJson("/api/v1/projects/{$this->project->getKey()}/reports/{$internal->getKey()}/shares", [])->assertStatus(422);
        $this->assertSame(0, ReportShare::withoutGlobalScopes()->where('report_id', $internal->getKey())->count());

        // The client document of the same data names no campaign, in the preview the operator sees before sending it.
        $client = self::text($this->as()->getJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}")->assertOk()->json('data.data'));
        $this->assertAbsent(['client preview' => $client], self::CAMPAIGNS, 'a client report names no campaign');
    }

    // ---------------------------------------------------------------- objective and currency

    public function test_the_objective_is_a_template_unless_asked_to_filter_and_the_currency_is_the_rows_own(): void
    {
        // The objective chooses the KPI template; it does not remove a campaign from the totals.
        $sales = $this->report(['campaign_objective' => 'sales']);
        $leads = $this->report(['campaign_objective' => 'leads']);
        $this->assertSame('sales', $sales->data['objective']);
        $this->assertSame('leads', $leads->data['objective']);
        $this->assertNotSame($sales->data['metric_set'], $leads->data['metric_set'], 'two objectives chose one KPI set');
        $this->assertEqualsWithDelta(4767.67, (float) $sales->data['kpis']['spend'], 0.001);
        $this->assertEqualsWithDelta(4767.67, (float) $leads->data['kpis']['spend'], 0.001);

        // Asked to filter by objective, it does: only the sales campaign's rows remain, in the totals and in the content.
        $filtered = $this->report(['scope' => ['account_ids' => [$this->snap, $this->meta], 'objectives' => ['sales'], 'objective_filter' => true]]);
        $this->assertEqualsWithDelta(1333.33, (float) $filtered->data['kpis']['spend'], 0.001);
        $this->assertSame(['snapchat'], array_column($filtered->data['platforms'], 'provider'));

        // The currency printed is the one the rows are held in, on the stored copy, the live payload and the files.
        DB::table('daily_metrics')->where('project_id', $this->project->getKey())->update(['project_currency' => 'USD', 'original_currency' => 'USD']);
        $usd = $this->report();
        $this->assertSame('USD', $usd->data['currency']);
        [$share, $raw] = $this->liveLink($usd);
        $this->assertSame('USD', $this->live($raw)['currency']);
        $files = $this->surfaces($usd, $share, $raw);
        $this->assertStringContainsString('USD', $files['csv']);
        $this->assertStringNotContainsString('SAR', $files['csv'], 'the file states a currency its rows are not held in');

        // Two currencies in one scope have no unit: none is stated, rather than one of them over the sum.
        DB::table('daily_metrics')->where('project_id', $this->project->getKey())->where('provider', 'meta')->update(['project_currency' => 'SAR']);
        $this->assertNull($this->report()->data['currency']);
    }

    // ---------------------------------------------------------------- sort, search and paging

    public function test_the_content_library_sorts_searches_and_pages_on_the_server_inside_the_link(): void
    {
        $report = $this->report();
        [, $raw] = $this->liveLink($report, ['settings' => ['creatives' => ['creatives' => true, 'spend' => true]]]);
        $library = function (string $query) use ($raw): array {
            RateLimiter::clear('share:127.0.0.1');

            return $this->getJson("/api/v1/reports/shared/{$raw}/creatives?{$query}")->assertOk()->json('data');
        };

        $this->assertSame(2, $library('')['total']);
        $this->assertSame(['QCREATIVEMETA', 'QCREATIVESNAP'], array_column($library('sort=spend')['creatives'], 'name'), 'sorting by spend did not order by spend');
        $this->assertSame(['QCREATIVESNAP'], array_column($library('search=QCREATIVESN')['creatives'], 'name'));
        $this->assertSame(0, $library('search=no-such-content')['total']);
        $this->assertSame(['QCREATIVEMETA'], array_column($library('providers[]=meta')['creatives'], 'name'));

        $first = $library('sort=spend&per_page=1&page=1');
        $second = $library('sort=spend&per_page=1&page=2');
        $this->assertSame([2, 1, 1], [$first['total'], $first['per_page'], count($first['creatives'])]);
        $this->assertSame(['QCREATIVEMETA'], array_column($first['creatives'], 'name'));
        $this->assertSame(['QCREATIVESNAP'], array_column($second['creatives'], 'name'));
        $this->assertSame([], $library('per_page=1&page=3')['creatives']);

        // A search for a campaign's name finds nothing: the library is searched by what a client may read.
        $this->assertSame(0, $library('search=QCAMPSNAP')['total']);

        /*
         * A reader cannot search, or sort by, what the link withholds: a hit on a hidden headline says what the headline holds,
         * and an order by hidden spend ranks the creatives by it.
         */
        DB::table('external_creatives')->where('id', $this->ids['SNAP']['creative'])->update(['headline' => 'QHEADLINE offer', 'body' => 'QBODY copy']);
        $for = function (array $creatives, array $settings = []) use ($report): \Closure {
            [, $raw] = $this->liveLink($report, ['settings' => ['creatives' => $creatives] + $settings]);

            return function (string $query) use ($raw): array {
                RateLimiter::clear('share:127.0.0.1');

                return $this->getJson("/api/v1/reports/shared/{$raw}/creatives?{$query}")->assertOk()->json('data');
            };
        };

        $open = $for(['creatives' => true, 'headline' => true, 'ad_copy' => true, 'spend' => true, 'revenue' => true]);
        $this->assertSame(1, $open('search=QHEADLINE')['total']);
        $this->assertSame(1, $open('search=QBODY')['total']);
        $this->assertSame(['QCREATIVEMETA', 'QCREATIVESNAP'], array_column($open('sort=spend')['creatives'], 'name'));
        $this->assertSame(['QCREATIVESNAP', 'QCREATIVEMETA'], array_column($open('sort=revenue')['creatives'], 'name'));

        $closed = $for(['creatives' => true]);
        $this->assertSame(0, $closed('search=QHEADLINE')['total'], 'a search told a reader what a withheld headline says');
        $this->assertSame(0, $closed('search=QBODY')['total'], 'a search told a reader what withheld ad copy says');
        $this->assertSame(1, $closed('search=QCREATIVESN')['total']);
        $unsorted = array_column($closed('')['creatives'], 'name');
        $this->assertSame($unsorted, array_column($closed('sort=spend')['creatives'], 'name'), 'the list is ordered by the spend the link withholds');
        $this->assertSame($unsorted, array_column($closed('sort=revenue')['creatives'], 'name'), 'the list is ordered by the revenue the link withholds');

        $generic = $for(['creatives' => true], ['generic_names' => ['content']]);
        $this->assertSame(0, $generic('search=QCREATIVESN')['total'], 'a search told a reader the name a generic label stands for');
    }

    // ---------------------------------------------------------------- «all sources» and the empty default

    public function test_a_link_whose_ceiling_names_no_campaign_and_no_account_shows_no_content(): void
    {
        $report = $this->report();
        $on = ['creatives' => ['creatives' => true, 'spend' => true, 'revenue' => true]];

        /*
         * What `ReportShareController::scopeFor()` stores for a report whose accounts spent nothing in its period, and what a link
         * made before the account axis holds: an empty campaign list, no account, not «follows its accounts». Nobody chose the
         * whole project here; the figures already read it as «nothing».
         */
        $empty = ['project_id' => (string) $this->project->getKey(), 'campaign_ids' => [], 'providers' => [], 'earliest' => self::FROM, 'latest' => self::TO];
        $content = ['131.31', '242.42', '909.09', ...self::CONTENT];

        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true, 'mode' => 'live', 'settings' => $on, 'scope' => $empty], null);
        $live = $this->live($raw);
        $this->assertEqualsWithDelta(0.0, (float) ($live['totals']['spend'] ?? 0), 0.001, 'the fixture has figures, so it is not the empty ceiling');
        $surfaces = array_intersect_key($this->surfaces($report, $share, $raw, files: false), array_flip(['live', 'creatives', 'creatives/summary', 'platform:snapchat', 'platform:meta']));
        $this->assertAbsent($surfaces, $content, 'a live link with an empty ceiling lists the whole project\'s content beside figures that are empty');
        $this->assertSame(0, $live['creatives_in_scope'] ?? 0);

        // The same ceiling on a snapshot link: its content library and the content rows of its files.
        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true, 'mode' => 'snapshot', 'settings' => $on, 'scope' => $empty], null);
        $surfaces = $this->surfaces($report, $share, $raw);
        $this->assertAbsent(array_intersect_key($surfaces, array_flip(['creatives', 'creatives/summary'])), $content, 'a snapshot link with an empty ceiling lists the whole project\'s content');
        $this->assertSame([], json_decode($surfaces['print'], true)['data']['data']['creatives'] ?? [], 'the file of a link with an empty ceiling carries the whole project\'s content rows');

        // A link over accounts that follows them is not this case, and keeps its content.
        [, $raw] = $this->liveLink($report, ['settings' => $on]);
        $this->assertSame(2, $this->live($raw)['creatives_in_scope']);

        /*
         * «Every source» of a link over ONE account is that account's. A creative the other account ran, filed under a campaign the
         * chosen account spent on, is on neither list of the same link: the live roster and the content library read one set, and
         * the library's filter options are derived from that set and not from the project.
         */
        $cross = (string) Str::uuid();
        $base = ['tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('external_creatives')->insert($base + [
            'id' => $cross, 'external_campaign_id' => $this->ids['META']['external'], 'campaign_id' => $this->ids['SNAP']['campaign'], 'provider' => 'meta',
            'external_creative_id' => 'cr-CROSS', 'name' => 'QCREATIVECROSS', 'format' => 'video', 'asset_url' => 'https://cdn.example/CROSS.mp4',
            'last_active_at' => Carbon::parse(self::IN1),
        ]);
        DB::table('creative_daily_metrics')->insert($base + [
            'id' => (string) Str::uuid(), 'creative_id' => $cross, 'campaign_id' => $this->ids['SNAP']['campaign'], 'metric_date' => self::IN1,
            'spend' => 353.53, 'impressions' => 900, 'clicks' => 9, 'conversions' => 1,
        ]);
        [$share, $raw] = $this->liveLink($report, ['settings' => $on], ['account_ids' => [$this->snap]]);
        $one = $this->surfaces($report, $share, $raw, files: false);
        $this->assertAbsent(array_intersect_key($one, array_flip(['live', 'creatives', 'creatives/summary', 'platform:snapchat'])), ['QCREATIVECROSS', '353.53', 'QCREATIVEMETA'], 'the link is over the Snapchat account alone');
        $library = json_decode($one['creatives'], true)['data'];
        $this->assertSame(['QCREATIVESNAP'], array_column($library['creatives'], 'name'));
        $this->assertSame(['snapchat'], array_values((array) $library['available']['providers']), 'the library offers a platform the link does not cover');
    }

    // ---------------------------------------------------------------- the link's lifecycle

    public function test_an_edit_a_conversion_and_a_rebuild_keep_what_the_link_hides(): void
    {
        $report = $this->report();
        [$share, $raw] = $this->snapshotLink($report);
        $base = "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}";
        RateLimiter::clear('share:127.0.0.1');
        $this->assertStringContainsString('4767.67', self::text($this->getJson("/api/v1/reports/shared/{$raw}")->json()));

        // An edit takes effect on the same address, at the next request.
        $this->as()->patchJson("{$base}/shares/{$share->getKey()}", ['hide_spend' => true, 'hide_revenue' => true, 'sections' => ['previous_comparison' => false]])->assertOk();
        $hidden = [...self::SPEND, ...self::REVENUE, ...self::BEFORE_FIGURES, ...self::MOVEMENT];
        $this->assertAbsent($this->surfaces($report, $share->fresh(), $raw), $hidden, 'the link was edited to hide money and the comparison');

        // Converted to live and back, the same switches hold.
        $this->as()->postJson("{$base}/shares/{$share->getKey()}/convert", ['to' => 'live', 'confirm' => true])->assertOk();
        $this->assertTrue($share->fresh()->isLive());
        $this->assertAbsent($this->surfaces($report, $share->fresh(), $raw), $hidden, 'the link was converted to live');
        $this->as()->postJson("{$base}/shares/{$share->getKey()}/convert", ['to' => 'snapshot', 'confirm' => true])->assertOk();

        // A rebuild replaces the stored copy; the link still hides what it hid.
        $this->as()->postJson("{$base}/regenerate", ['confirm' => true])->assertOk();
        $rebuilt = Report::withoutGlobalScopes()->findOrFail($report->getKey());
        $this->assertSame('completed', $rebuilt->status);
        $this->assertAbsent($this->surfaces($rebuilt, $share->fresh(), $raw), $hidden, 'the report was rebuilt');

        // Restoring the copy the rebuild replaced leaves the link's switches as they are.
        $version = ReportVersion::withoutGlobalScopes()->where('report_id', $report->getKey())->firstOrFail();
        $this->as()->postJson("{$base}/versions/{$version->getKey()}/restore", ['confirm' => true])->assertOk();
        $this->assertAbsent($this->surfaces(Report::withoutGlobalScopes()->findOrFail($report->getKey()), $share->fresh(), $raw), $hidden, 'an earlier copy was restored');
    }

    // ---------------------------------------------------------------- the password

    public function test_a_password_protected_link_gives_no_file_without_the_password(): void
    {
        $report = $this->report();
        [, $raw] = $this->snapshotLink($report, ['password' => 'open-sesame']);
        RateLimiter::clear('share:127.0.0.1');

        $this->getJson("/api/v1/reports/shared/{$raw}")->assertStatus(401);
        foreach (['csv', 'xlsx'] as $format) {
            $this->get("/api/v1/reports/shared/{$raw}/download/{$format}")->assertStatus(401);
            $this->get("/api/v1/reports/shared/{$raw}/download/{$format}", ['X-Report-Password' => 'wrong'])->assertStatus(401);
        }
        $csv = $this->get("/api/v1/reports/shared/{$raw}/download/csv", ['X-Report-Password' => 'open-sesame'])->assertOk()->streamedContent();
        $this->assertStringContainsString('4767.67', $csv);
    }

    private function as(): self
    {
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());

        return $this;
    }
}
