<?php

declare(strict_types=1);

namespace App\Domains\Forms\Http\Controllers;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Forms\Services\SubmitLeadForm;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FORMS-HUB-001 — the form as a stranger meets it: read the published version, send one submission.
 *
 * Unauthenticated by design, so everything it can reveal is chosen here: the published definition and
 * nothing of the workspace around it — no tenant, no project, no owner, no count. A form that is not
 * published, is archived, or whose workspace has switched the module off is simply not found.
 */
final class PublicLeadFormController extends Controller
{
    public function __construct(private readonly SubmitLeadForm $submit, private readonly Capabilities $capabilities) {}

    public function show(string $slug): JsonResponse
    {
        [$form, $version] = $this->live($slug);

        return ApiResponse::success([
            'name' => $form->name,
            'version' => $version->version,
            'definition' => $version->definition,
        ], 'Form.');
    }

    public function submit(Request $request, string $slug): JsonResponse
    {
        [$form] = $this->live($slug);

        /*
         * A honeypot: a field no person sees and no label asks for. A bot that fills every input fills this
         * one; the answer looks like success so it learns nothing, and nothing is stored.
         */
        if (trim((string) $request->input('website', '')) !== '') {
            return ApiResponse::success(['accepted' => true, 'duplicate' => false], 'Received.');
        }

        $result = $this->submit->handle($form, $request->only(['submission_id', 'answers', 'consent', 'attribution', 'session_id']));

        return ApiResponse::success([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'reference' => substr((string) $result['submission']->id, 0, 8),
        ], 'Received.', status: $result['duplicate'] ? 200 : 201);
    }

    /** @return array{0: LeadForm, 1: LeadFormVersion} */
    private function live(string $slug): array
    {
        $form = LeadForm::withoutGlobalScopes()->where('slug', $slug)->where('status', LeadForm::PUBLISHED)->first();
        $version = $form === null ? null : LeadFormVersion::withoutGlobalScopes()->find($form->published_version_id);
        abort_if($form === null || $version === null, 404, 'Form not found.');

        $tenant = Tenant::query()->find($form->tenant_id);
        $project = Project::withoutGlobalScopes()->where('tenant_id', $form->tenant_id)->find($form->project_id);
        abort_if(
            $tenant === null || $project === null
            || ! $this->capabilities->allows('forms_hub', $tenant, $project->client_workspace_id === null ? null : (string) $project->client_workspace_id, (string) $project->id),
            404,
            'Form not found.',
        );

        return [$form, $version];
    }
}
