<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Catalogue\ProviderAuth;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Status\AccountFacts;
use App\Domains\Integrations\Status\ConnectionFacts;
use App\Domains\Integrations\Status\StatusText;
use App\Domains\Integrations\Support\InsightsAuthorisation;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Integrations\Support\NextScheduledSync;
use App\Domains\Metrics\Models\MetricSyncRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * INTEG-STATUS-MODEL-001 (contract §1.13) — ONE honest status object: five statuses that never imply one another.
 *
 * ## The five, and why they are five
 *
 * | status          | answers                                    | it is NOT                                   |
 * |-----------------|--------------------------------------------|---------------------------------------------|
 * | `authorisation` | may we call the provider as this grant     | proof that anything was linked or synced    |
 * | `binding`       | does a project own this resource           | proof that the grant still works            |
 * | `sync`          | what did the last attempts do              | proof that the data is complete or current  |
 * | `freshness`     | how old is what is on screen, and is it all there | proof that the grant works           |
 * | `error`         | what went wrong, and what a person does    | a restatement of the other four             |
 *
 * Discovered is not linked. Connected is not synced. A call that succeeded is not complete data: a run that
 * placed some rows, withheld the money for want of a currency, or fetched nothing until a project is chosen
 * is `partial` / `withheld`, never a quiet success. Withheld values are never a trusted zero, and there is
 * NO combined «healthy» field in this object, on purpose: a field that could say «fine» is the field that
 * would be read instead of the five.
 *
 * ## Pure core, thin loaders
 *
 * {@see self::resolveAccount()} and {@see self::resolveConnection()} take plain facts ({@see ConnectionFacts},
 * {@see AccountFacts}) and a clock, so the whole matrix of «facts → five statuses» is provable without a
 * database. The `for*Model` loaders only translate rows into facts. Nothing is stored: a stored status is a
 * second opinion that drifts from the stamps it was computed from.
 *
 * ## Provider differences are stated, not hidden
 *
 * An API-key provider has no expiry and no refresh, so its authorisation can only be `connected` or
 * `api_key_invalid` and a token date is never consulted. A provider without a push channel reports
 * freshness as `polling`. {@see self::profile()} says which applies.
 *
 * @phpstan-type Raw array{state:string, detail:?string, action:?string, since:?CarbonInterface, as_of:?CarbonInterface, extra:array<string,mixed>}
 */
final class IntegrationStatusResolver
{
    /** A token with no refresh path is «expiring» this many days before it lapses. */
    public const EXPIRING_WITHIN_DAYS = 7;

    /** A refreshable token may sit past its date this long before we stop believing a refresh is coming. */
    public const REFRESH_GRACE_HOURS = 24;

    /** Categories after which the money or the whole payload is withheld rather than shown. */
    private const WITHHELD = ['currency_unknown', 'insights_not_authorised', 'awaiting_assignment'];

    /** Categories that mean «somebody must act» but nothing is broken. */
    private const ATTENTION = ['awaiting_assignment', 'currency_unknown', 'unmapped_rows', 'awaiting_credentials', 'connection_removed'];

    private const ERROR_ACTIONS = [
        'connection_removed' => 'reconnect',
        'connection_revoked' => 'reauthorize',
        'api_key_invalid' => 'replace_api_key',
        'refresh_failed' => 'reauthorize',
        'provider_rejected' => 'reauthorize',
        'scope_missing' => 'reauthorize',
        'token_expired' => 'reauthorize',
        'refresh_overdue' => 'reauthorize',
        'all_selected_accounts_lost_access' => 'reauthorize',
        'insights_not_authorised' => 'grant_read_scope',
        'access_lost' => 'restore_access',
        'awaiting_assignment' => 'assign_project',
        'currency_unknown' => 'set_currency',
        'unmapped_rows' => 'review_unmapped',
        'awaiting_credentials' => 'add_platform_credentials',
        'provider_error' => 'retry_sync',
        'abandoned_run' => 'retry_sync',
    ];

    /* -------------------------------------------------------------------------------------------------
     | Legacy single word — kept so the existing `connection_state` field is computed in ONE place.
     | ------------------------------------------------------------------------------------------------- */

    /**
     * The pre-existing `connection_state` word (IntegrationTruth), for the fields that already ship.
     *
     * The hub and the wizard each carried their own copy of this `match`. It lives here now so they cannot
     * drift, and it is deliberately NOT the same function as {@see self::resolveConnection()}: the shipped
     * field has never looked at token dates, and changing what an existing field means is not additive.
     * The richer truth is in `statuses.authorisation`.
     */
    public static function connectionTruth(ConnectionFacts $c, bool $authorisationLost = false): string
    {
        return match (true) {
            in_array($c->status, ['revoked', 'disconnected'], true) => IntegrationTruth::REVOKED,
            $c->status === 'error',
            $c->insightsDeniedAt !== null,
            $authorisationLost,
            ! InsightsAuthorisation::grantedBy($c->provider, $c->scopes) => IntegrationTruth::REAUTH_REQUIRED,
            default => IntegrationTruth::CONNECTED,
        };
    }

    /* -------------------------------------------------------------------------------------------------
     | Public pure API
     | ------------------------------------------------------------------------------------------------- */

    /**
     * The five statuses of ONE resource (an ad account, a store, a property) and its link.
     *
     * @return array{statuses: array<string, array<string,mixed>>, profile: array<string,mixed>}
     */
    public function resolveAccount(ConnectionFacts $c, AccountFacts $a, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $raw = $this->accountRaw($c, $a, $now);

        return [
            'statuses' => $this->render($raw),
            'profile' => $this->profile($c->provider),
        ];
    }

    /**
     * The five statuses of one AUTHORISATION over the accounts somebody selected.
     *
     * Aggregation is «worst honest answer, with the counts beside it»: a connection is only as fresh as its
     * stalest selected account, and one account that failed out of ten is `partial`, not `failed` and not
     * `succeeded`. The counts are returned so a surface can say «1 of 10».
     *
     * @param  list<AccountFacts>  $chosen  accounts with an ACTIVE binding
     * @param  int  $discovered  accounts this authorisation can reach (chosen included)
     * @return array{statuses: array<string, array<string,mixed>>, profile: array<string,mixed>}
     */
    public function resolveConnection(ConnectionFacts $c, array $chosen, int $discovered, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $conn = $this->connectionAuthorisation($c, $now);
        $each = array_map(fn (AccountFacts $a): array => $this->accountRaw($c, $a, $now), $chosen);
        $total = count($chosen);

        $auth = $conn;
        if (in_array($conn['state'], ['connected', 'expiring'], true) && $total > 0
            && count(array_filter($chosen, static fn (AccountFacts $a): bool => $a->accessLostAt !== null)) === $total) {
            // The wizard's rule, kept: every selected account lost access, so the grant is gone whatever the row says.
            $auth = $this->raw('reauth_required', 'all_selected_accounts_lost_access', 'reauthorize',
                since: collect($chosen)->map(static fn (AccountFacts $a) => $a->accessLostAt)->max());
        } elseif ($total > 0) {
            $lost = count(array_filter($chosen, static fn (AccountFacts $a): bool => $a->accessLostAt !== null));
            $auth['extra']['accounts_without_access'] = $lost;
        }

        $binding = $this->connectionBinding($auth['state'], $total, $discovered);
        $sync = $this->aggregateSync($each, $total);
        $freshness = $this->aggregateFreshness($each, $total);
        $error = $this->connectionError($c, $auth, $each);

        return [
            'statuses' => $this->render([
                'authorisation' => $auth, 'binding' => $binding, 'sync' => $sync, 'freshness' => $freshness, 'error' => $error,
            ]),
            'profile' => $this->profile($c->provider),
        ];
    }

    /** @return array<string,mixed> */
    public function profile(string $provider): array
    {
        if (! ProviderCatalogue::has($provider)) {
            return ['auth' => null, 'refresh' => null, 'token_expiry' => null, 'webhooks' => null, 'freshness_mode' => 'polling'];
        }

        $def = ProviderCatalogue::get($provider);
        $apiKey = $def->auth === ProviderAuth::ApiKey;

        return [
            'kind' => $def->kind->value,
            'auth' => $def->auth->value,
            'refresh' => ! $apiKey && $def->supportsRefresh,
            'token_expiry' => ! $apiKey,
            'webhooks' => $def->webhooks->value,
            'freshness_mode' => $def->webhooks->isAuthoritative() ? 'webhook_and_polling' : 'polling',
        ];
    }

    /* -------------------------------------------------------------------------------------------------
     | Model loaders — translate rows into facts, nothing else.
     | ------------------------------------------------------------------------------------------------- */

    /**
     * The newest runs per account, as three DISTINCT ON reads: the open one, the last finished one, the last failed one.
     *
     * Not «every run in a window»: a 30-minute sweep leaves thousands of rows per page of accounts, and the
     * status needs exactly these three.
     *
     * @param  list<string>  $accountIds
     * @return Collection<int|string, Collection<int, MetricSyncRun>>
     */
    public function runsByAccount(string $tenantId, array $accountIds): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $base = fn () => MetricSyncRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $accountIds)
            ->selectRaw('DISTINCT ON (external_account_id) external_account_id, status, started_at, finished_at')
            ->orderBy('external_account_id')
            ->orderByDesc('started_at');

        $rows = $base()->where('status', 'running')->get()
            ->concat($base()->where('status', '!=', 'running')->get())
            ->concat($base()->where('status', 'failed')->get());

        return collect($rows->all())->groupBy(fn ($r): string => (string) $r->external_account_id);
    }

    /**
     * @param  Collection<int, ExternalAccount>  $chosen  accounts with an ACTIVE binding
     * @param  array<string, bool>  $syncEnabled  external_account_id => binding.sync_enabled
     * @param  Collection<int|string, Collection<int, MetricSyncRun>>  $runs  from {@see self::runsByAccount()}
     * @return array{statuses: array<string, array<string,mixed>>, profile: array<string,mixed>}
     */
    public function forConnectionModel(ProviderConnection $c, Collection $chosen, int $discovered, array $syncEnabled, Collection $runs, ?Carbon $now = null): array
    {
        $facts = $chosen->map(fn (ExternalAccount $a): AccountFacts => AccountFacts::fromModel(
            $a, AccountFacts::BINDING_ACTIVE, $syncEnabled[(string) $a->getKey()] ?? true, $runs->get((string) $a->getKey(), []),
        ))->values()->all();

        return $this->resolveConnection(ConnectionFacts::fromModel($c), $facts, $discovered, $now);
    }

    /**
     * @param  'none'|'active'|'inactive'  $binding
     * @param  Collection<int|string, Collection<int, MetricSyncRun>>  $runs
     * @return array{statuses: array<string, array<string,mixed>>, profile: array<string,mixed>}
     */
    public function forAccountModel(ExternalAccount $a, ?ProviderConnection $c, string $binding, bool $syncEnabled, Collection $runs, ?Carbon $now = null): array
    {
        $conn = $c === null ? ConnectionFacts::missing((string) $a->provider) : ConnectionFacts::fromModel($c);

        return $this->resolveAccount($conn, AccountFacts::fromModel($a, $binding, $syncEnabled, $runs->get((string) $a->getKey(), [])), $now);
    }

    /**
     * Every connection of ONE tenant at once — the panel list's batch path.
     *
     * A connection that belongs to another tenant is skipped, not resolved: the tenant id is checked here as
     * well as in the caller's query, so a widened query upstream cannot leak a status.
     *
     * @param  Collection<int, ProviderConnection>  $connections
     * @return array<string, array{statuses: array<string, array<string,mixed>>, profile: array<string,mixed>}> keyed by connection id
     */
    public function forConnections(Collection $connections, string $tenantId, ?Carbon $now = null): array
    {
        $own = $connections->filter(static fn (ProviderConnection $c): bool => (string) $c->tenant_id === $tenantId)->values();
        if ($own->isEmpty()) {
            return [];
        }

        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('is_active', true)->whereNotNull('project_id')
            ->get(['external_account_id', 'sync_enabled']);
        $syncEnabled = $bindings->mapWithKeys(static fn ($b): array => [(string) $b->external_account_id => (bool) $b->sync_enabled])->all();

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('account_type', 'ad_account')
            ->whereIn('provider_connection_id', $own->pluck('id')->all())
            ->get()->groupBy(fn (ExternalAccount $a): string => (string) $a->provider_connection_id);

        $chosenIds = $accounts->flatten(1)->filter(static fn (ExternalAccount $a): bool => isset($syncEnabled[(string) $a->getKey()]))
            ->map(static fn (ExternalAccount $a): string => (string) $a->getKey())->values()->all();
        $runs = $this->runsByAccount($tenantId, $chosenIds);

        $out = [];
        foreach ($own as $c) {
            $mine = $accounts->get((string) $c->getKey(), collect());
            $chosen = $mine->filter(static fn (ExternalAccount $a): bool => isset($syncEnabled[(string) $a->getKey()]));
            $discovered = $mine->filter(static fn (ExternalAccount $a): bool => $a->access_lost_at === null || isset($syncEnabled[(string) $a->getKey()]));
            $out[(string) $c->getKey()] = $this->forConnectionModel($c, $chosen, $discovered->count(), $syncEnabled, $runs, $now);
        }

        return $out;
    }

    /* -------------------------------------------------------------------------------------------------
     | Authorisation
     | ------------------------------------------------------------------------------------------------- */

    /** @return Raw */
    private function connectionAuthorisation(ConnectionFacts $c, Carbon $now): array
    {
        $def = ProviderCatalogue::has($c->provider) ? ProviderCatalogue::get($c->provider) : null;
        $apiKey = $def?->auth === ProviderAuth::ApiKey;
        $refresh = ! $apiKey && $def !== null && $def->supportsRefresh;

        if (! $c->exists || $c->status === 'disconnected') {
            return $this->raw('removed', null, 'reconnect');
        }
        if ($c->status === 'revoked') {
            return $this->raw('revoked', null, $apiKey ? 'replace_api_key' : 'reauthorize');
        }
        if ($c->status === 'error') {
            // An API key has nothing to refresh, so an error on one is the key itself.
            return $apiKey
                ? $this->raw('api_key_invalid', null, 'replace_api_key')
                : $this->raw('reauth_required', $refresh ? 'refresh_failed' : 'provider_rejected', 'reauthorize');
        }
        if ($apiKey) {
            // No expiry, no refresh, no scopes to request: nothing here is consulted, and nothing is invented.
            return $this->raw('connected', 'api_key_has_no_expiry', null);
        }
        if ($c->insightsDeniedAt !== null) {
            return $this->raw('reauth_required', 'insights_not_authorised', 'grant_read_scope', since: $c->insightsDeniedAt);
        }
        if (! InsightsAuthorisation::grantedBy($c->provider, $c->scopes)) {
            return $this->raw('reauth_required', 'scope_missing', 'reauthorize');
        }

        $expires = $c->tokenExpiresAt;
        if ($expires !== null) {
            if ($expires->lte($now)) {
                if (! $refresh) {
                    return $this->raw('reauth_required', 'token_expired', 'reauthorize', extra: ['expires_at' => $expires->toIso8601String()]);
                }
                if ($expires->lte($now->copy()->subHours(self::REFRESH_GRACE_HOURS))) {
                    return $this->raw('reauth_required', 'refresh_overdue', 'reauthorize', extra: ['expires_at' => $expires->toIso8601String()]);
                }

                return $this->raw('expiring', 'refresh_pending', 'reauthorize_before_expiry', extra: ['expires_at' => $expires->toIso8601String()]);
            }
            if (! $refresh && $expires->lte($now->copy()->addDays(self::EXPIRING_WITHIN_DAYS))) {
                return $this->raw('expiring', 'expires_soon', 'reauthorize_before_expiry', extra: ['expires_at' => $expires->toIso8601String()]);
            }

            return $this->raw('connected', null, null, extra: ['expires_at' => $expires->toIso8601String()]);
        }

        return $this->raw('connected', null, null);
    }

    /**
     * Resource-level overlay: the connection is fine and THIS account is not.
     *
     * Only applied when the connection itself is usable — a revoked connection's word is the more actionable
     * one and the account cannot be repaired separately from it.
     *
     * @param  Raw  $conn
     * @return Raw
     */
    private function accountAuthorisation(array $conn, AccountFacts $a): array
    {
        if (! in_array($conn['state'], ['connected', 'expiring'], true)) {
            return $conn;
        }
        if ($a->accessLostAt !== null) {
            return $this->raw('access_lost', null, 'restore_access', since: $a->accessLostAt, extra: $conn['extra']);
        }
        if ($a->lastErrorCategory === 'insights_not_authorised') {
            return $this->raw('scope_not_granted', 'insights_not_authorised', 'grant_read_scope', extra: $conn['extra']);
        }

        return $conn;
    }

    /* -------------------------------------------------------------------------------------------------
     | Binding
     | ------------------------------------------------------------------------------------------------- */

    /** @return Raw */
    private function accountBinding(AccountFacts $a, string $authState): array
    {
        return match ($a->binding) {
            AccountFacts::BINDING_ACTIVE => $this->raw('bound', null, null),
            AccountFacts::BINDING_INACTIVE => in_array($authState, ['revoked', 'removed'], true)
                ? $this->raw('detached', 'connection_'.$authState, 'reconnect')
                : $this->raw('deselected', null, 'assign_project'),
            default => $this->raw('discovered_unbound', null, 'assign_project'),
        };
    }

    /** @return Raw */
    private function connectionBinding(string $authState, int $chosen, int $discovered): array
    {
        $extra = ['selected' => $chosen, 'discovered' => $discovered];

        return match (true) {
            $chosen > 0 => $this->raw('bound', null, null, extra: $extra),
            in_array($authState, ['revoked', 'removed'], true) => $this->raw('detached', 'connection_'.$authState, 'reconnect', extra: $extra),
            $discovered > 0 => $this->raw('discovered_unbound', null, 'choose_accounts', extra: $extra),
            default => $this->raw('none_discovered', null, null, extra: $extra),
        };
    }

    /* -------------------------------------------------------------------------------------------------
     | Per-account raw statuses
     | ------------------------------------------------------------------------------------------------- */

    /** @return array{authorisation:Raw, binding:Raw, sync:Raw, freshness:Raw, error:Raw} */
    private function accountRaw(ConnectionFacts $c, AccountFacts $a, Carbon $now): array
    {
        $connAuth = $this->connectionAuthorisation($c, $now);
        $auth = $this->accountAuthorisation($connAuth, $a);
        $binding = $this->accountBinding($a, $auth['state']);

        return [
            'authorisation' => $auth,
            'binding' => $binding,
            'sync' => $this->accountSync($a, $auth['state'], $binding['state'], $now),
            'freshness' => $this->accountFreshness($c, $a, $binding['state'], $now),
            'error' => $this->accountError($c, $a, $connAuth, $auth, $now),
        ];
    }

    /** Whether an open `running` row is still believable — the same sixty minutes the sweeper uses. */
    private function openRunIsLive(AccountFacts $a, Carbon $now): bool
    {
        return $a->openRunStartedAt !== null
            && $a->openRunStartedAt->greaterThanOrEqualTo($now->copy()->subMinutes(IntegrationTruth::ABANDONED_AFTER_MINUTES));
    }

    /** @return Raw */
    private function accountSync(AccountFacts $a, string $authState, string $bindingState, Carbon $now): array
    {
        $live = $this->openRunIsLive($a, $now);
        $abandoned = $a->openRunStartedAt !== null && ! $live;
        $extra = [
            'last_success_at' => $a->lastSyncedAt?->toIso8601String(),
            'last_attempt_at' => $a->lastAttemptAt?->toIso8601String(),
            'last_failure_at' => $a->lastFailureAt?->toIso8601String(),
            'last_error_category' => $a->lastErrorCategory,
            'next_at' => null,
        ];

        if ($bindingState !== 'bound') {
            // A run that started before the link was cut may still be open. It is reported as running AND
            // the binding as detached/deselected: neither word hides the other.
            if ($live) {
                return $this->raw('running', $bindingState === 'discovered_unbound' ? 'running_while_unlinked' : 'running_after_detach', null, since: $a->openRunStartedAt, extra: $extra);
            }

            return $bindingState === 'discovered_unbound'
                ? $this->raw('never_started', 'not_linked', 'assign_project', extra: $extra)
                : $this->raw('paused', 'binding_inactive', null, extra: $extra);
        }

        if ($live) {
            return $this->raw('running', null, 'wait', since: $a->openRunStartedAt, extra: $extra);
        }
        if (! $a->syncEnabled) {
            return $this->raw('paused', 'sync_disabled', 'resume_sync', extra: $extra);
        }

        $eligible = in_array($authState, ['connected', 'expiring'], true);
        $extra['next_at'] = NextScheduledSync::at($eligible, $now)?->toIso8601String();

        $outcome = $this->outcome($a);

        if ($outcome === 'failed') {
            $extra['last_failure_at'] = ($a->lastFailureAt ?? $a->lastAttemptAt)?->toIso8601String();

            return $this->raw('failed', $a->lastErrorCategory ?? 'provider_error', self::ERROR_ACTIONS[$a->lastErrorCategory ?? 'provider_error'] ?? 'retry_sync',
                since: $a->lastFailureAt ?? $a->lastAttemptAt, extra: $extra);
        }
        if ($outcome === 'partial') {
            $detail = $a->lastErrorCategory ?? 'unmapped_rows';

            return $this->raw('partial', $detail, self::ERROR_ACTIONS[$detail] ?? null, since: $a->lastSyncedAt ?? $a->latestRunAt, extra: $extra);
        }
        if ($outcome === 'paused') {
            return $this->raw('paused', 'awaiting_assignment', 'assign_project', extra: $extra);
        }
        if ($outcome === 'succeeded') {
            return $this->raw('succeeded', $a->latestRunStatus === 'no_data' ? 'no_rows_returned' : null, null, since: $a->lastSyncedAt, extra: $extra);
        }
        if ($abandoned) {
            $extra['last_failure_at'] = $a->openRunStartedAt?->toIso8601String();

            return $this->raw('failed', 'abandoned_run', 'retry_sync', since: $a->openRunStartedAt, extra: $extra);
        }

        return $this->raw('never_started', null, 'run_first_sync', extra: $extra);
    }

    /**
     * The sync outcome in the card's vocabulary. A recorded category outranks a run status because the category
     * is stamped by the same checkpoint and is the more specific of the two (`currency_unknown` rides on a run
     * that otherwise looks like a success).
     *
     * @return 'failed'|'partial'|'paused'|'succeeded'|'none'
     */
    private function outcome(AccountFacts $a): string
    {
        $cat = $a->lastErrorCategory;
        if ($cat !== null) {
            return match ($cat) {
                'awaiting_assignment' => 'paused',
                'currency_unknown', 'unmapped_rows' => 'partial',
                default => 'failed',
            };
        }

        return match ($a->latestRunStatus) {
            'failed' => 'failed',
            'awaiting_assignment' => 'paused',
            'partial_mapping' => 'partial',
            'success', 'no_data' => 'succeeded',
            default => $a->lastSyncedAt !== null ? 'succeeded' : 'none',
        };
    }

    /** @return Raw */
    private function accountFreshness(ConnectionFacts $c, AccountFacts $a, string $bindingState, Carbon $now): array
    {
        $hours = max(1, (int) config('integrations.health.stale_after_hours', 48));
        $extra = ['mode' => $this->profile($c->provider)['freshness_mode'], 'stale_after_hours' => $hours];

        if ($bindingState === 'discovered_unbound') {
            return $this->raw('unknown', 'not_linked', null, extra: $extra);
        }

        $cat = $a->lastErrorCategory ?? ($a->latestRunStatus === 'awaiting_assignment' ? 'awaiting_assignment' : null);
        $asOf = $a->lastSyncedAt;
        $inactive = $bindingState !== 'bound' ? 'binding_inactive' : null;

        if ($cat !== null && in_array($cat, self::WITHHELD, true)) {
            // The values exist or would — and are not shown. Never a zero, never «fresh».
            return $this->raw('withheld', $cat, self::ERROR_ACTIONS[$cat], as_of: $asOf, extra: $extra);
        }
        if ($cat === 'unmapped_rows' || $a->latestRunStatus === 'partial_mapping') {
            return $this->raw('partial', 'unmapped_rows', 'review_unmapped', as_of: $asOf, extra: $extra);
        }
        if ($asOf === null) {
            return $this->raw('unknown', 'never_synced', $bindingState === 'bound' ? 'run_first_sync' : null, extra: $extra);
        }
        if ($asOf->lt($now->copy()->subHours($hours))) {
            return $this->raw('stale', $inactive ?? 'older_than_threshold', $bindingState === 'bound' ? 'retry_sync' : null, as_of: $asOf, extra: $extra);
        }

        return $this->raw('fresh', $inactive ?? ($a->latestRunStatus === 'no_data' ? 'no_rows_returned' : null), null, as_of: $asOf, extra: $extra);
    }

    /**
     * @param  Raw  $connAuth
     * @param  Raw  $auth
     * @return Raw
     */
    private function accountError(ConnectionFacts $c, AccountFacts $a, array $connAuth, array $auth, Carbon $now): array
    {
        $cat = $this->connectionErrorCategory($connAuth);
        $since = $connAuth['since'];

        if ($cat === null && $auth['state'] === 'access_lost') {
            $cat = 'access_lost';
            $since = $a->accessLostAt;
        }
        if ($cat === null && $a->lastErrorCategory !== null) {
            $cat = $a->lastErrorCategory;
            $since = $a->lastAttemptAt;
        }
        if ($cat === null && $a->latestRunStatus === 'failed') {
            $cat = 'provider_error';
            $since = $a->lastFailureAt;
        }
        if ($cat === null && $a->openRunStartedAt !== null && ! $this->openRunIsLive($a, $now) && $a->lastSyncedAt === null) {
            $cat = 'abandoned_run';
            $since = $a->openRunStartedAt;
        }
        if ($cat === null && $c->discoveryBlockedReason !== null) {
            return $this->errorRaw($c->discoveryBlockedReason, 'attention', 'fix_provider_prerequisite', null);
        }

        return $cat === null ? $this->raw('none', null, null, extra: ['category' => null]) : $this->errorRaw($cat, null, self::ERROR_ACTIONS[$cat] ?? 'retry_sync', $since);
    }

    /** @param  Raw  $conn */
    private function connectionErrorCategory(array $conn): ?string
    {
        return match ($conn['state']) {
            'removed' => 'connection_removed',
            'revoked' => 'connection_revoked',
            'api_key_invalid' => 'api_key_invalid',
            'reauth_required' => $conn['detail'],
            default => null,
        };
    }

    /** @return Raw */
    private function errorRaw(string $category, ?string $class, ?string $action, ?CarbonInterface $since): array
    {
        $state = $class ?? (in_array($category, self::ATTENTION, true) ? 'attention' : 'error');

        return $this->raw($state, $category, $action, since: $since, extra: ['category' => $category]);
    }

    /* -------------------------------------------------------------------------------------------------
     | Connection-level aggregation
     | ------------------------------------------------------------------------------------------------- */

    /**
     * @param  list<array{authorisation:Raw, binding:Raw, sync:Raw, freshness:Raw, error:Raw}>  $each
     * @return Raw
     */
    private function aggregateSync(array $each, int $total): array
    {
        if ($total === 0) {
            return $this->raw('never_started', 'nothing_selected', 'choose_accounts', extra: ['accounts' => 0]);
        }

        $counts = array_count_values(array_map(static fn (array $r): string => $r['sync']['state'], $each));
        $n = static fn (string $s): int => $counts[$s] ?? 0;
        $success = collect($each)->map(static fn (array $r) => $r['sync']['extra']['last_success_at'] ?? null)->filter()->max();
        $failure = collect($each)->map(static fn (array $r) => $r['sync']['extra']['last_failure_at'] ?? null)->filter()->max();
        $worst = collect($each)->first(static fn (array $r): bool => $r['sync']['state'] === 'failed');
        $extra = [
            'counts' => $counts,
            'accounts' => $total,
            'last_success_at' => $success,
            'last_failure_at' => $failure,
            'last_error_category' => $worst['sync']['extra']['last_error_category'] ?? collect($each)->map(static fn (array $r) => $r['sync']['extra']['last_error_category'] ?? null)->filter()->first(),
            'next_at' => collect($each)->map(static fn (array $r) => $r['sync']['extra']['next_at'] ?? null)->filter()->min(),
        ];

        [$state, $detail, $action] = match (true) {
            $n('running') > 0 => ['running', null, 'wait'],
            $n('failed') === $total => ['failed', 'all_accounts_failed', 'retry_sync'],
            $n('failed') > 0 => ['partial', 'some_accounts_failed', 'retry_sync'],
            $n('partial') > 0 => ['partial', 'some_accounts_partial', null],
            $n('paused') === $total => ['paused', 'all_accounts_paused', null],
            $n('paused') > 0 => ['partial', 'some_accounts_paused', null],
            $n('never_started') === $total => ['never_started', null, 'run_first_sync'],
            $n('never_started') > 0 => ['partial', 'some_accounts_not_started', 'run_first_sync'],
            default => ['succeeded', null, null],
        };

        return $this->raw($state, $detail, $action, extra: $extra);
    }

    /**
     * @param  list<array{authorisation:Raw, binding:Raw, sync:Raw, freshness:Raw, error:Raw}>  $each
     * @return Raw
     */
    private function aggregateFreshness(array $each, int $total): array
    {
        if ($total === 0) {
            return $this->raw('unknown', 'nothing_selected', null, extra: ['accounts' => 0]);
        }

        $order = ['withheld', 'partial', 'stale', 'unknown', 'fresh'];
        $counts = array_count_values(array_map(static fn (array $r): string => $r['freshness']['state'], $each));
        $worst = collect($order)->first(static fn (string $s): bool => isset($counts[$s]));
        $pick = collect($each)->first(static fn (array $r): bool => $r['freshness']['state'] === $worst)['freshness'];
        // The connection is only as current as its stalest selected account.
        $asOf = collect($each)->map(static fn (array $r) => $r['freshness']['as_of'])->filter()->min();

        return $this->raw($worst, $pick['detail'], $pick['action'], as_of: $asOf, extra: ['counts' => $counts, 'accounts' => $total] + $pick['extra']);
    }

    /**
     * @param  Raw  $auth
     * @param  list<array{authorisation:Raw, binding:Raw, sync:Raw, freshness:Raw, error:Raw}>  $each
     * @return Raw
     */
    private function connectionError(ConnectionFacts $c, array $auth, array $each): array
    {
        $cat = $this->connectionErrorCategory($auth);
        if ($cat !== null) {
            return $this->errorRaw($cat, null, self::ERROR_ACTIONS[$cat] ?? 'reauthorize', $auth['since']);
        }

        $rank = array_flip(['access_lost', 'insights_not_authorised', 'awaiting_credentials', 'provider_error', 'abandoned_run', 'currency_unknown', 'unmapped_rows', 'awaiting_assignment']);
        $found = collect($each)->map(static fn (array $r): array => $r['error'])->filter(static fn (array $e): bool => $e['state'] !== 'none');
        if ($found->isNotEmpty()) {
            $top = $found->sortBy(static fn (array $e): int => $rank[$e['detail']] ?? 50)->first();
            $raw = $this->raw($top['state'], $top['detail'], $top['action'], since: $top['since'], extra: $top['extra']);
            $raw['extra']['affected_accounts'] = $found->count();
            $raw['extra']['accounts'] = count($each);

            return $raw;
        }

        if ($c->discoveryBlockedReason !== null) {
            return $this->errorRaw($c->discoveryBlockedReason, 'attention', 'fix_provider_prerequisite', null);
        }

        return $this->raw('none', null, null, extra: ['category' => null]);
    }

    /* -------------------------------------------------------------------------------------------------
     | Rendering
     | ------------------------------------------------------------------------------------------------- */

    /**
     * @param  array{authorisation:Raw, binding:Raw, sync:Raw, freshness:Raw, error:Raw}  $raws
     * @return array<string, array<string,mixed>>
     */
    private function render(array $raws): array
    {
        $out = [];
        foreach (['authorisation', 'binding', 'sync', 'freshness', 'error'] as $dim) {
            $r = $raws[$dim];
            $extra = $r['extra'];
            foreach ($extra as $k => $v) {
                if ($v instanceof CarbonInterface) {
                    $extra[$k] = $v->toIso8601String();
                }
            }
            $out[$dim] = [
                'state' => $r['state'],
                ...StatusText::label($dim, $r['state']),
                'since' => $r['since']?->toIso8601String(),
                'as_of' => $r['as_of']?->toIso8601String(),
                'detail' => $r['detail'],
                'action' => StatusText::action($r['action']),
                ...$extra,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return Raw
     */
    private function raw(string $state, ?string $detail, ?string $action, ?CarbonInterface $since = null, ?CarbonInterface $as_of = null, array $extra = []): array
    {
        return ['state' => $state, 'detail' => $detail, 'action' => $action, 'since' => $since, 'as_of' => $as_of, 'extra' => $extra];
    }
}
