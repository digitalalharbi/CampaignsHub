<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ReachableProjects;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * INTEG-UNIFIED-002 (contract §1.12) — the Integrations Centre's «التدقيق» section: who did what to a source.
 *
 * GET /api/v1/integrations/audit
 *
 * ## Not a new trail
 *
 * Every integration act already writes one row through {@see AuditLogger} — connect,
 * re-authorise, select, link, unlink, detach, remove, revoke, discovery refresh, manual sync. Those rows had
 * exactly one reader, the platform operator's `/admin/audit`. This endpoint is the tenant's own read of ITS
 * rows; it writes nothing, stores nothing and adds no table.
 *
 * ## What it withholds, on purpose
 *
 * `before`, `after`, `reason`, the IP address and the user agent are NOT returned. The payloads are written
 * by a dozen call sites and nothing constrains what a future one puts in them, so the safe contract is a
 * closed one: the act, when, who (by NAME — a workspace email is not this page's business), and what it was
 * done to. The provider is read from the row's own payload only when it is a plain provider key.
 *
 * ## Whose rows
 *
 * `audit_logs` carries no tenant global scope, so the tenant is stated in the query, always. A member whose
 * reach is narrowed to some projects sees only the acts they performed themselves: a row does not reliably
 * name its project, and showing a narrowed member the whole tenant's linking history would hand them the
 * names of clients they cannot open.
 */
final class IntegrationAuditController extends Controller
{
    /** The page sizes the centre's tables offer — the Owner's 10 / 25 / 50. */
    public const PAGE_SIZES = [10, 25, 50];

    /**
     * Action prefixes, grouped the way the centre's three questions are asked.
     *
     * @var array<string, list<string>>
     */
    public const CATEGORIES = [
        'authorisation' => [
            'integration.connect', // also covers integration.connection.* (created, opened, revoked, removed)
            'integration.measurement.connected',
            'integration.store.connected',
            'integration.store.claimed',
        ],
        'binding' => [
            'integration.bound',
            'integration.detached',
            'integration.selection.',
            'integration.account.',
            'integration.scope_cleanup.',
            'commerce.store.assigned',
            'commerce.store.unassigned',
        ],
        'sync' => [
            'metrics.sync_requested',
            'integration.discovery.refreshed',
        ],
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ReachableProjects $reach,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', Rule::in(array_keys(self::CATEGORIES))],
            'per_page' => ['sometimes', 'integer', Rule::in(self::PAGE_SIZES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $tenantId = (string) $this->tenant->tenantId();
        $category = $validated['category'] ?? null;
        $prefixes = $category === null
            ? array_merge(...array_values(self::CATEGORIES))
            : self::CATEGORIES[$category];

        $reach = $this->reach->for($request->user(), ProjectCapability::DASHBOARD_VIEW);
        $narrowed = $reach['only'] !== null || $reach['except'] !== [];

        $page = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $q) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('action', 'like', $prefix.'%');
                }
            })
            ->when($narrowed, fn (Builder $q) => $q->where('user_id', $request->user()->getKey()))
            ->orderByDesc('created_at')
            // `created_at` is not unique: two acts in one second must not swap places between pages.
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? self::PAGE_SIZES[0]));

        $entries = collect($page->items());
        $subjects = $this->subjects($tenantId, $entries);
        $actors = User::query()->whereIn('id', $entries->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');

        return ApiResponse::success([
            'entries' => $entries->map(function (AuditLog $log) use ($subjects, $actors): array {
                $subject = $subjects[(string) $log->getKey()];
                $provider = $subject['provider'];

                return [
                    'id' => (string) $log->getKey(),
                    'action' => (string) $log->action,
                    'category' => self::categoryOf((string) $log->action),
                    // Null when nobody was signed in (a scheduled sweep) or the user has since gone. Left null
                    // rather than filled with a word that would read as a name.
                    'actor_name' => $log->user_id === null ? null : ($actors[$log->user_id] ?? null),
                    'provider' => $provider,
                    'provider_label' => $provider !== null && ProviderCatalogue::has($provider) ? ProviderCatalogue::get($provider)->label : $provider,
                    'provider_label_ar' => $provider !== null && ProviderCatalogue::has($provider) ? ProviderCatalogue::get($provider)->labelAr : $provider,
                    'subject_kind' => $subject['kind'],
                    'subject_name' => $subject['name'],
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            })->all(),
            'page_sizes' => self::PAGE_SIZES,
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ], __('api.ok'));
    }

    public static function categoryOf(string $action): ?string
    {
        foreach (self::CATEGORIES as $category => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * What each row was done to, resolved in one query per kind over the page — never per row.
     *
     * Every lookup states the tenant: an `entity_id` is a value somebody wrote into a log row, and a row
     * that named another tenant's connection must resolve to nothing rather than to that tenant's name.
     *
     * @param  Collection<int, AuditLog>  $entries
     * @return array<string, array{kind:?string, name:?string, provider:?string}>
     */
    private function subjects(string $tenantId, Collection $entries): array
    {
        $kinds = [
            ProviderConnection::class => 'connection', 'provider_connection' => 'connection',
            ExternalAccount::class => 'account', 'external_account' => 'account',
            Project::class => 'project', 'project' => 'project',
            ProjectIntegrationBinding::class => 'binding', 'project_integration_binding' => 'binding',
        ];

        $ids = ['connection' => [], 'account' => [], 'project' => [], 'binding' => []];
        foreach ($entries as $log) {
            $kind = $kinds[(string) $log->entity_type] ?? null;
            if ($kind !== null && $log->entity_id !== null && preg_match('/^[0-9a-f-]{36}$/i', (string) $log->entity_id) === 1) {
                $ids[$kind][] = (string) $log->entity_id;
            }
        }

        $connections = $ids['connection'] === [] ? collect() : ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('id', $ids['connection'])->get(['id', 'provider'])->keyBy('id');
        $bindings = $ids['binding'] === [] ? collect() : ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('id', $ids['binding'])->get(['id', 'provider', 'external_account_id'])->keyBy('id');
        $accountIds = array_merge($ids['account'], $bindings->pluck('external_account_id')->map(static fn (mixed $id): string => (string) $id)->all());
        $accounts = $accountIds === [] ? collect() : ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('id', $accountIds)->get(['id', 'provider', 'name'])->keyBy('id');
        $projects = $ids['project'] === [] ? collect() : Project::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('id', $ids['project'])->get(['id', 'name'])->keyBy('id');

        $out = [];
        foreach ($entries as $log) {
            $kind = $kinds[(string) $log->entity_type] ?? null;
            $id = (string) $log->entity_id;
            $name = null;
            $provider = null;

            if ($kind === 'connection') {
                $provider = $connections->get($id)?->provider;
            } elseif ($kind === 'account') {
                $name = $accounts->get($id)?->name;
                $provider = $accounts->get($id)?->provider;
            } elseif ($kind === 'binding') {
                $binding = $bindings->get($id);
                $provider = $binding?->provider;
                $name = $binding === null ? null : $accounts->get((string) $binding->external_account_id)?->name;
            } elseif ($kind === 'project') {
                $name = $projects->get($id)?->name;
            }

            $out[(string) $log->getKey()] = [
                'kind' => $kind,
                'name' => $name === null ? null : (string) $name,
                'provider' => $provider === null ? $this->providerFromPayload($log) : (string) $provider,
            ];
        }

        return $out;
    }

    /** The provider key a row wrote about itself — taken only when it is a key the catalogue knows. */
    private function providerFromPayload(AuditLog $log): ?string
    {
        foreach ([$log->after, $log->before] as $payload) {
            $value = is_array($payload) ? ($payload['provider'] ?? null) : null;
            if (is_string($value) && ProviderCatalogue::has($value)) {
                return $value;
            }
        }

        return null;
    }
}
