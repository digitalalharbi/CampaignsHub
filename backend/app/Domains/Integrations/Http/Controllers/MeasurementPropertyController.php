<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\Ga4ReportFailed;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GA4-INTEGRATION-001 — the properties this tenant's identities can see, and pulling one on demand.
 *
 * ## Why there is no «select» action here
 *
 * A property becomes a project's through the EXISTING binding endpoint
 * (`POST projects/{project}/integrations/bindings`, `purpose = analytics`), which already enforces the
 * client-workspace fence, the one-active-binding rule and the plan quota. A second selection path
 * would be a second place for those three to be forgotten — and the owner's constraint is explicit:
 * «Do not create a parallel integrations engine.»
 *
 * So this controller reads, and syncs. Selection belongs where selection already lives.
 */
final class MeasurementPropertyController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * GET /measurement/properties — every property discovered for this tenant, selected or not.
     *
     * `is_selected` is stated per row rather than filtered out, because an operator's question is
     * usually «which of these did we connect?» and a list that silently omitted the rest would make
     * a property that was never selected look like one that was never found.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', (string) $this->tenant->tenantId())
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
            ->orderBy('parent_name')
            ->orderBy('name')
            ->get();

        $selected = ProjectIntegrationBinding::withoutGlobalScopes()
            ->whereIn('external_account_id', $accounts->pluck('id')->all())
            ->where('is_active', true)
            ->pluck('project_id', 'external_account_id');

        return ApiResponse::success([
            'properties' => $accounts->map(fn (ExternalAccount $a): array => [
                'id' => (string) $a->getKey(),
                'property_id' => (string) $a->external_id,
                'name' => (string) $a->name,
                /* The GA4 hierarchy, kept: a property is read as «Acme Group → Acme Store». */
                'analytics_account_id' => $a->parent_external_id === null ? null : (string) $a->parent_external_id,
                'analytics_account_name' => $a->parent_name === null ? null : (string) $a->parent_name,
                /*
                 * Null until the first sync asked the property for its own settings. Discovery does
                 * not ask — see `Ga4PropertySync` — so a never-synced property honestly says «not
                 * known yet» rather than being shown a guessed UTC and a guessed currency.
                 */
                'timezone' => $a->timezone,
                'currency' => $a->currency,
                'is_selected' => $selected->has((string) $a->getKey()),
                'project_id' => $selected->get((string) $a->getKey()),
                'last_synced_at' => optional($a->last_synced_at)->toIso8601String(),
                'discovered_at' => optional($a->discovered_at)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /** POST /measurement/properties/{account}/sync — pull a selected property's days now. */
    public function sync(Request $request, string $account, Ga4PropertySync $sync): JsonResponse
    {
        /*
         * `integrations.view`, which is what the product's other two on-demand syncs ask for —
         * `IntegrationController::sync` and the store sync both do.
         *
         * `integrations.manage` was written here first and is not a tenant-level permission at all:
         * the tenant vocabulary is view/connect/disconnect, and `integrations.manage` exists only as
         * a PROJECT permission behind `project.can:`. Asking for it meant nobody — including an owner
         * holding every permission there is — could ever press sync.
         *
         * The quota a sync spends is bounded by the route's throttle rather than by the permission,
         * the same way the other two are.
         */
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $validated = $request->validate([
            /*
             * Bounded at 365. The Data API meters in TOKENS per property per day, and a wide report
             * over two years can exhaust a client's whole allowance in one request — after which
             * every sync on that property, including the scheduled ones, is refused until midnight.
             */
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $model = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', (string) $this->tenant->tenantId())
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
            ->find($account);

        abort_if($model === null, 404);

        try {
            $result = $sync->sync($model, $validated['days'] ?? null);
        } catch (Ga4NotSelected $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        } catch (Ga4ReportFailed $e) {
            // Redacted first: a Google failure message can name the URL that failed, token and all.
            return ApiResponse::error(ProviderErrorText::forDisplay($e->getMessage()), status: 502);
        }

        return ApiResponse::success($result, 'Property synced.');
    }
}
