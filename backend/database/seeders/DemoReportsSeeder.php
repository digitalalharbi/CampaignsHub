<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

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
        $owner = User::where('email', 'owner@demo-agency.local')->first();
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

        app(TenantContext::class)->forget();
    }
}
