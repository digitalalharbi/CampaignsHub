<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use App\Domains\Branding\BrandingSpec;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BRANDING-HIERARCHY-001 — the identity a shared report link carries.
 *
 * A client's report is read by somebody with no session, no way to cross-check and nobody to ask, so
 * two properties matter more here than anywhere else in the product.
 *
 * **The hierarchy is the existing one.** `BrandingService::resolve()` already walks
 * client → tenant → platform for AGENCY-005, and this calls it. A second resolver would drift from
 * the Branding Center the operator actually configures, and the drift would show up as a client's
 * report quietly carrying last year's logo.
 *
 * **Nothing is read from the caller.** Every identifier comes from the share row the TOKEN resolved:
 * the tenant, the client workspace, the project. No asset id, tenant id or scope is accepted as
 * input, because an endpoint that takes one is an endpoint somebody will enumerate — and a shared
 * report link is precisely where a stranger has a URL and time. This is why the method takes a
 * `ReportShare` and not a scope pair.
 *
 * **A missing logo is «no logo», never a broken one.** `logo_url` is null when nothing resolved, and
 * the reader gets the name. A broken image in a client's report looks like the report failed.
 */
final class SharedLinkBranding
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Resolve inside the SHARE's tenant, and put the context back afterwards.
     *
     * `BrandingAsset` is tenant-scoped, and a public link has no tenant in context at all — so
     * without this the resolver quietly found nothing and every shared report fell to the platform
     * layer, with a `logo_url` that then 404'd. That is the «never a broken image» clause failing in
     * the very place it was written for.
     *
     * The tenant comes from the share row the token resolved, never from the request. This is the
     * isolation boundary: one tenant is in context for the duration of one resolve, so there is no
     * arrangement of query parameters that can reach another tenant's assets — the scope is not a
     * filter over everything, it IS everything the query can see.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inTenant(ReportShare $share, callable $work): mixed
    {
        $previous = $this->tenant->tenantId();
        $this->tenant->setTenantId((string) $share->tenant_id);

        try {
            return $work();
        } finally {
            $previous === null ? $this->tenant->forget() : $this->tenant->setTenantId($previous);
        }
    }

    /**
     * @return array{name: string, logo_url: ?string, logo_source: string, by: ?string}
     */
    public function forShare(ReportShare $share, string $rawToken, ?Report $report = null): array
    {
        /*
         * The identity the link was CREATED with wins, where the report has one.
         *
         * `PublicReportController::branding()` already reads `config['branding']` and freezes it on
         * purpose: a link should keep the identity it was shared under even if the agency later
         * rebrands. That decision stands. This resolver exists for the case it does not cover — a
         * report whose config carries no branding at all, which rendered a blank header and is the
         * «never a blank header» clause of BRANDING-HIERARCHY-001.
         */
        $config = (array) ($report === null ? [] : ($report->config ?? []));
        $frozen = (array) ($config['branding'] ?? []);
        if (($frozen['name'] ?? null) !== null || ($frozen['logo_url'] ?? null) !== null) {
            return [
                'name' => (string) ($frozen['name'] ?? 'CampaignsHub'),
                'logo_url' => $frozen['logo_url'] ?? null,
                'logo_source' => 'report',
                'by' => null,
            ];
        }

        $tenant = Tenant::withoutGlobalScopes()->find($share->tenant_id);
        $client = $this->client($share);

        /*
         * The scope is derived, never supplied. A share bound to a client resolves at the client
         * layer; one without falls to the tenant, which `resolve()` then falls to the platform.
         */
        [$scope, $scopeId] = $client !== null
            ? ['client', (string) $client->id]
            : ['tenant', null];

        $asset = $this->inTenant($share, fn () => $this->pickLogo($scope, $scopeId));

        return [
            /*
             * client → agency → CampaignsHub. `ClientWorkspace` has no separate display name — that
             * column lives on `UnifiedCampaign`, and reading it here returned null on every row until
             * PHPStan named it.
             */
            'name' => (string) ($client->name ?? $tenant->name ?? 'CampaignsHub'),
            'logo_url' => $asset === null ? null : $this->tokenUrl($rawToken),
            /*
             * Where the LOGO came from — a separate question from whose NAME is shown.
             *
             * The name follows client → agency → CampaignsHub, so an agency's client report says the
             * agency rather than the product even when no logo exists anywhere. Reporting «platform»
             * as one combined source would have read as «this is CampaignsHub's report», which it is
             * not.
             */
            'logo_source' => $asset === null ? 'none' : (string) $asset->scope,
            /*
             * «بواسطة» — the agency appears SECONDARILY on a client's report, never in place of the
             * client. Absent when the two are the same identity, because «Nakheel, by Nakheel» reads
             * as a bug.
             */
            'by' => $client !== null && $tenant !== null ? (string) $tenant->name : null,
        ];
    }

    /** The workspace this share's report belongs to, resolved through the report's project. */
    private function client(ReportShare $share): ?ClientWorkspace
    {
        /*
         * `withoutGlobalScopes()`, and that is not a shortcut.
         *
         * This runs on a PUBLIC route, where there is no tenant in context at all — the token is the
         * only authority. Reading `$share->report` through the tenant scope returns null for every
         * unauthenticated caller, which silently turned every client's report into the agency's
         * name. The isolation is not the global scope here; it is the explicit tenant comparison
         * below, which is checked rather than assumed.
         */
        $projectId = Report::withoutGlobalScopes()->find($share->report_id)?->project_id;
        if ($projectId === null) {
            return null;
        }

        $project = Project::withoutGlobalScopes()->find($projectId);
        if ($project === null) {
            return null;
        }

        $client = ClientWorkspace::withoutGlobalScopes()->find($project->client_workspace_id);

        /*
         * A workspace from another tenant is not this link's client. It cannot normally happen, and
         * it is checked anyway: the whole value of this class is that a share can only ever answer
         * for its own tenant, and a guard that is only correct while the data is correct is not a
         * guard.
         */
        return $client !== null && (string) $client->tenant_id === (string) $share->tenant_id ? $client : null;
    }

    /**
     * The bytes are addressed by the TOKEN the caller already holds — no asset id in the URL at all.
     *
     * An id in the path is an id somebody will change. There is nothing to change here: the same
     * token that proves the reader may see the report is the only thing that selects the logo.
     */
    private function tokenUrl(string $rawToken): string
    {
        return url("/api/v1/reports/shared/{$rawToken}/branding/logo");
    }

    /**
     * The logo bytes for this share, or null — resolved from the SHARE, never from an id.
     *
     * Same resolution as `forShare()`, so the bytes a reader downloads are the ones the payload
     * named. Null rather than a placeholder: a report with no logo has no logo, and inventing an
     * image would put a brand on a document that carries none.
     */
    public function logoFor(ReportShare $share): ?StreamedResponse
    {
        $client = $this->client($share);
        [$scope, $scopeId] = $client !== null ? ['client', (string) $client->id] : ['tenant', null];

        $asset = $this->inTenant($share, fn () => $this->pickLogo($scope, $scopeId));

        if ($asset === null || ! Storage::disk($asset->disk)->exists($asset->path)) {
            return null;
        }

        return Storage::disk($asset->disk)->response($asset->path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    /** The nearest logo for a scope, in this surface's preference order. */
    private function pickLogo(string $scope, ?string $scopeId): mixed
    {
        $assets = $this->branding->resolve($scope, $scopeId);

        foreach (self::kinds() as $kind) {
            if (isset($assets[$kind])) {
                return $assets[$kind];
            }
        }

        return null;
    }

    /** @return list<string> the kinds this surface will ever ask for, in preference order. */
    public static function kinds(): array
    {
        return array_values(array_intersect(['report_logo', 'client_logo', 'primary_horizontal'], BrandingSpec::KINDS));
    }
}
