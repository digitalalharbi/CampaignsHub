<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderHierarchy;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\ConnectionRemoval;
use App\Domains\Integrations\Services\IntegrationStatusResolver;
use App\Domains\Integrations\Status\ConnectionFacts;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Integrations\Support\NextScheduledSync;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ReachableProjects;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
        private readonly ReachableProjects $reach,
        private readonly IntegrationStatusResolver $status,
    ) {}

    /** @return list<string>|null null = every project of the tenant */
    private function reachableProjectIds(Request $request, string $tenantId): ?array
    {
        $reach = $this->reach->for($request->user(), ProjectCapability::DASHBOARD_VIEW);

        if ($reach['only'] === null && $reach['except'] === []) {
            return null;
        }

        $q = Project::withoutGlobalScopes()->where('tenant_id', $tenantId);
        $this->reach->constrain($q, $request->user(), 'id', ProjectCapability::DASHBOARD_VIEW);

        return $q->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $tenantId = (string) $this->tenant->tenantId();

        $inProjectSpace = is_string($request->query('project_id')) && $request->query('project_id') !== '';

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            /*
             * `disconnected` stays off the workspace-wide board. A PROJECT's space lists it when the project
             * still holds an active link through it: the project's card counts that account, and a count the
             * space cannot show is a number with nothing behind it. Everything a project is not linked to is
             * dropped further down, so this adds a row only where a link exists.
             */
            ->when(! $inProjectSpace, fn ($q) => $q->whereNotIn('status', ['disconnected']))
            /*
             * INTEG-SOURCE-REMOVAL-001 — a removed source, and one whose authorisation was revoked, are
             * not listed by default: they stayed on this page for ever and nothing could take them off.
             * `?include_removed=1` is the «show removed» toggle and lists them, marked.
             */
            ->when(! $request->boolean('include_removed'), fn ($q) => $q->whereNull('removed_at')->where('status', '!=', 'revoked'))
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
            ->get(['id', 'provider_connection_id', 'client_workspace_id', 'name', 'external_id', 'parent_name', 'currency', 'last_synced_at', 'access_lost_at', 'last_sync_error_category', 'last_sync_attempt_at', 'next_sync_at'])
            ->groupBy(fn (ExternalAccount $a): string => (string) $a->provider_connection_id);

        /*
         * SEC-CLUSTER-2 — a restricted reader sees only the projects they reach, and the authorisations that
         * serve them. The hub named every connection of the tenant, its accounts and the projects they feed;
         * a teammate confined to one client read the whole agency. `$reach` is the same set the inventory
         * uses; null = every project (agency-wide readers keep the page as it was).
         */
        $reachable = $this->reachableProjectIds($request, $tenantId);

        /*
         * INTEG-PROJECT-SPACE-001 (Owner 2026-11-06) — asked for ONE project, the hub is that project's own space.
         *
         * The centre is the selected project's screen: switching the project makes the other project's sources
         * disappear, and here that means they are not read into the answer at all — not another project's
         * connection, account, name or count. A project the reader does not reach, or of another tenant, is a
         * 404 like one that does not exist.
         */
        $only = $request->validate(['project_id' => ['sometimes', 'nullable', 'uuid']])['project_id'] ?? null;
        if ($only !== null) {
            abort_unless(
                ($reachable === null || in_array($only, $reachable, true))
                    && Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $only)->whereNull('deleted_at')->exists(),
                404,
                'Project not found.',
            );
        }

        $selected = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('project_id')
            ->when($reachable !== null, fn ($q) => $q->whereIn('project_id', $reachable ?: ['00000000-0000-0000-0000-000000000000']))
            ->when($only !== null, fn ($q) => $q->where('project_id', $only))
            ->get(['project_id', 'external_account_id', 'sync_enabled']);

        if ($only !== null) {
            /*
             * Only what THIS project reads. The accounts of a connection that this project is not linked to —
             * another project's, or the never-linked estate — are dropped before anything is counted, so
             * «discovered» on a project's row can never be the estate. A connection left with nothing is not
             * a row: that is every other project's source, every dead or zero-account one, and a sign-in that
             * was started for this project and never finished.
             */
            $linkedHere = $selected->pluck('external_account_id')->map(strval(...))->unique()->all();
            $accounts = $accounts
                ->map(fn ($rows) => $rows->filter(fn (ExternalAccount $a): bool => in_array((string) $a->getKey(), $linkedHere, true))->values())
                ->filter(fn ($rows) => $rows->isNotEmpty());
            $connections = $connections->filter(fn (ProviderConnection $c): bool => $accounts->has((string) $c->getKey()))->values();
        }

        if ($reachable !== null) {
            $clients = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $reachable)
                ->pluck('client_workspace_id')->filter()->map(strval(...))->unique()->all();
            $boundHere = $selected->pluck('external_account_id')->map(strval(...))->unique()->all();
            $held = ProjectIntegrationBinding::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->pluck('external_account_id')->map(strval(...))->all();

            // Authorisations whose accounts belong to projects the reader cannot reach: not theirs to see or name.
            $heldAway = $accounts->filter(fn ($rows) => $rows->contains(
                fn (ExternalAccount $a): bool => in_array((string) $a->getKey(), $held, true) && ! in_array((string) $a->getKey(), $boundHere, true),
            ))->keys()->all();

            // Accounts the reader may see: bound to a project they reach, or bound to none and tenant-level / their client's.
            $accounts = $accounts->map(fn ($rows) => $rows->filter(
                fn (ExternalAccount $a): bool => in_array((string) $a->getKey(), $boundHere, true)
                    || (! in_array((string) $a->getKey(), $held, true)
                        && ($a->client_workspace_id === null || in_array((string) $a->client_workspace_id, $clients, true))),
            ))->filter(fn ($rows) => $rows->isNotEmpty());

            $servesReader = $accounts->filter(fn ($rows) => $rows->contains(fn (ExternalAccount $a): bool => in_array((string) $a->getKey(), $boundHere, true)))->keys()->all();
            $connections = $connections->filter(
                fn (ProviderConnection $c): bool => in_array((string) $c->getKey(), $servesReader, true)
                    || (! in_array((string) $c->getKey(), $heldAway, true)
                        && ($c->client_workspace_id === null || in_array((string) $c->client_workspace_id, $clients, true))),
            )->values();
        }

        $selectedIds = $selected->pluck('external_account_id')->map(strval(...))->unique();

        /*
         * Campaigns per linked account, from the canonical rows — the same definition the project's card
         * counts with (`unlinked_at` null), and bounded by the same projects the links above are: this
         * project alone in its space, and never a project the reader cannot reach.
         */
        $campaignsByAccount = $selectedIds->isEmpty() ? [] : ExternalCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $selectedIds->values()->all())
            ->whereIn('project_id', $selected->pluck('project_id')->map(strval(...))->unique()->values()->all())
            ->whereNull('unlinked_at')
            ->groupBy('external_account_id')
            ->selectRaw('external_account_id, count(*) as total')
            ->pluck('total', 'external_account_id')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        /*
         * INTEG-STATUS-MODEL-001 — the five-status object's inputs, read once for the whole page.
         * `sync_enabled` per selected account, and the newest open / finished / failed run of each.
         */
        $syncEnabled = [];
        foreach ($selected as $b) {
            $syncEnabled[(string) $b->external_account_id] = ($syncEnabled[(string) $b->external_account_id] ?? false) || (bool) $b->sync_enabled;
        }
        $statusRuns = $this->status->runsByAccount($tenantId, $selectedIds->values()->all());

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

        $removal = app(ConnectionRemoval::class);

        $rows = $connections->map(function (ProviderConnection $connection) use ($accounts, $selectedIds, $runs, $projects, $projectByAccount, $authors, $removal, $syncEnabled, $statusRuns, $campaignsByAccount): array {
            $mine = $accounts->get((string) $connection->getKey(), collect());

            // «Discovered» excludes an account whose access the provider has withdrawn: it is not one
            // of the things this authorisation can still reach, and counting it inflates the sentence.
            // ACCOUNT-COUNTS-001 — the same definition as the wizard: reachable now, or already selected.
            $discovered = $mine->filter(fn (ExternalAccount $a): bool => $a->access_lost_at === null || $selectedIds->contains((string) $a->getKey()));
            $chosen = $discovered->filter(
                fn (ExternalAccount $a): bool => $selectedIds->contains((string) $a->getKey()),
            );
            $chosenIds = $chosen->map(fn (ExternalAccount $a): string => (string) $a->getKey())->values()->all();

            // One definition of the legacy word, shared with the wizard (INTEG-STATUS-MODEL-001).
            $connectionState = IntegrationStatusResolver::connectionTruth(ConnectionFacts::fromModel($connection));

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
                // INTEG-PROJECT-LINK-001 — the client this sign-in is filed under, so a project is only offered an authorisation it may use.
                'client_workspace_id' => $connection->client_workspace_id === null ? null : (string) $connection->client_workspace_id,
                /*
                 * INTEG-PROVIDER-IDENTITY-001 — the PROVIDER login the grant was made with, as the provider
                 * itself reported it at connect time. Null is «not recorded», never the workspace email.
                 */
                'provider_account' => $connection->provider_identity_label === null && $connection->provider_identity_email === null
                    ? null
                    : [
                        'label' => $connection->provider_identity_label,
                        'email' => $connection->provider_identity_email,
                        'fetched_at' => $connection->provider_identity_fetched_at?->toIso8601String(),
                    ],
                'removed_at' => $connection->removed_at?->toIso8601String(),
                // Started in the wizard and never finished: the row offers «إلغاء/إزالة» prominently.
                'abandoned' => $removal->isAbandoned($connection),
                /*
                 * The CampaignsHub USER who pressed connect — kept as the secondary «added by» line. It is
                 * the workspace-side identity and says nothing about which provider login was used.
                 */
                'authorised_by' => $author === null ? null : [
                    'name' => (string) $author->name,
                    'email' => (string) $author->email,
                ],
                'authorised_at' => $connection->created_at?->toIso8601String(),
                // The ONE project a link journey opened this connection for; null is a workspace-wide connection.
                'project_id' => $connection->project_id === null ? null : (string) $connection->project_id,
                // What the reader's projects read through this source, by name — two rows of one platform are told apart by it.
                'accounts' => $chosen->sortBy('name')->map(fn (ExternalAccount $a): array => [
                    'id' => (string) $a->getKey(),
                    'name' => (string) $a->name,
                    'organisation' => $a->parent_name,
                    'external_id' => (string) $a->external_id,
                    'currency' => $a->currency,
                    'campaigns' => $campaignsByAccount[(string) $a->getKey()] ?? 0,
                ])->values()->all(),
                'campaigns' => $chosen->sum(fn (ExternalAccount $a): int => $campaignsByAccount[(string) $a->getKey()] ?? 0),
                // The stored word, beside the two truths: a project's space tells «disconnected» from «revoked» by it.
                'status' => (string) $connection->status,
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
                /*
                 * INTEG-STATUS-MODEL-001 §1.13 — ADDITIVE. Five independent statuses; every field above is unchanged.
                 * `connection_state` / `sync_state` stay the legacy two words; `statuses` is the honest object.
                 */
                ...$this->statusFields($connection, $chosen, $discovered->count(), $syncEnabled, $statusRuns),
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

    /**
     * @param  Collection<int, ExternalAccount>  $chosen
     * @param  array<string, bool>  $syncEnabled
     * @param  Collection<int|string, Collection<int, MetricSyncRun>>  $runs
     * @return array{statuses: array<string, array<string,mixed>>, status_profile: array<string,mixed>}
     */
    private function statusFields(ProviderConnection $connection, Collection $chosen, int $discovered, array $syncEnabled, Collection $runs): array
    {
        $resolved = $this->status->forConnectionModel($connection, $chosen, $discovered, $syncEnabled, $runs);

        return ['statuses' => $resolved['statuses'], 'status_profile' => $resolved['profile']];
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
