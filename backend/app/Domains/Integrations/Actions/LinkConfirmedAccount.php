<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Exceptions\AccountAssignedElsewhere;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\FirstSync;
use App\Domains\Integrations\Services\LinkDirectory;
use App\Domains\Integrations\Services\SharedAccounts;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Exceptions\PlanLimitReached;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INTEG-ON-DEMAND-DISCOVERY-001 (Owner 2026-11-06) — the end of a project's link journey: ONE account,
 * stored because it was confirmed, bound to the project the journey started from.
 *
 * `$listed` is the provider's own description of the account ({@see LinkDirectory::find}),
 * never something the browser sent: the browser names an id, the server looks it up in what the provider
 * listed for this sign-in and organisation.
 *
 * ## Two cases, one rule: an account the project already reads keeps its row
 *
 *  - **A new link.** The row is written and bound in one transaction through {@see ConfirmAccountSelection}
 *    — the same quota lock, seat claim, one-project-per-account refusal and first sync as every other
 *    selection. If the binding is refused, the row is not left behind: nothing unconfirmed is stored.
 *
 *  - **«إعادة الربط» of an account this project already reads through an older, workspace-wide connection.**
 *    Analytics, reports and stored metrics hang off the account row's id, so a second row for the same real
 *    account would split its history in two. The existing row is MOVED onto this project's connection
 *    instead — same id, same binding, same history — and from then on it is read with this project's own
 *    sign-in. The older connection and every other project's accounts on it are not touched.
 */
final class LinkConfirmedAccount
{
    public function __construct(
        private readonly ConfirmAccountSelection $confirm,
        private readonly FirstSync $firstSync,
    ) {}

    /**
     * @param  array<string,mixed>  $listed
     * @return array{account: ExternalAccount, relinked: bool, created: bool, shared?: bool}
     *
     * @throws AccountAssignedElsewhere 409 — the same real account already feeds another project
     * @throws PlanLimitReached 422 — no seat left for one more account
     */
    public function execute(
        ProviderConnection $connection,
        Project $project,
        array $listed,
        /*
         * INTEG-PROJECT-LINK-STORES-GA4-001 — WHAT is being linked. A store and a GA4 property end their
         * journey through the same two cases as an ad account (a new row, or the row this project already
         * reads moved onto its own connection with its id kept), with their own type and purpose.
         */
        string $accountType = 'ad_account',
        string $purpose = 'advertising',
    ): array {
        $externalId = (string) $listed['external_id'];
        $facts = [
            'name' => (string) $listed['name'],
            'currency' => $listed['currency'] ?? null,
            'timezone' => $listed['timezone'] ?? null,
            'status' => (string) ($listed['status'] ?? 'active'),
            'parent_external_id' => $listed['parent_external_id'] ?? null,
            'parent_name' => $listed['parent_name'] ?? null,
        ];
        /** @var array<string,mixed> $extra what only this kind of source carries (a store's domain and plan) */
        $extra = array_filter((array) ($listed['metadata'] ?? []), static fn ($v): bool => $v !== null);

        return DB::transaction(function () use ($connection, $project, $externalId, $facts, $extra, $accountType, $purpose): array {
            $now = Carbon::now();

            $here = ExternalAccount::withoutGlobalScopes()
                ->where('provider_connection_id', $connection->getKey())
                ->where('external_id', $externalId)
                ->where('account_type', $accountType)
                ->lockForUpdate()
                ->first();

            $older = $this->readByThisProjectThroughAnotherConnection($connection, $project, $externalId, $accountType);

            /*
             * SHARED-ACCOUNT-PROJECTS-001 — this project already reads the account as a SHARED one (another project is
             * its home). Linking it again is idempotent: the row stays on the home project's connection, which is the
             * one that fetches it, and nothing is moved.
             */
            if ($older !== null && $this->sharesHere($older, $project)) {
                return ['account' => $older, 'relinked' => false, 'created' => false, 'shared' => true];
            }

            if ($older !== null) {
                if ($here !== null) {
                    // A never-used double on this connection gives way to the row that carries the history.
                    $here->forceFill([
                        'external_id' => $here->external_id.'#superseded-'.substr((string) $here->getKey(), 0, 8),
                        'status' => 'superseded',
                        'metadata' => array_merge((array) ($here->metadata ?? []), [
                            'superseded_by_account_id' => (string) $older->getKey(), 'superseded_at' => $now->toIso8601String(),
                        ]),
                    ])->save();
                }

                $from = (string) $older->provider_connection_id;
                $older->forceFill(array_filter($facts, static fn ($v): bool => $v !== null) + [
                    'provider_connection_id' => $connection->getKey(),
                    'discovered_at' => $now,
                    'access_lost_at' => null,
                    'metadata' => array_merge((array) ($older->metadata ?? []), $extra, [
                        'moved_from_connection_id' => $from, 'moved_at' => $now->toIso8601String(), 'moved_by_relink_of_project' => (string) $project->getKey(),
                    ]),
                ])->save();

                $this->firstSync->start([(string) $older->getKey()], source: 'reconnect');

                return ['account' => $older, 'relinked' => true, 'created' => false];
            }

            /*
             * SHARED-ACCOUNT-PROJECTS-001 (Owner 2026-11-26) — the same real ad account already feeds another project of
             * this workspace. It is no longer refused: this project gets a SHARED binding on the row that project reads
             * (one fetch, one seat, one history), with an empty campaign scope — it receives nothing until its campaigns
             * are chosen, so no figure is counted in two projects. The client fence still holds: an account discovered
             * for one client never feeds another client's project.
             */
            if ($accountType === 'ad_account') {
                $holder = $this->heldElsewhere($connection, $project, $externalId);

                if ($holder !== null) {
                    return $this->share($holder, $project, $purpose);
                }
            }

            $created = $here === null;
            $account = $here ?? new ExternalAccount;
            // Absent stays absent, and a currency already known is never replaced by «not stated».
            $account->forceFill(array_filter($facts, static fn ($v): bool => $v !== null) + [
                'tenant_id' => $connection->tenant_id,
                'client_workspace_id' => $connection->client_workspace_id,
                'provider_connection_id' => $connection->getKey(),
                'provider' => $connection->provider,
                'external_id' => $externalId,
                'account_type' => $accountType,
                'discovered_at' => $now,
                'access_lost_at' => null,
            ] + ($extra === [] ? [] : ['metadata' => array_merge((array) ($account->metadata ?? []), $extra)]))->save();

            // Throws inside this transaction, so a refused binding leaves no row behind.
            $this->confirm->execute($connection, $project, [(string) $account->getKey()], $purpose);

            return ['account' => $account, 'relinked' => false, 'created' => $created];
        });
    }

    /**
     * The row of the same REAL ad account that ANOTHER project of this tenant reads now — its home binding first — or null.
     *
     * @throws AccountAssignedElsewhere when that row may not feed this project (another client's account)
     */
    private function heldElsewhere(ProviderConnection $connection, Project $project, string $externalId): ?ExternalAccount
    {
        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('project_id', '!=', $project->getKey())
            ->whereIn('external_account_id', ExternalAccount::withoutGlobalScopes()
                ->where('tenant_id', $connection->tenant_id)
                ->where('provider', $connection->provider)
                ->where('external_id', $externalId)
                ->where('account_type', 'ad_account')
                ->where('status', '!=', 'superseded')
                ->select('id'))
            ->orderBy('created_at')
            ->get()
            ->sortBy(static fn (ProjectIntegrationBinding $b): int => SharedAccounts::isShared($b) ? 1 : 0)
            ->values();

        $binding = $bindings->first();
        if ($binding === null) {
            return null;
        }

        $holder = ExternalAccount::withoutGlobalScopes()->lockForUpdate()->find($binding->external_account_id);
        if ($holder === null) {
            return null;
        }

        if ((string) $holder->tenant_id !== (string) $project->tenant_id
            || ($holder->client_workspace_id !== null && (string) $holder->client_workspace_id !== (string) $project->client_workspace_id)) {
            throw new AccountAssignedElsewhere((string) $holder->getKey(), (string) $binding->project_id);
        }

        return $holder;
    }

    /**
     * Bind the holder row to this project as a SHARED account: revived if this project held it before (its old scope kept),
     * created otherwise. No seat is claimed — the real account is already connected — and no sync is started: the home
     * project's fetch files the chosen campaigns here once they are picked.
     *
     * @return array{account: ExternalAccount, relinked: bool, created: bool, shared: bool}
     */
    private function share(ExternalAccount $holder, Project $project, string $purpose): array
    {
        $existing = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
            ->where('project_id', $project->getKey())
            ->where('external_account_id', $holder->getKey())
            ->where('purpose', $purpose)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'is_active' => true,
                'is_primary' => false,
                'settings' => SharedAccounts::isShared($existing) ? $existing->settings : SharedAccounts::emptySettings($existing->settings),
            ])->save();

            return ['account' => $holder, 'relinked' => false, 'created' => false, 'shared' => true];
        }

        ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)->create([
            'tenant_id' => $holder->tenant_id,
            'project_id' => $project->getKey(),
            'client_workspace_id' => $project->client_workspace_id,
            'external_account_id' => $holder->getKey(),
            'provider' => $holder->provider,
            'purpose' => $purpose,
            'is_primary' => false,
            'is_active' => true,
            'campaign_management_enabled' => $purpose === 'advertising',
            'tracking_enabled' => false,
            'settings' => SharedAccounts::emptySettings(),
        ]);

        return ['account' => $holder, 'relinked' => false, 'created' => true, 'shared' => true];
    }

    private function sharesHere(ExternalAccount $account, Project $project): bool
    {
        $binding = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $project->getKey())
            ->where('external_account_id', $account->getKey())
            ->where('is_active', true)
            ->first();

        return $binding !== null && SharedAccounts::isShared($binding);
    }

    /** The row of the same REAL account that this project already holds actively, on a different connection. */
    private function readByThisProjectThroughAnotherConnection(ProviderConnection $connection, Project $project, string $externalId, string $accountType): ?ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $connection->tenant_id)
            ->where('provider', $connection->provider)
            ->where('external_id', $externalId)
            ->where('account_type', $accountType)
            ->where('status', '!=', 'superseded')
            ->where('provider_connection_id', '!=', $connection->getKey())
            ->whereIn('id', ProjectIntegrationBinding::withoutGlobalScopes()
                ->where('project_id', $project->getKey())
                ->where('is_active', true)
                ->select('external_account_id'))
            ->lockForUpdate()
            ->first();
    }
}
