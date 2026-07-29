<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Services\ScheduledReportDispatcher;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * REPORT-SCHEDULING — the HTTP surface for report schedules, which the dispatcher engine has always
 * lacked. Without these routes a schedule could only be created by seeding the database, so the UI
 * had nothing honest to talk to.
 *
 * The next run is computed by {@see ScheduledReportDispatcher::nextRun()} — the SAME method the cron
 * dispatcher uses — so what the UI promises and what actually fires cannot drift apart.
 *
 * Delivery honesty is preserved: this controller never marks anything "sent". Creating a schedule only
 * schedules it; the dispatcher writes an honest per-recipient delivery row whose status stays
 * `awaiting_provider_credentials` until a real mail provider acknowledges the send.
 */
final class ReportScheduleController extends Controller
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly', 'custom'];

    public function __construct(
        private readonly ScheduledReportDispatcher $dispatcher,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** GET reports/schedules — every schedule of the active project, with its delivery counts. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);

        $schedules = ReportSchedule::query()->orderByDesc('created_at')->get();

        // Delivery ledger counts per schedule — one grouped query, not one per row.
        $deliveries = $schedules->isEmpty()
            ? collect()
            : DB::table('report_deliveries')
                ->whereIn('schedule_id', $schedules->pluck('id'))
                ->select('schedule_id', 'status')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('schedule_id', 'status')
                ->get()
                ->groupBy('schedule_id');

        return ApiResponse::success(
            $schedules->map(fn (ReportSchedule $s) => $this->shape($s, $deliveries->get($s->id)))->all(),
            'Report schedules.',
        );
    }

    /** POST reports/schedules — create a schedule and compute when it will actually fire. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);

        $data = $this->validated($request);

        $schedule = new ReportSchedule;
        $schedule->forceFill($data + [
            'tenant_id' => $this->tenant->tenantId(),
            'project_id' => app(ProjectContext::class)->projectId(),
            'created_by' => $request->user()->id,
            // Creating a schedule means you want it to run; pausing is an explicit, separate action.
            'active' => true,
        ])->save();

        $schedule->forceFill(['next_run_at' => $this->dispatcher->nextRun($schedule, Carbon::now())])->save();

        $this->audit->log('report_schedule.created', 'report_schedule', (string) $schedule->getKey(), after: $this->shape($schedule));

        return ApiResponse::success($this->shape($schedule), 'Schedule created.', status: 201);
    }

    /** PUT/PATCH reports/schedules/{schedule} — edit a schedule; the next run is recomputed. */
    public function update(Request $request, string $project, string $schedule): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);

        $model = ReportSchedule::query()->findOrFail($schedule);
        $before = $this->shape($model);

        $model->forceFill($this->validated($request, partial: true))->save();
        $model->forceFill(['next_run_at' => $this->dispatcher->nextRun($model, Carbon::now())])->save();

        $this->audit->log('report_schedule.updated', 'report_schedule', (string) $model->getKey(), before: $before, after: $this->shape($model));

        return ApiResponse::success($this->shape($model), 'Schedule updated.');
    }

    /**
     * POST reports/schedules/{schedule}/toggle — pause or resume.
     * A paused schedule keeps its settings but stops firing; resuming recomputes the next run from now,
     * so a schedule that was paused for a month does not immediately fire a backlog.
     */
    public function toggle(Request $request, string $project, string $schedule): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);

        $model = ReportSchedule::query()->findOrFail($schedule);
        $active = ! $model->active;

        $model->forceFill([
            'active' => $active,
            'next_run_at' => $active ? $this->dispatcher->nextRun($model, Carbon::now()) : null,
        ])->save();

        $this->audit->log($active ? 'report_schedule.resumed' : 'report_schedule.paused', 'report_schedule', (string) $model->getKey());

        return ApiResponse::success($this->shape($model), $active ? 'Schedule resumed.' : 'Schedule paused.');
    }

    /** DELETE reports/schedules/{schedule} */
    public function destroy(Request $request, string $project, string $schedule): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);

        $model = ReportSchedule::query()->findOrFail($schedule);
        $before = $this->shape($model);
        $model->delete();

        $this->audit->log('report_schedule.deleted', 'report_schedule', $schedule, before: $before);

        return ApiResponse::success(null, 'Schedule deleted.');
    }

    /**
     * POST reports/schedules/{schedule}/run — run it now, without waiting for the cron.
     * This goes through the same dispatcher the cron uses, so a manual run is a real run: it generates
     * a report and writes the same honest delivery rows.
     */
    public function runNow(Request $request, string $project, string $schedule): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);

        $model = ReportSchedule::query()->findOrFail($schedule);
        $report = $this->dispatcher->dispatchOne($model, Carbon::now());

        $this->audit->log('report_schedule.run_now', 'report_schedule', (string) $model->getKey(), after: ['report_id' => $report->id]);

        return ApiResponse::success([
            'schedule' => $this->shape($model->refresh()),
            'report_id' => $report->id,
        ], 'Schedule run started.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'type' => [$req, 'string', 'max:40'],
            'frequency' => [$req, Rule::in(self::FREQUENCIES)],
            // weekday name for weekly, day-of-month for monthly.
            'day' => ['nullable', 'string', 'max:20'],
            'time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'audience' => ['nullable', Rule::in(['client', 'internal', 'executive'])],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'formats' => ['nullable', 'array'],
            'formats.*' => [Rule::in(['pdf', 'xlsx', 'csv'])],
            'recipients' => ['nullable', 'array', 'max:50'],
            'recipients.*.email' => ['required_with:recipients', 'email'],
            'recipients.*.name' => ['nullable', 'string', 'max:120'],
            'cron' => ['nullable', 'string', 'max:120'],
            'report_id' => ['nullable', 'uuid'],
            'active' => ['nullable', 'boolean'],
        ]);

        // A custom frequency without a cron expression would silently fall back to daily — refuse it
        // rather than schedule something different from what was asked for.
        if (($data['frequency'] ?? null) === 'custom' && blank($data['cron'] ?? null)) {
            abort(422, 'A custom frequency needs a cron expression.');
        }

        return $data;
    }

    /**
     * @param  Collection<int,object>|null  $deliveries
     * @return array<string,mixed>
     */
    private function shape(ReportSchedule $s, $deliveries = null): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'type' => $s->type,
            'frequency' => $s->frequency,
            'day' => $s->day,
            'time' => $s->time,
            'timezone' => $s->timezone,
            'audience' => $s->audience,
            'language' => $s->language,
            'formats' => $s->formats ?? [],
            'recipients' => $s->recipients ?? [],
            'cron' => $s->cron,
            'report_id' => $s->report_id,
            'active' => (bool) $s->active,
            'is_demo' => (bool) $s->is_demo,
            'last_run_at' => optional($s->last_run_at)->toIso8601String(),
            'next_run_at' => optional($s->next_run_at)->toIso8601String(),
            // Delivery states are reported exactly as recorded — nothing is ever presented as "sent"
            // unless a provider acknowledged it.
            'deliveries' => collect($deliveries ?? [])->mapWithKeys(fn ($r) => [$r->status => (int) $r->total])->all(),
        ];
    }
}
