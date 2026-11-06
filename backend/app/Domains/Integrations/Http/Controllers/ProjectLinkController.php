<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderHierarchy;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PendingProjectLink;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * INTEG-PROJECT-LINK-001 — one project's view of one authorisation: is a link journey waiting to be resumed,
 * and what has been linked.
 *
 * GET /api/v1/projects/{project}/integrations/link/{connection}
 *
 * Read-only. It serves the two ends of the Project-First journey:
 *
 *  - the RETURN from the provider: `pending` is true only when this user, in this tenant, opened this
 *    connection for THIS project ({@see PendingProjectLink}). The browser's address says which connection
 *    came back; it is never believed about which project it was for.
 *  - the SUCCESS screen: the accounts of this connection that are actively linked to THIS project — and only
 *    those — each with its organisation, its currency and what happens to its money.
 *
 * ## Money is described, never decided here
 *
 * The rule lives where it always did (row currency → account currency → ask the provider once → else
 * withhold; reporting currency USD). This endpoint only says which case an account is in, by asking the same
 * {@see CurrencyConverter} the metrics pipeline asks:
 *
 *   `reporting`        the account already bills in the reporting currency
 *   `converted`        another currency with a stored rate — figures are converted, the original is kept
 *   `withheld_no_rate` a currency with no rate on file — linked, money withheld until a rate exists
 *   `withheld_unknown` the provider has not stated the currency — money withheld, never assumed
 *
 * ## What it never shows
 *
 * A connection of another tenant is a 404, indistinguishable from one that does not exist. Accounts linked
 * to OTHER projects are not listed, not counted and not named.
 */
final class ProjectLinkController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectContext $project,
        private readonly AccountHealth $health,
        private readonly ReportingCurrency $reporting,
        private readonly CurrencyConverter $rates,
    ) {}

    public function __invoke(Request $request, string $project, string $connection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $tenantId = (string) $this->tenant->tenantId();
        $projectId = (string) $this->project->projectId();

        $model = ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $connection)
            ->whereNull('removed_at')
            ->first();
        abort_if($model === null, 404, 'Connection not found.');

        $projectModel = Project::findOrFail($projectId);
        $provider = (string) $model->provider;
        $known = ProviderCatalogue::has($provider);
        $reportingCurrency = $this->reporting->forProject($projectId);

        $accountIds = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->pluck('external_account_id');

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('provider_connection_id', $model->getKey())
            ->whereIn('id', $accountIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'pending' => PendingProjectLink::holds((string) $model->getKey(), $tenantId, $projectId, (int) $request->user()->getKey()),
            'project' => ['id' => $projectId, 'name' => (string) $projectModel->name],
            'connection' => [
                'id' => (string) $model->getKey(),
                'provider' => $provider,
                'label' => $known ? ProviderCatalogue::get($provider)->label : $provider,
                'label_ar' => $known ? ProviderCatalogue::get($provider)->labelAr : $provider,
                'status' => (string) $model->status,
                'has_parent' => ProviderHierarchy::hasParent($provider),
                // The PLATFORM account the grant was made with. Never the workspace user's email.
                'platform_account' => $model->provider_identity_label === null && $model->provider_identity_email === null
                    ? null
                    : ['label' => $model->provider_identity_label, 'email' => $model->provider_identity_email],
            ],
            'reporting_currency' => $reportingCurrency,
            'accounts' => $accounts->map(fn (ExternalAccount $a): array => [
                'id' => (string) $a->getKey(),
                'name' => (string) $a->name,
                'external_id' => (string) $a->external_id,
                'account_type' => (string) $a->account_type,
                'organisation' => $a->parent_name,
                'currency' => $a->currency,
                'money' => $this->money($a->currency, $reportingCurrency),
                'health' => $this->health->for($a),
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
            ])->all(),
        ], __('api.ok'));
    }

    /** @return array{state:string, rate:?float, rate_date:?string} */
    private function money(?string $currency, string $reporting): array
    {
        $currency = $currency === null ? '' : strtoupper(trim($currency));

        if ($currency === '') {
            return ['state' => 'withheld_unknown', 'rate' => null, 'rate_date' => null];
        }

        if ($currency === strtoupper($reporting)) {
            return ['state' => 'reporting', 'rate' => 1.0, 'rate_date' => null];
        }

        $rate = $this->rates->resolve($currency, $reporting, Carbon::now());

        return $rate === null
            ? ['state' => 'withheld_no_rate', 'rate' => null, 'rate_date' => null]
            : ['state' => 'converted', 'rate' => (float) $rate['rate'], 'rate_date' => (string) $rate['rate_date']];
    }
}
