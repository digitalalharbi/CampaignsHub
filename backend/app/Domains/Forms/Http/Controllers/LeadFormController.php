<?php

declare(strict_types=1);

namespace App\Domains\Forms\Http\Controllers;

use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Forms\Support\FormDefinition;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * FORMS-HUB-001 — the team's side of a project's lead forms: build, version, publish, retire.
 *
 * Reading needs `leads.view`, changing needs `leads.update`; both are asked on the project the form
 * belongs to, which the `project` middleware has already tied to the caller's tenant. A form is addressed
 * by (project, id), so an id from another project is a 404, not a different form.
 *
 * A published version is never edited: saving a changed definition on a form whose latest version is
 * published starts the next version as a draft. Publishing makes the latest draft live and is the only
 * thing that does.
 */
final class LeadFormController extends Controller
{
    public function __construct(private readonly ProjectContext $context, private readonly ProjectAbilities $abilities) {}

    public function index(Request $request, string $project): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_VIEW);

        $forms = LeadForm::query()->where('project_id', $this->projectId($project))->orderByDesc('created_at')->limit(100)->get();
        $counts = LeadFormSubmission::query()->whereIn('lead_form_id', $forms->pluck('id'))->selectRaw('lead_form_id, count(*) as n')->groupBy('lead_form_id')->pluck('n', 'lead_form_id');

        return ApiResponse::success($forms->map(fn (LeadForm $f): array => $this->shape($f) + ['submissions' => (int) ($counts[$f->id] ?? 0)])->values()->all(), 'Forms.');
    }

    public function show(Request $request, string $project, string $form): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_VIEW);
        $model = $this->find($project, $form);
        $latest = $model->versions()->first();

        return ApiResponse::success($this->shape($model) + [
            'definition' => $latest?->definition,
            'definition_version' => $latest?->version,
            'definition_published' => $latest?->published_at !== null,
            'versions' => $model->versions()->get()->map(fn (LeadFormVersion $v): array => ['version' => $v->version, 'published_at' => $v->published_at?->toIso8601String(), 'checksum' => $v->checksum])->all(),
        ], 'Form.');
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'definition' => ['required', 'array'],
            'default_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'retention_days' => ['nullable', 'integer', 'min:30', 'max:3650'],
        ]);
        $definition = $this->normalise($data['definition']);
        $owner = $this->owner($data['default_owner_id'] ?? null, $project);

        $form = LeadForm::create([
            'project_id' => $this->projectId($project),
            'name' => $data['name'],
            'status' => LeadForm::DRAFT,
            'default_owner_id' => $owner,
            'retention_days' => $data['retention_days'] ?? null,
            'created_by' => $request->user()?->id,
        ]);
        $this->version($form, $definition, 1, $request->user()?->id);

        return ApiResponse::success($this->shape($form), 'Form saved.', status: 201);
    }

    public function update(Request $request, string $project, string $form): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $model = $this->find($project, $form);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'definition' => ['sometimes', 'array'],
            'default_owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'retention_days' => ['sometimes', 'nullable', 'integer', 'min:30', 'max:3650'],
        ]);

        if (isset($data['name'])) {
            $model->name = $data['name'];
        }
        if (array_key_exists('default_owner_id', $data)) {
            $model->default_owner_id = $this->owner($data['default_owner_id'], $project);
        }
        if (array_key_exists('retention_days', $data)) {
            $model->retention_days = $data['retention_days'];
        }
        $model->save();

        if (isset($data['definition'])) {
            $definition = $this->normalise($data['definition']);
            $latest = $model->versions()->first();
            if ($latest !== null && $latest->published_at === null) {
                $latest->forceFill(['definition' => $definition, 'checksum' => $this->checksum($definition)])->save();
            } else {
                $this->version($model, $definition, ($latest === null ? 0 : $latest->version) + 1, $request->user()?->id);
            }
        }

        return ApiResponse::success($this->shape($model->refresh()), 'Form saved.');
    }

    public function publish(Request $request, string $project, string $form): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $model = $this->find($project, $form);
        $latest = $model->versions()->first();
        abort_if($latest === null, 409, 'The form has no version to publish.');

        if ($latest->published_at === null) {
            $latest->forceFill(['published_at' => Carbon::now()])->save();
        }
        $model->forceFill(['status' => LeadForm::PUBLISHED, 'published_version_id' => $latest->id])->save();

        return ApiResponse::success($this->shape($model->refresh()), 'Form published.');
    }

    public function archive(Request $request, string $project, string $form): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $model = $this->find($project, $form);
        $model->forceFill(['status' => LeadForm::ARCHIVED])->save();

        return ApiResponse::success($this->shape($model->refresh()), 'Form archived.');
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalise(array $raw): array
    {
        try {
            return FormDefinition::normalise($raw);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    /** @param array<string,mixed> $definition */
    private function version(LeadForm $form, array $definition, int $n, ?int $by): LeadFormVersion
    {
        return LeadFormVersion::create([
            'tenant_id' => $form->tenant_id,
            'lead_form_id' => $form->id,
            'version' => $n,
            'definition' => $definition,
            'checksum' => $this->checksum($definition),
            'created_by' => $by,
        ]);
    }

    /** @param array<string,mixed> $definition */
    private function checksum(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** The default owner must be somebody who can work this project's leads — an id alone proves nothing. */
    private function owner(mixed $id, string $project): ?int
    {
        if ($id === null) {
            return null;
        }
        $user = User::query()->find((int) $id);
        abort_unless($user !== null && $this->abilities->allows($user, $this->projectId($project), ProjectCapability::LEADS_UPDATE), 422, 'That person cannot work this project\'s leads.');

        return (int) $id;
    }

    private function may(Request $request, string $project, string $capability): void
    {
        $user = $request->user();
        abort_unless($user !== null && $this->abilities->allows($user, $this->projectId($project), $capability), 403);
    }

    private function find(string $project, string $form): LeadForm
    {
        $model = LeadForm::query()->where('project_id', $this->projectId($project))->find($form);
        abort_if($model === null, 404, 'Form not found.');

        return $model;
    }

    private function projectId(string $project): string
    {
        return $this->context->projectId() ?? $project;
    }

    /** @return array<string,mixed> */
    private function shape(LeadForm $f): array
    {
        return [
            'id' => (string) $f->id,
            'name' => $f->name,
            'status' => $f->status,
            'slug' => $f->slug,
            'public_url' => Frontend::url('/f/'.$f->slug),
            'default_owner_id' => $f->default_owner_id,
            'retention_days' => $f->retention_days,
            'published_version_id' => $f->published_version_id,
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
