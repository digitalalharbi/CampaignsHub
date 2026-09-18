<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportScopeTemplate;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Sections\ReportSectionResolver;
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
            $base = $this->findTemplate($project, (string) $request->input('template_id'))->sectionSettings()->toArray()['sections'];
        }

        $sections = $this->validatedSections($request, $base ?? $before['sections']);

        $model->section_settings = ['sections' => $sections];
        $model->save();

        $audit->log(
            action: 'report.sections_updated',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: $before,
            after: $model->section_settings,
        );

        return ApiResponse::success($this->reportShape($model->fresh() ?? $model), 'Report sections saved.');
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

        $model->section_settings = ['sections' => $this->validatedSections($request, $before['sections'])];
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
            'effective' => [
                'client' => $settings->effective($this->registry, 'client'),
                'internal' => $settings->effective($this->registry, 'internal'),
            ],
        ];
    }
}
