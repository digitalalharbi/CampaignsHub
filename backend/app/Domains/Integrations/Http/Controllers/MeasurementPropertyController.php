<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\Ga4ReportFailed;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /**
     * GET /measurement/properties — one card per measurement provider, plus what it can see.
     *
     * The same shape as the commerce board, deliberately. The six situations a measurement
     * connection can be in are the six a store connection can be in, and an operator who has learned
     * that «بانتظار بيانات الاعتماد» means «the platform operator has not finished» should not have
     * to learn a second phrase for the same fact one section further down.
     *
     * Driven off `ProviderCatalogue::ofKind(Measurement)` rather than off the string `ga4`, so a
     * second measurement source needs no change here.
     *
     * Nothing in this response names a system credential. `awaiting_credentials` means «the platform
     * operator has not finished setting this up», said without saying which key is absent: a customer
     * cannot obtain an OAuth client secret for our app and does not need to know the shape of our
     * registration.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $tenantId = (string) $this->tenant->tenantId();
        $out = [];

        foreach (ProviderCatalogue::ofKind(ProviderKind::Measurement) as $definition) {
            $creds = PlatformCredentials::for($definition->key);

            $connection = ProviderConnection::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('provider', $definition->key)
                ->whereIn('status', ['connected', 'error'])
                ->latest('updated_at')
                ->first();

            /*
             * An empty ELOQUENT collection, not `collect()`.
             *
             * The two branches have to be the same type or the union loses the model collection's
             * own methods — `modelKeys()` below was the one that noticed, and only under static
             * analysis, because the null branch never reaches it at runtime.
             */
            $properties = $connection === null
                ? new EloquentCollection
                : ExternalAccount::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('provider_connection_id', $connection->getKey())
                    ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
                    ->orderBy('parent_name')
                    ->orderBy('name')
                    ->get();

            $selected = $properties->isEmpty() ? collect() : ProjectIntegrationBinding::withoutGlobalScopes()
                ->whereIn('external_account_id', $properties->modelKeys())
                ->where('is_active', true)
                ->pluck('project_id', 'external_account_id');

            $state = match (true) {
                /*
                 * Ordered so an out-of-service provider reads as such even when its keys are
                 * complete — otherwise an operator is offered a connect button the OAuth start is
                 * going to refuse.
                 */
                ! $this->settings->isEnabled($definition->key) => 'unavailable',
                ! $creds->isConfigured() => 'awaiting_credentials',
                $connection === null || $properties->isEmpty() => 'disconnected',
                $connection->status === 'error' => 'error',
                default => 'connected',
            };

            $out[] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'label_ar' => $definition->labelAr,
                'state' => $state,
                'connection_error' => $connection?->last_error,
                /*
                 * «2 of 17 properties» is this family's version of the sentence the hub rows carry.
                 * A count of discovered properties alone invites the belief that all of them are
                 * being read, which is exactly the belief DISCOVERED ≠ SELECTED exists to correct.
                 */
                'discovered_count' => $properties->count(),
                'selected_count' => $selected->count(),
                'properties' => $properties->map(fn (ExternalAccount $a): array => [
                    'id' => (string) $a->getKey(),
                    'property_id' => (string) $a->external_id,
                    'name' => (string) $a->name,
                    /* The GA4 hierarchy, kept: a property reads as «Acme Group → Acme Store». */
                    'analytics_account_id' => $a->parent_external_id === null ? null : (string) $a->parent_external_id,
                    'analytics_account_name' => $a->parent_name === null ? null : (string) $a->parent_name,
                    /*
                     * Null until the first sync asked the property for its own settings. Discovery
                     * does not ask — see `Ga4PropertySync` — so a never-synced property honestly says
                     * «not known yet» rather than being shown a guessed UTC and a guessed currency.
                     */
                    'timezone' => $a->timezone,
                    'currency' => $a->currency,
                    'is_selected' => $selected->has((string) $a->getKey()),
                    'project_id' => $selected->get((string) $a->getKey()),
                    'last_synced_at' => optional($a->last_synced_at)->toIso8601String(),
                    'discovered_at' => optional($a->discovered_at)->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return ApiResponse::success($out, 'Measurement connections retrieved.');
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
