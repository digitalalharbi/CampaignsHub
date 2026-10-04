<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REPORT-IDENTITY-001 — the two identities a report will carry, BEFORE one exists.
 *
 * ## Why this endpoint
 *
 * Every report surface already resolves «who prepared this» and «who is it for»: the shared link,
 * the print route and the share card all call {@see SharedLinkBranding::forReport()}. The report
 * BUILDER could not, for the ordinary reason that there is no report yet and no share token to
 * address one by — so the one screen where an operator decides what to send showed neither identity.
 *
 * It resolves the same way, through the same service, from an UNSAVED report carrying only the
 * project. `clientOfReport()` reads `project_id` and nothing else, so this is the identity the real
 * report will have — not a second opinion about it. Nothing is written.
 *
 * ## Isolation
 *
 * The route is authenticated, tenant-scoped and project-bound: `ResolveProject` has already proved
 * this project belongs to the caller's tenant before anything here runs, and the resolver is handed
 * that tenant's id rather than one from the request. The logo bytes are addressed by ROLE and
 * re-resolved from the project — there is no asset id in the URL to change, which is the same rule
 * the shared route follows and for the same reason.
 */
final class ReportIdentityController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** GET /projects/{project}/report-identity — the identities a report for this project will carry. */
    public function show(Request $request, Project $project, SharedLinkBranding $branding): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $tenantId = (string) $this->tenant->tenantId();

        return response()->json([
            'success' => true,
            'data' => $branding->forReport(
                $this->draftFor($project),
                $tenantId,
                /*
                 * Addressed by ROLE, like the shared route. A URL carrying an asset id is a URL
                 * somebody can edit into another tenant's asset; this one re-resolves from the
                 * project every time and can only ever answer with that project's marks.
                 */
                fn (?string $role = null) => route('api.v1.projects.scoped.report-identity.logo', [
                    'project' => $project->getKey(),
                    'role' => $role ?? 'auto',
                ], absolute: false),
            ),
        ]);
    }

    /** GET /projects/{project}/report-identity/logo/{role} — those marks' bytes. */
    public function logo(Request $request, Project $project, string $role, SharedLinkBranding $branding): mixed
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $file = $branding->logoFor(
            $this->draftFor($project),
            (string) $this->tenant->tenantId(),
            $role === 'auto' ? null : $role,
        );

        /*
         * 404 when nothing resolves, deliberately. The caller has already been told `logo_url: null`
         * and should not have asked, and inventing a placeholder here would put a mark on a report
         * that has none — which is the one thing the fallback-to-a-name rule exists to prevent.
         */
        abort_unless($file !== null, 404);

        return $file;
    }

    /**
     * An unsaved report, carrying only what the resolver reads.
     *
     * Not persisted and never returned: `clientOfReport()` needs `project_id` and nothing else, so
     * this is the cheapest honest way to ask «what identity would a report for this project have».
     */
    private function draftFor(Project $project): Report
    {
        $draft = new Report;
        $draft->project_id = $project->getKey();

        return $draft;
    }
}
