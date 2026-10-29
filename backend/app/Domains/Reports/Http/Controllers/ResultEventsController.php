<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\ResultEvents;
use App\Http\Controllers\Controller;
use App\Support\AdPlatforms;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RESULT-EVENTS-001 — which reported metric is a project's «Result», per platform and acquisition path.
 *
 * Nothing is inferred and nothing is defaulted: an unconfigured project has no Results on any client report. Changing the mapping changes
 * how a report is READ, never what a provider was told — no campaign, pixel or conversion setting is touched.
 */
final class ResultEventsController extends Controller
{
    public function show(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        return ApiResponse::success($this->shape(Project::query()->findOrFail($project)));
    }

    public function update(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.share'), 403);

        $request->validate([
            'result_events' => ['sometimes', 'array'],
            'result_events.*' => ['array'],
            'result_events.*.*' => ['string', Rule::in(ResultEvents::METRICS)],
            // LEAD-EVENTS-001 — which reported metric is a verified LEAD, per platform and path (the same shape as Results, kept apart).
            'lead_events' => ['sometimes', 'array'],
            'lead_events.*' => ['array'],
            'lead_events.*.*' => ['string', Rule::in(ResultEvents::METRICS)],
            // The project's own, explicit naming convention: a word in a campaign's name → the acquisition path it states.
            'campaign_paths' => ['sometimes', 'array', 'max:10'],
            'campaign_paths.*' => ['string', Rule::in(['lead_form', 'landing_page'])],
        ]);
        abort_if(! $request->has('result_events') && ! $request->has('lead_events') && ! $request->has('campaign_paths'), 422, 'Nothing to save.');

        $model = Project::query()->findOrFail($project);
        $meta = (array) ($model->meta ?? []);
        $before = array_intersect_key($meta, array_flip(['result_events', 'lead_events', 'campaign_paths']));

        foreach (['result_events', 'lead_events'] as $key) {
            if (! $request->has($key)) {
                continue;
            }
            $problems = ResultEvents::problems($request->input($key));
            abort_unless($problems === [], 422, implode('; ', $problems));
            $events = ResultEvents::fromArray((array) $request->input($key));
            if ($events->configured()) {
                $meta[$key] = $events->toArray();
            } else {
                unset($meta[$key]);
            }
        }
        if ($request->has('campaign_paths')) {
            $paths = array_filter((array) $request->input('campaign_paths'), static fn ($path, $word): bool => is_string($word) && trim($word) !== '', ARRAY_FILTER_USE_BOTH);
            if ($paths === []) {
                unset($meta['campaign_paths']);
            } else {
                $meta['campaign_paths'] = $paths;
            }
        }
        $model->update(['meta' => $meta]);

        $audit->log(action: 'project.result_events_changed', entityType: Project::class, entityId: (string) $model->getKey(), before: $before, after: array_intersect_key($meta, array_flip(['result_events', 'lead_events', 'campaign_paths'])));

        return ApiResponse::success($this->shape($model->refresh()), 'Result events saved.');
    }

    /**
     * NASEL 2026-10-28 — one EXISTING link's result and lead events, changed in place so the client keeps the same URL.
     *
     * Only the link's `settings.result_events` / `settings.lead_events` change: never its accounts, campaigns, platforms, dates, password or
     * expiry, so nothing a client can see is widened. A platform the link does not cover is refused rather than stored. The audit entry
     * keeps the previous value; sending that value back is the reversal. Sending `null` for a key removes the link's override, so the
     * project's own mapping applies again.
     *
     * NASEL 2026-10-29 — and its PRESENTATION: the KPI cards in their order (`metrics`), their names (`kpi_labels`) and the campaign table
     * (`campaign_breakdown`). Both live links had been built with the pixel «leads» card, and the only way to correct one was a new URL.
     * These choose how the link's own figures are shown; the money switches (`hide_spend`, `hide_revenue`) stay where they are.
     */
    public function updateShare(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        $keys = LiveReportBuilderController::metricKeys();
        $request->validate([
            'result_events' => ['nullable', 'array'],
            'lead_events' => ['nullable', 'array'],
            'metrics' => ['sometimes', 'array', 'min:1', 'max:12'],
            'metrics.*' => ['string', 'distinct', Rule::in($keys)],
            'kpi_labels' => ['sometimes', 'nullable', 'array', 'max:30'],
            'kpi_labels.*' => ['nullable', 'string', 'max:60'],
            'campaign_breakdown' => ['sometimes', 'boolean'],
        ]);
        abort_if(array_intersect(['result_events', 'lead_events', 'metrics', 'kpi_labels', 'campaign_breakdown'], array_keys($request->all())) === [], 422, 'Nothing to save.');
        $presentation = ['metrics', 'kpi_labels', 'campaign_breakdown'];

        $model = Report::query()->where('project_id', $project)->findOrFail($report);
        $link = ReportShare::query()->where('report_id', $model->getKey())->findOrFail($share);
        $covered = array_values(array_filter((array) (($link->scope ?? [])['providers'] ?? [])));

        $settings = (array) ($link->settings ?? []);
        $scope = (array) ($link->scope ?? []);
        $read = static fn (array $settings, array $scope): array => array_intersect_key($settings, array_flip(['result_events', 'lead_events', 'kpi_labels', 'campaign_breakdown']))
            + (array_key_exists('metrics', $scope) ? ['metrics' => $scope['metrics']] : []);
        $before = $read($settings, $scope);
        foreach (['result_events', 'lead_events'] as $key) {
            if (! $request->exists($key)) {
                continue;
            }
            $raw = $request->input($key);
            $problems = ResultEvents::problems($raw);
            foreach (array_keys((array) $raw) as $platform) {
                if ($covered !== [] && ! in_array(AdPlatforms::canonical((string) $platform), array_map(static fn (string $p): string => AdPlatforms::canonical($p), $covered), true)) {
                    $problems[] = "«{$platform}» is not a platform this link covers";
                }
            }
            abort_unless($problems === [], 422, implode('; ', $problems));
            $events = ResultEvents::fromArray((array) $raw);
            if ($raw === null || ! $events->configured()) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $events->toArray();
            }
        }
        if ($request->has('metrics')) {
            $scope['metrics'] = array_values((array) $request->input('metrics'));
        }
        if ($request->exists('kpi_labels')) {
            // Names only for cards the link shows; a blank name means the catalogue's own.
            $labels = array_filter(array_map(static fn ($v): string => trim((string) $v), array_intersect_key((array) $request->input('kpi_labels'), array_flip($keys))), static fn (string $v): bool => $v !== '');
            if ($labels === []) {
                unset($settings['kpi_labels']);
            } else {
                $settings['kpi_labels'] = $labels;
            }
        }
        if ($request->has('campaign_breakdown')) {
            if ($request->boolean('campaign_breakdown')) {
                $settings['campaign_breakdown'] = true;
            } else {
                unset($settings['campaign_breakdown']);
            }
        }
        $link->settings = $settings;
        $link->scope = $scope;
        $link->save();
        if ($request->has('metrics') && $model->type === 'live') {
            // The report row records the same card list, so the two never disagree for a later reader.
            $model->config = ['metrics' => $scope['metrics']] + (array) ($model->config ?? []);
            $model->save();
        }

        $after = $read($settings, $scope);
        $entry = $audit->log(action: 'report.shared_link_result_events_changed', entityType: ReportShare::class, entityId: (string) $link->getKey(), before: $before, after: $after);

        return ApiResponse::success([
            'share_id' => (string) $link->getKey(),
            'providers' => $covered,
            'before' => (object) $before,
            'after' => (object) $after,
            'audit_id' => (string) $entry->getKey(),
        ], 'Link result events saved.');
    }

    /** @return array<string, mixed> */
    private function shape(Project $project): array
    {
        return [
            'result_events' => (object) (array) (($project->meta ?? [])['result_events'] ?? []),
            'lead_events' => (object) (array) (($project->meta ?? [])['lead_events'] ?? []),
            'campaign_paths' => (object) (array) (($project->meta ?? [])['campaign_paths'] ?? []),
            'platforms' => AdPlatforms::ORDER,
            'paths' => ResultEvents::PATHS,
            'metrics' => ResultEvents::METRICS,
        ];
    }
}
