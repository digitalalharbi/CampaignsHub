<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Demo reports on the demo analytics project. Completed reports carry a REAL generated snapshot from
 * the metrics tables (deterministic); one is left processing and one failed with a clear message; a
 * scheduled report is included. All is_demo=true and removed by demo:remove.
 */
final class DemoReportsSeeder extends Seeder
{
    public function run(): void
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            return;
        }
        $tenant = Tenant::where('slug', 'demo-agency')->first();
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->setTenantId((string) $tenant->id);

        $project = Project::where('name', 'متجر تجريبي — Demo')->first();
        if (! $project) {
            app(TenantContext::class)->forget();

            return;
        }
        $owner = User::where('email', 'agency@campaignshub.io')->first();
        $generator = app(ReportGenerator::class);

        $completed = [
            ['التقرير الأسبوعي — الأداء', 'weekly', Carbon::today()->subDays(6), Carbon::today()],
            ['التقرير الشهري — نظرة تنفيذية', 'monthly', Carbon::today()->subDays(29), Carbon::today()],
            ['تقرير المنصات — مقارنة', 'platform_comparison', Carbon::today()->subDays(29), Carbon::today()],
        ];
        foreach ($completed as [$name, $type, $from, $to]) {
            $report = Report::updateOrCreate(
                ['project_id' => $project->id, 'name' => $name],
                [
                    'type' => $type, 'status' => 'processing', 'period_start' => $from, 'period_end' => $to,
                    'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'attribution_window' => '7d_click_1d_view',
                    'created_by' => $owner?->id, 'is_demo' => true,
                ],
            );
            $data = $generator->generate($report->fresh());
            $report->update(['data' => $data, 'status' => 'completed', 'generated_at' => now()]);
        }

        Report::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'تقرير قيد المعالجة'],
            ['type' => 'project', 'status' => 'processing', 'period_start' => Carbon::today()->subDays(29), 'period_end' => Carbon::today(), 'currency' => 'SAR', 'created_by' => $owner?->id, 'is_demo' => true],
        );

        Report::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'تقرير فشل الإنشاء'],
            ['type' => 'campaign', 'status' => 'failed', 'error' => 'تعذّر جلب بيانات المنصة — انتهت صلاحية الرمز (Token expired).', 'period_start' => Carbon::today()->subDays(29), 'period_end' => Carbon::today(), 'currency' => 'SAR', 'created_by' => $owner?->id, 'is_demo' => true],
        );

        ReportSchedule::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'الملخص التنفيذي الأسبوعي'],
            ['type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday', 'time' => '08:00', 'active' => true, 'next_run_at' => Carbon::today()->next('Sunday')->setTime(8, 0), 'created_by' => $owner?->id, 'is_demo' => true],
        );

        $this->seedLiveShare($project, $owner);

        app(TenantContext::class)->forget();
    }

    /**
     * A live client link that works before any platform credentials exist (LIVEREP-001).
     *
     * The point of a demo link is that somebody can open the client's own experience — filters, charts,
     * freshness strip and all — without first connecting Meta. It reads the same demo `daily_metrics`
     * every other demo surface reads, so the figures move when the seeded story moves rather than being
     * a second set of numbers that drifts away from the first.
     *
     * **The token is fixed, and that is only safe because of where this can run.** `run()` returns early
     * outside local/testing/demo, so a predictable token cannot exist in production. A random one would
     * be unusable for the thing this exists for: a link somebody can type, and a test can assert on.
     */
    private function seedLiveShare(Project $project, ?User $owner): void
    {
        $report = Report::where('project_id', $project->id)
            ->where('status', 'completed')
            ->orderByDesc('period_end')
            ->first();

        if (! $report) {
            return;
        }

        /*
         * The link's ceiling comes from the METRICS, exactly as `ReportShareController` builds it.
         *
         * This read `$report->data['campaigns']`, and that list is empty on every report generated
         * under CLIENT-REPORT-ENTITY-BOUNDARY-001 — a client report carries no campaign roster. The
         * seeded share's ceiling became «no campaigns», which the aggregator fails closed on, and
         * the demo live link opened on a page of zeros. A ceiling is an authorisation fact; deriving
         * it from a document's rendering was the coupling that broke.
         */
        $campaigns = DB::table('daily_metrics')
            ->where('project_id', $project->id)
            ->whereNotNull('unified_campaign_id')
            ->distinct()
            ->pluck('unified_campaign_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
        $providers = array_values(array_unique(array_filter(array_map(
            static fn ($row) => is_array($row) ? ($row['provider'] ?? null) : null,
            (array) (($report->data ?? [])['platforms'] ?? []),
        ))));

        ReportShare::updateOrCreate(
            ['token_hash' => hash('sha256', self::DEMO_LIVE_TOKEN)],
            [
                'tenant_id' => $report->tenant_id,
                'report_id' => $report->id,
                'mode' => 'live',
                'scope' => [
                    'project_id' => (string) $project->id,
                    'campaign_ids' => $campaigns,
                    'providers' => $providers,
                    'earliest' => Carbon::today()->subDays(89)->toDateString(),
                    'latest' => Carbon::today()->addYear()->toDateString(),
                ],
                'allow_download' => true,
                'watermark' => false,
                'created_by' => $owner?->id,
                // Tagged, so the client page shows a Demo badge rather than passing seeded figures off
                // as a real account's spend.
                'is_demo' => true,
            ],
        );
    }

    /** The demo live link's token: `/reports/share/{token}`. Local/testing/demo only — see seedLiveShare. */
    public const DEMO_LIVE_TOKEN = 'demo-live-report-token';
}
