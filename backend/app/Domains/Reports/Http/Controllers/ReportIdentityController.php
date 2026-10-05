<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Services\BrandingService;
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

        $identity = $branding->forReport(
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
        );

        return response()->json([
            'success' => true,
            'data' => $identity + ['upload' => $this->uploadTargets($request, $project)],
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
     * DELETE /projects/{project}/report-identity/logo/{role} — put a role back to its NAME.
     *
     * «لا يوجد إزالة واضحة للشعار.» An operator could upload a mark from the builder and could not
     * take it off, so the only way back to «no logo» was the Branding Center and a guess about which
     * of its rows the report was reading.
     *
     * ## What it is allowed to delete
     *
     * Exactly the marks of THIS role, in THIS role's own slot, that a report would read: the tenant's
     * for the company, this project's client for the client, in the kinds
     * {@see SharedLinkBranding::kinds()} names. Nothing else — and the scope is derived from the
     * project rather than taken from the request, so there is no id in the URL that could be edited
     * into another client's mark or the platform's.
     *
     * The platform layer is unreachable by construction: `scope` is only ever `tenant` or `client`
     * here, and a `platform` row carries neither. A client's mark cannot be removed by asking for the
     * company's, and vice versa.
     */
    public function removeLogo(Request $request, Project $project, string $role, BrandingService $branding): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.manage'), 403);

        [$scope, $scopeId] = $this->slotFor($role, $project);

        abort_unless($scope !== null, 404);

        $removed = 0;

        foreach (BrandingAsset::query()
            ->where('scope', $scope)
            ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scopeId))
            ->whereIn('kind', SharedLinkBranding::kinds())
            ->get() as $asset) {
            $branding->removeAsset($asset);
            $removed++;
        }

        return response()->json([
            'success' => true,
            'data' => ['removed' => $removed, 'role' => $role],
            'message' => $role === 'client' ? 'Client logo removed.' : 'Company logo removed.',
        ]);
    }

    /**
     * A role's own storage slot — the SAME pair `uploadTargets()` offers and the resolver reads.
     *
     * One mapping for reading, writing and removing: three copies of «the company is the tenant
     * layer» is how a remove comes to clear a slot nothing was ever shown from.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function slotFor(string $role, Project $project): array
    {
        if ($role === 'agency' || $role === 'company') {
            return ['tenant', null];
        }

        if ($role !== 'client') {
            return [null, null];
        }

        $clientId = $project->client_workspace_id;

        return $clientId === null ? [null, null] : ['client', (string) $clientId];
    }

    /**
     * WHERE each mark would be stored, for an operator who has none yet.
     *
     * The builder is where somebody sets a report up, and discovering there that a client has no
     * mark used to mean leaving for the Branding Center and coming back. These are the two slots
     * the Branding Center itself writes — tenant for the company, this project's client for the
     * client — so a mark uploaded here is the SAME mark, configured once and reused by every report.
     * Nothing is stored per report.
     *
     * Absent entirely for somebody who may not manage branding: an interface that offers an upload
     * and then answers 403 is worse than one that does not offer it.
     *
     * @return array<string, array{scope: string, scope_id: ?string}>|null
     */
    private function uploadTargets(Request $request, Project $project): ?array
    {
        if ($request->user()?->hasPermission('branding.manage') !== true) {
            return null;
        }

        $clientId = $project->client_workspace_id;

        return [
            'company' => ['scope' => 'tenant', 'scope_id' => null],
            'client' => $clientId === null ? null : ['scope' => 'client', 'scope_id' => (string) $clientId],
        ];
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
