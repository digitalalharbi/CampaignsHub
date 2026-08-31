<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Exceptions\AccountAssignedElsewhere;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Services\FirstSync;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Exceptions\PlanLimitReached;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * RUNTIME-100 §10 — the customer's whole selection, applied as one decision or not at all.
 *
 * ## Why this is not a loop over the single-bind endpoint
 *
 * The wizard used to call `POST …/bindings` once per ticked account. Each call was individually
 * correct — tenant checked, client workspace fenced, quota counted under a row lock — and the
 * SEQUENCE was not a decision anybody made. Somebody choosing ten accounts against a plan with room
 * for eight ended up with eight connected, two refused, and no way to tell which eight; from the
 * server's side nothing had failed, so there was nothing to undo.
 *
 * A selection is one act of intent. It succeeds whole or leaves the database exactly as it was.
 *
 * ## What «one transaction» actually has to cover
 *
 * Not just the inserts. Every fact the decision rests on is re-read INSIDE the transaction, after
 * the lock, because all of them can move between the wizard rendering and the customer pressing
 * confirm — an account can be assigned elsewhere, a connection revoked, a plan downgraded, another
 * operator can take the last slot. Validating against what the wizard saw and writing against what
 * the database now holds is the same class of bug as a check-then-insert, one screen further out.
 *
 * ## And the sync is queued after the commit, never inside it
 *
 * A job dispatched inside a transaction can reach a worker before the rows it depends on are
 * visible — the worker then finds no binding and correctly refuses to sync, which reads to everybody
 * as «confirmed, and nothing happened». `DB::afterCommit()` is what makes the first sync a
 * consequence of the write rather than a race with it.
 */
final class ConfirmAccountSelection
{
    public function __construct(
        private readonly AccountAssignment $assignment,
        private readonly SubscriptionService $subscriptions,
        private readonly FirstSync $firstSync,
    ) {}

    /**
     * @param  list<string>  $accountIds
     * @return list<ProjectIntegrationBinding>
     *
     * @throws ValidationException the selection names something that is not this connection's
     * @throws HttpException 403 — an account belonging to another client of this agency
     * @throws AccountAssignedElsewhere 409 — an account already feeding a different project
     * @throws PlanLimitReached 422 — the whole selection does not fit
     */
    public function execute(
        ProviderConnection $connection,
        Project $project,
        array $accountIds,
        string $purpose,
        ?string $primaryAccountId = null,
    ): array {
        $accountIds = array_values(array_unique($accountIds));

        if ($accountIds === []) {
            throw ValidationException::withMessages([
                'external_account_ids' => [__('validation.selection_empty')],
            ]);
        }

        return DB::transaction(function () use ($connection, $project, $accountIds, $purpose, $primaryAccountId) {
            /*
             * The quota owner, locked before anything is counted.
             *
             * Two operators confirming the last remaining slots would otherwise both read «room for
             * one» and both write. The partial unique index on active bindings is the backstop; this
             * is the mechanism.
             */
            DB::table('tenants')->where('id', $connection->tenant_id)->lockForUpdate()->first();

            $accounts = $this->accountsOfThisConnection($connection, $accountIds);

            foreach ($accounts as $account) {
                // The client-workspace fence. The tenant check above stops another COMPANY; this
                // stops another CLIENT of the same agency, which is the same tenant and a worse leak.
                if (! $this->assignment->mayAssign($account, $project)) {
                    throw new HttpException(
                        403,
                        'This account belongs to a different client workspace and cannot be assigned to this project.',
                    );
                }
            }

            $existing = ProjectIntegrationBinding::withoutGlobalScopes()
                ->whereIn('external_account_id', $accounts->map(fn (ExternalAccount $a): string => (string) $a->getKey())->all())
                ->where('is_active', true)
                ->get()
                ->keyBy('external_account_id');

            foreach ($accounts as $account) {
                $binding = $existing->get($account->getKey());

                if ($binding !== null && (string) $binding->project_id !== (string) $project->getKey()) {
                    throw new AccountAssignedElsewhere(
                        (string) $account->getKey(),
                        (string) $binding->project_id,
                    );
                }
            }

            // Only the ones that are not already connected here cost a slot — confirming the same
            // selection twice is the same decision, and must not be charged twice.
            $newIds = $accounts->reject(fn (ExternalAccount $a) => $existing->has($a->getKey()));

            $this->guardQuota((string) $connection->tenant_id, $newIds->count(), count($accountIds));

            /*
             * RUNTIME-100 §13 — the first sync is a consequence of the commit, not a race with it.
             *
             * Registered before the inserts so it is registered at all even if a later statement in
             * this closure throws; `afterCommit` only fires when the transaction actually commits,
             * so a rollback silently discards it. Dispatching inline instead would let a worker pick
             * the job up before the bindings are visible, find no assignment, correctly refuse — and
             * read to the customer as «confirmed, and nothing happened».
             */
            $queueFor = $newIds->map(fn (ExternalAccount $a): string => (string) $a->getKey())->values()->all();
            DB::afterCommit(fn () => $this->firstSync->start($queueFor));

            foreach ($newIds as $account) {
                $existing->put((string) $account->getKey(), ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)->create([
                    'tenant_id' => $connection->tenant_id,
                    'project_id' => $project->getKey(),
                    'client_workspace_id' => $project->client_workspace_id,
                    'external_account_id' => $account->getKey(),
                    'provider' => $account->provider,
                    'purpose' => $purpose,
                    'is_primary' => $primaryAccountId !== null
                        && (string) $account->getKey() === $primaryAccountId,
                    'is_active' => true,
                    'campaign_management_enabled' => $purpose === 'advertising',
                    'tracking_enabled' => in_array($purpose, ['tracking', 'conversion_api'], true),
                ]));
            }

            /** @var list<ProjectIntegrationBinding> $bindings */
            $bindings = [];
            foreach ($accounts as $account) {
                $binding = $existing->get((string) $account->getKey());
                if ($binding instanceof ProjectIntegrationBinding) {
                    $bindings[] = $binding;
                }
            }

            return $bindings;
        });
    }

    /**
     * The chosen accounts, proved to be this connection's — read inside the transaction.
     *
     * A tampered or stale id is a validation failure and not a 404: the customer is being told their
     * selection is no longer one this connection can honour, which is also the honest answer when the
     * id belongs to somebody else entirely.
     *
     * @param  list<string>  $accountIds
     * @return EloquentCollection<int, ExternalAccount>
     */
    private function accountsOfThisConnection(ProviderConnection $connection, array $accountIds): EloquentCollection
    {
        if (! in_array($connection->status, ['connected'], true)) {
            throw ValidationException::withMessages([
                'connection_id' => [__('validation.connection_not_authorized')],
            ]);
        }

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->whereIn('id', $accountIds)
            ->where('provider_connection_id', $connection->getKey())
            ->where('tenant_id', $connection->tenant_id)
            /*
             * COMMERCE-PROJECT-001 — a store is assigned the same way an ad account is.
             *
             * Commerce had no assignment concept at all: `StoreSyncer` filed a store's orders into
             * the tenant's OLDEST project and the next sweep kept them there. Widening this to
             * `store` is what gives a merchant the same explicit decision, through the same
             * transaction, the same quota lock and the same client-workspace fence.
             */
            ->whereIn('account_type', ['ad_account', 'store'])
            ->get();

        if ($accounts->count() !== count($accountIds)) {
            throw ValidationException::withMessages([
                'external_account_ids' => [__('validation.selection_stale')],
            ]);
        }

        return $accounts;
    }

    /** The cap, counted under the lock this method is called inside. */
    private function guardQuota(string $tenantId, int $adding, int $requested): void
    {
        if ($adding === 0) {
            return;
        }

        $tenant = Tenant::withoutGlobalScopes()->findOrFail($tenantId);
        $limit = $this->subscriptions->effectiveLimit($tenant, 'ad_accounts');

        if ($limit === null) {
            return;
        }

        $used = $this->subscriptions->usage($tenant, 'ad_accounts');

        if ($used + $adding <= $limit) {
            return;
        }

        throw new PlanLimitReached(
            'ad_accounts',
            $used,
            $limit,
            __('billing.ad_accounts_selection_exceeds_plan', [
                'requested' => $requested,
                'remaining' => max(0, $limit - $used),
                'limit' => $limit,
            ]),
            requested: $requested,
        );
    }
}
