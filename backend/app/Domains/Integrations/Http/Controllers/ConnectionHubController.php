<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderHierarchy;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Support\InsightsAuthorisation;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Integrations\Support\NextScheduledSync;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — the Connection Hub: one row per AUTHORISATION.
 *
 * ## What the row is, and why it is the connection and not the provider
 *
 * The page used to draw one card per PROVIDER, which cannot describe an agency: two Meta
 * authorisations — the agency's own and a client's, granted by two different people — collapsed into
 * one «Meta» card with one state, and the operator asking «which of our two Meta logins has gone
 * stale» had nowhere to look. A `ProviderConnection` is the thing that is authorised, the thing that
 * expires, the thing that gets reconnected, and the thing that owns a catalogue of accounts. So it
 * is the row.
 *
 * ## «1 of 17 accounts» is the sentence this product needed
 *
 * Chosen over discovered, in one phrase, on the row. It is the whole of ACCOUNT-SCOPE-ISOLATION-001
 * said in four words: seventeen accounts are reachable and one of them is ours to sync. A count of
 * discovered accounts alone invites the belief that all of them are being read, which is the belief
 * the Owner had to correct twice.
 *
 * ## The two truths stay two
 *
 * `connection_state` and `sync_state` are reported separately (§16) and no surface may rank one out
 * of existence — that ranking is what hid a Reconnect button on Production behind a stale `running`
 * row (#578).
 *
 * ## Aggregates only
 *
 * A tenant's estate is bounded by its plan, not by what fits on a screen. Accounts are not rendered
 * here at all; the drawer asks `GET integrations/accounts?connection=…`, which is the inventory that
 * already paginates, searches and computes per-account health.
 */
final class ConnectionHubController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProviderConfigurationService $settings,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $tenantId = (string) $this->tenant->tenantId();

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['disconnected'])
            ->orderBy('provider')
            ->orderBy('created_at')
            /*
             * A tiebreaker, because `created_at` is not unique.
             *
             * Two authorisations for one provider opened in the same second share a timestamp, and
             * Postgres is then free to return them in either order — so the hub shuffled two rows
             * between refreshes, and the test asserting their order failed on CI about half the time
             * while passing locally.
             *
             * The id is arbitrary but STABLE, which is the whole requirement: a list a reader
             * returns to should be in the order they left it.
             */
            ->orderBy('id')
            ->get();

        /*
         * The ADVERTISING providers this product carries, and only those — INTEG-RUNTIME §2.
         *
         * Salla and Zid are commerce connectors with a store, an order stream and no ad account:
         * «organisation → ad account» asks them a question they have no answer to, so they keep
         * their own section and their own journey. The sandbox is a local fake that exists so the
         * end-to-end suite and the demo seeder have something to drive without a real platform
         * credential; listing it here would put it on the customer's own page as a source, which is
         * the ninth provider §2 removed.
         */
        $connections = $connections->filter(
            fn (ProviderConnection $c): bool => ProviderCatalogue::has((string) $c->provider)
                && ProviderCatalogue::get((string) $c->provider)->kind === ProviderKind::Advertising,
        )->values();

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_type', 'ad_account')
            ->whereIn('provider_connection_id', $connections->modelKeys())
            ->get(['id', 'provider_connection_id', 'last_synced_at', 'access_lost_at', 'last_sync_error_category'])
            ->groupBy(fn (ExternalAccount $a): string => (string) $a->provider_connection_id);

        $selected = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('project_id')
            ->get(['project_id', 'external_account_id']);

        $selectedIds = $selected->pluck('external_account_id')->map(strval(...))->unique();

        $runs = $selectedIds->isEmpty() ? collect() : MetricSyncRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $selectedIds)
            ->orderByDesc('started_at')
            ->get(['external_account_id', 'status', 'started_at']);

        $projects = Project::withoutGlobalScopes()
            ->whereIn('id', $selected->pluck('project_id')->filter()->unique())
            ->get(['id', 'name'])
            ->keyBy(fn (Project $p): string => (string) $p->getKey());

        $authors = User::query()
            ->whereIn('id', $connections->pluck('created_by')->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy(fn (User $u): string => (string) $u->getKey());

        $projectByAccount = $selected
            ->groupBy(fn ($b): string => (string) $b->external_account_id)
            ->map(fn ($rows) => (string) $rows->first()->project_id);

        $rows = $connections->map(function (ProviderConnection $connection) use ($accounts, $selectedIds, $runs, $projects, $projectByAccount, $authors): array {
            $mine = $accounts->get((string) $connection->getKey(), collect());

            // «Discovered» excludes an account whose access the provider has withdrawn: it is not one
            // of the things this authorisation can still reach, and counting it inflates the sentence.
            $discovered = $mine->filter(fn (ExternalAccount $a): bool => $a->access_lost_at === null);
            $chosen = $discovered->filter(
                fn (ExternalAccount $a): bool => $selectedIds->contains((string) $a->getKey()),
            );
            $chosenIds = $chosen->map(fn (ExternalAccount $a): string => (string) $a->getKey())->values()->all();

            $connectionState = match (true) {
                in_array($connection->status, ['revoked', 'disconnected'], true) => IntegrationTruth::REVOKED,
                $connection->status === 'error',
                $connection->insights_denied_at !== null,
                ! InsightsAuthorisation::grantedBy((string) $connection->provider, $connection->scopes) => IntegrationTruth::REAUTH_REQUIRED,
                default => IntegrationTruth::CONNECTED,
            };

            $syncState = IntegrationTruth::syncStateFromRuns($chosenIds, $runs);
            $author = $connection->created_by === null ? null : $authors->get((string) $connection->created_by);

            $feeds = $chosen
                ->map(fn (ExternalAccount $a): ?string => $projectByAccount->get((string) $a->getKey()))
                ->filter()
                ->unique()
                ->map(fn (string $id): ?array => $projects->has($id)
                    ? ['id' => $id, 'name' => (string) $projects->get($id)->name]
                    : null)
                ->filter()
                ->values()
                ->all();

            $lastSuccess = $chosen->pluck('last_synced_at')->filter()->max();

            return [
                'id' => (string) $connection->getKey(),
                'provider' => (string) $connection->provider,
                'label' => ProviderCatalogue::has((string) $connection->provider)
                    ? ProviderCatalogue::get((string) $connection->provider)->label
                    : (string) $connection->provider,
                'label_ar' => ProviderCatalogue::has((string) $connection->provider)
                    ? ProviderCatalogue::get((string) $connection->provider)->labelAr
                    : (string) $connection->provider,
                'connection_name' => (string) $connection->connection_name,
                /*
                 * WHO authorised it — the CampaignsHub user, because that is the identity this
                 * product actually holds. No provider returns an account email on these grants, and
                 * printing one would be an invention. Null is «nobody recorded it», said as such.
                 */
                'authorised_by' => $author === null ? null : [
                    'name' => (string) $author->name,
                    'email' => (string) $author->email,
                ],
                'authorised_at' => $connection->created_at?->toIso8601String(),
                'selected_accounts' => $chosen->count(),
                'discovered_accounts' => $discovered->count(),
                'connection_state' => $connectionState,
                'sync_state' => $syncState,
                'needs_attention' => $discovered->contains(
                    fn (ExternalAccount $a): bool => $a->last_sync_error_category !== null,
                ),
                'last_success_at' => $lastSuccess === null ? null : Carbon::parse($lastSuccess)->toIso8601String(),
                'next_sync_at' => NextScheduledSync::at(
                    $connectionState === IntegrationTruth::CONNECTED && $chosenIds !== [],
                )?->toIso8601String(),
                // Which clients this authorisation actually feeds — the answer to «who breaks if it lapses».
                'projects' => $feeds,
                'has_parent' => ProviderHierarchy::hasParent((string) $connection->provider),
                'discovery_blocked_reason' => $connection->discovery_blocked_reason,
                'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
            ];
        })->all();

        return ApiResponse::success([
            'connections' => $rows,
            /*
             * The providers a NEW authorisation can be started for, so the hub's one primary action
             * has something to offer. A provider whose system credentials are absent is deliberately
             * not here: offering «Connect» for it produces an OAuth start the server will refuse.
             */
            'connectable' => $this->connectable(),
        ], __('api.ok'));
    }

    /** @return list<array<string,mixed>> */
    private function connectable(): array
    {
        $out = [];

        foreach (ProviderCatalogue::all() as $key => $definition) {
            if ($definition->kind !== ProviderKind::Advertising) {
                continue;
            }

            if (! $this->settings->isEnabled($key) || ! $this->settings->isConfigured($key)) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $definition->label,
                'label_ar' => $definition->labelAr,
                'kind' => $definition->kind->value,
                'has_parent' => ProviderHierarchy::hasParent($key),
                /*
                 * HOW this one is connected, because the two ways look identical from here.
                 *
                 * «Connect» means «leave for a consent screen» for every provider with an app we
                 * registered, and «paste the key you hold» for one the advertiser credentials
                 * themselves. A card that cannot tell them apart promises a redirect that never
                 * comes.
                 */
                'auth' => $definition->auth->value,
            ];
        }

        return $out;
    }
}
