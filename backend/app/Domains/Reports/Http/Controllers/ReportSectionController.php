<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportScopeTemplate;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Sections\ReportSectionResolver;
use App\Domains\Reports\Sections\ReportSectionSurfaces;
use App\Domains\Reports\Sections\SectionContext;
use App\Domains\Reports\Sections\SectionSettings;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The operator's section choices for a report or a template — REPORT-SECTION-MODEL-001.
 *
 * Reading is `reports.view`; changing is `reports.manage`, checked HERE as well as on the route, so a
 * client-facing section cannot be switched on by anyone who merely found the endpoint. Hiding a
 * toggle in the builder is not the control.
 */
final class ReportSectionController extends Controller
{
    public function __construct(
        private readonly ReportSectionRegistry $registry,
        private readonly ReportSectionResolver $resolver,
        private readonly ProjectAbilities $abilities,
    ) {}

    /** The project capability, checked in the controller as well as on the route. */
    private function authorise(Request $request, string $project, string $capability): void
    {
        $user = $request->user();

        abort_unless($user !== null && $this->abilities->allows($user, $project, $capability), 403);
    }

    /** The sections an operator can choose from, with each audience's default. */
    public function registry(Request $request, string $project): JsonResponse
    {
        $this->authorise($request, $project, 'reports.view');

        return ApiResponse::success([
            'sections' => array_map(static fn ($s): array => $s->toArray(), $this->registry->all()),
            'reasons' => [
                ReportSectionResolver::DISABLED_BY_OPERATOR,
                ReportSectionResolver::UNSUPPORTED,
                ReportSectionResolver::DATA_UNAVAILABLE,
            ],
        ], 'Report sections.');
    }

    public function show(Request $request, string $project, string $report): JsonResponse
    {
        $this->authorise($request, $project, 'reports.view');

        return ApiResponse::success($this->reportShape(Report::query()->findOrFail($report)), 'Report sections.');
    }

    public function update(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        $this->authorise($request, $project, 'reports.manage');

        $model = Report::query()->findOrFail($report);
        $before = $model->sectionSettings()->toArray();

        $base = null;
        if ($request->filled('template_id')) {
            $request->validate(['template_id' => ['uuid']]);
            $base = $this->findTemplate($project, (string) $request->input('template_id'))->sectionSettings()->toArray();
        }

        $sections = $this->validatedSections($request, ($base ?? $before)['sections']);
        $streams = $this->validatedStreams($request, $project, ($base ?? $before)['streams'] ?? []);

        $model->section_settings = SectionSettings::fromArray(['sections' => $sections, 'streams' => $streams], $this->registry)->toArray();
        $model->save();

        /*
         * A generated snapshot holds its stream figures from generation time, so a changed stream
         * definition regenerates it — the same rule the scope editor follows. A live report computes
         * them on every open and needs nothing.
         */
        if (($before['streams'] ?? []) !== ($model->section_settings['streams'] ?? []) && ! $this->isLive($model)) {
            $model->forceFill(['status' => 'processing', 'error' => null])->save();
            GenerateReportJob::dispatch((string) $model->getKey());
        }

        $audit->log(
            action: 'report.sections_updated',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: $before,
            after: $model->section_settings,
        );

        return ApiResponse::success($this->reportShape($model->fresh() ?? $model), 'Report sections saved.');
    }

    /**
     * One link's switches — the SAME registry list as the report's, off-only.
     *
     * Each section reads `hidden_by_report` (the report already hides it; the link cannot change
     * that), `hidden_by_link` or `shown`. The preview is what this link's client gets.
     */
    public function showShare(Request $request, string $project, string $report, string $share): JsonResponse
    {
        $this->authorise($request, $project, 'reports.view');
        [$model, $link] = $this->findShare($report, $share);

        return ApiResponse::success($this->shareShape($model, $link), 'Link sections.');
    }

    public function updateShare(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        $this->authorise($request, $project, 'reports.manage');
        [$model, $link] = $this->findShare($report, $share);

        $surfaces = app(ReportSectionSurfaces::class);
        $before = array_keys($surfaces->hiddenByLink($link));
        $hidden = array_fill_keys($before, true);

        foreach ($this->validatedSections($request, []) as $key => $on) {
            if ($on) {
                unset($hidden[$key]);
            } else {
                $hidden[$key] = true;
            }
        }

        /*
         * The older display flags fold into the overrides and are set back to ON, so the link has one
         * list: switching a section back on here cannot be undone by a flag nobody can see.
         * `attribution` and `previous_comparison` are not sections and are left as they were.
         */
        $settings = (array) ($link->settings ?? []);
        $flags = (array) ($settings['sections'] ?? []);
        foreach (array_keys(ReportSectionSurfaces::SHARE_FLAGS) as $flag) {
            $flags[$flag] = true;
        }
        $settings['sections'] = $flags;
        $settings['section_overrides'] = array_values(array_intersect($this->registry->keys(), array_keys($hidden)));
        $link->settings = $settings;
        $link->save();

        $audit->log(
            action: 'report.shared_link_sections_updated',
            entityType: ReportShare::class,
            entityId: (string) $link->getKey(),
            before: ['hidden' => $before],
            after: ['hidden' => $settings['section_overrides']],
        );

        return ApiResponse::success($this->shareShape($model, $link->fresh() ?? $link), 'Link sections saved.');
    }

    /** @return array{0: Report, 1: ReportShare} */
    private function findShare(string $report, string $share): array
    {
        $model = Report::query()->findOrFail($report);
        $link = ReportShare::query()->where('report_id', $model->getKey())->findOrFail($share);

        return [$model, $link];
    }

    /** @return array<string, mixed> */
    private function shareShape(Report $report, ReportShare $link): array
    {
        $surfaces = app(ReportSectionSurfaces::class);
        $reportOn = $this->resolver->resolve($report->sectionSettings(), $surfaces->contextFor($report, $link, 'preview'));
        $byLink = $surfaces->hiddenByLink($link);
        $data = ! $link->isLive() && is_array($report->data) && $report->data !== [] ? $report->data : null;
        $resolved = $surfaces->resolve($report, $link, 'preview', $data);

        return [
            'share_id' => (string) $link->getKey(),
            'sections' => array_map(fn ($section): array => [
                'key' => $section->key,
                'title_ar' => $section->titleAr,
                'title_en' => $section->titleEn,
                'breakdown' => $section->breakdown,
                'state' => $reportOn->reasonFor($section->key) === ReportSectionResolver::DISABLED_BY_OPERATOR
                    ? 'hidden_by_report'
                    : (isset($byLink[$section->key]) ? 'hidden_by_link' : 'shown'),
            ], $this->registry->all()),
            'resolved' => $resolved->toArray(),
            'visible' => $resolved->visible(),
            'availability_judged' => $resolved->availabilityJudged,
        ];
    }

    public function showTemplate(Request $request, string $project, string $template): JsonResponse
    {
        $this->authorise($request, $project, 'reports.view');

        return ApiResponse::success($this->templateShape($this->findTemplate($project, $template)), 'Template sections.');
    }

    public function updateTemplate(Request $request, AuditLogger $audit, string $project, string $template): JsonResponse
    {
        $this->authorise($request, $project, 'reports.manage');

        $model = $this->findTemplate($project, $template);
        $before = $model->sectionSettings()->toArray();

        $model->section_settings = SectionSettings::fromArray([
            'sections' => $this->validatedSections($request, $before['sections']),
            'streams' => $this->validatedStreams($request, $project, $before['streams'] ?? []),
        ], $this->registry)->toArray();
        $model->save();

        $audit->log(
            action: 'report.scope_template.sections_updated',
            entityType: ReportScopeTemplate::class,
            entityId: (string) $model->getKey(),
            before: $before,
            after: $model->section_settings,
        );

        return ApiResponse::success($this->templateShape($model), 'Template sections saved.');
    }

    /**
     * The submitted choices merged over what was stored. Only known sections, only booleans.
     *
     * A key the registry does not know is refused rather than ignored: an operator who believes they
     * switched something off must be told the switch does not exist.
     *
     * @param  array<string, bool>  $current
     * @return array<string, bool>
     */
    private function validatedSections(Request $request, array $current): array
    {
        $request->validate([
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['boolean'],
        ]);

        $submitted = (array) $request->input('sections', []);
        $unknown = array_diff(array_keys($submitted), $this->registry->keys());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['sections' => 'Unknown report section: '.implode(', ', $unknown)]);
        }

        $merged = $current;
        foreach ($submitted as $key => $value) {
            $merged[(string) $key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return SectionSettings::fromArray(['sections' => $merged], $this->registry)->toArray()['sections'];
    }

    /**
     * The submitted business streams, or the stored ones when none were sent.
     *
     * An account id this project has no figures for is dropped rather than stored: a stream mapped to
     * another client's account would be a way to read that account's spend through this report.
     *
     * @param  list<array<string, mixed>>  $current
     * @return list<array<string, mixed>>
     */
    private function validatedStreams(Request $request, string $project, array $current): array
    {
        if (! $request->has('streams')) {
            return $current;
        }

        $request->validate([
            'streams' => ['present', 'array', 'max:'.SectionSettings::MAX_STREAMS],
            'streams.*.label' => ['required', 'string', 'max:60'],
            'streams.*.providers' => ['sometimes', 'array'],
            'streams.*.providers.*' => ['string', 'max:40'],
            'streams.*.account_ids' => ['sometimes', 'array'],
            'streams.*.account_ids.*' => ['string', 'max:64'],
        ]);

        $known = DailyMetric::query()
            ->where('project_id', $project)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->whereNotNull('external_account_id')
            ->distinct()
            ->pluck('external_account_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return array_map(static function (array $stream) use ($known): array {
            $stream['account_ids'] = array_values(array_intersect((array) ($stream['account_ids'] ?? []), $known));

            return $stream;
        }, array_values((array) $request->input('streams', [])));
    }

    private function isLive(Report $report): bool
    {
        return $report->type === 'live' || (bool) (($report->config ?? [])['live'] ?? false);
    }

    private function findTemplate(string $project, string $template): ReportScopeTemplate
    {
        return ReportScopeTemplate::query()
            ->where(fn ($q) => $q->where('project_id', $project)->orWhereNull('project_id'))
            ->findOrFail($template);
    }

    /** @return array<string, mixed> */
    private function reportShape(Report $report): array
    {
        $audience = (string) ($report->audience ?? 'client');
        $settings = $report->sectionSettings();
        $data = is_array($report->data) && $report->data !== [] ? $report->data : null;

        $resolved = $this->resolver->resolve($settings, new SectionContext(
            audience: $audience,
            form: (string) ($report->form ?? 'detailed'),
            providers: array_values(array_filter((array) (($report->scope ?? [])['providers'] ?? []), 'is_string')),
            objective: $report->campaign_objective,
            payload: $data,
            surface: 'preview',
        ));

        return [
            'report_id' => (string) $report->getKey(),
            'audience' => $audience,
            'chosen' => $settings->toArray()['sections'],
            'streams' => $settings->streams(),
            'effective' => $settings->effective($this->registry, $audience),
            'resolved' => $resolved->toArray(),
            'visible' => $resolved->visible(),
            /*
             * A live report has no figures until a client opens it, so the preview cannot say whether
             * the data is there — it says it did not look rather than guessing.
             */
            'availability_judged' => $resolved->availabilityJudged,
        ];
    }

    /** @return array<string, mixed> */
    private function templateShape(ReportScopeTemplate $template): array
    {
        $settings = $template->sectionSettings();

        return [
            'template_id' => (string) $template->getKey(),
            'chosen' => $settings->toArray()['sections'],
            'streams' => $settings->streams(),
            'effective' => [
                'client' => $settings->effective($this->registry, 'client'),
                'internal' => $settings->effective($this->registry, 'internal'),
            ],
        ];
    }
}
