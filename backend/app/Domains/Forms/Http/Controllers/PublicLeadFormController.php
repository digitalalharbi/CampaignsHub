<?php

declare(strict_types=1);

namespace App\Domains\Forms\Http\Controllers;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Forms\Conversions\ConversionKinds;
use App\Domains\Forms\Models\ConversionDestination;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Forms\Services\FormFunnel;
use App\Domains\Forms\Services\SubmitLeadForm;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * FORMS-HUB-001 — the form as a stranger meets it: read the published version, send one submission.
 *
 * Unauthenticated by design, so everything it can reveal is chosen here: the published definition and
 * nothing of the workspace around it — no tenant, no project, no owner, no count. A form that is not
 * published, is archived, or whose workspace has switched the module off is simply not found.
 */
final class PublicLeadFormController extends Controller
{
    public function __construct(private readonly SubmitLeadForm $submit, private readonly Capabilities $capabilities, private readonly FormFunnel $funnel) {}

    public function show(string $slug): JsonResponse
    {
        [$form, $version] = $this->live($slug);

        // The one tag manager container the team made live for this form, if any — the page loads it, nothing else does.
        $container = ConversionDestination::withoutGlobalScopes()
            ->where('tenant_id', $form->tenant_id)->where('project_id', $form->project_id)
            ->where('kind', ConversionKinds::GTM_WEB)->where('status', ConversionDestination::LIVE)
            ->where(fn ($q) => $q->whereNull('lead_form_id')->orWhere('lead_form_id', $form->id))
            ->orderByRaw('lead_form_id is null')->first();

        return ApiResponse::success([
            'name' => $form->name,
            'version' => $version->version,
            'definition' => $version->definition,
            'tracking' => ['gtm_container_id' => $container?->config['container_id'] ?? null],
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

        $result = $this->submit->handle($form, $request->only(['submission_id', 'answers', 'consent', 'attribution', 'session_id', 'ref']));

        return ApiResponse::success([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'reference' => substr((string) $result['submission']->id, 0, 8),
            // The deduplication id: the page gives the same one to its browser tag, so a platform that hears from both counts one conversion.
            'event_id' => (string) $result['submission']->id,
        ], 'Received.', status: $result['duplicate'] ? 200 : 201);
    }

    /**
     * FORMS-FUNNEL-EVENTS-001 — the browser's account of a visit: viewed, started, finished a step, tried to send.
     *
     * Always 204. Whether anything was recorded depends on the workspace's tracking switch and the consent state
     * the page reported, and a stranger is not told either; a refused event type is the one thing that is
     * answered, so a broken page is found in testing rather than silently missing from a dashboard.
     */
    public function event(Request $request, string $slug): Response
    {
        [$form] = $this->live($slug);
        abort_unless(in_array((string) $request->input('event'), TrackingEvent::BROWSER_FUNNEL, true), 422, 'Unknown event.');

        $this->funnel->fromBrowser($form, $request->only(['event', 'event_id', 'session_id', 'step', 'consent', 'attribution']), $request);

        return response()->noContent();
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
