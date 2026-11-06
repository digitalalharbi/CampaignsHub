<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Exceptions\AccountAssignedElsewhere;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\FirstSync;
use App\Domains\Integrations\Services\LinkDirectory;
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
     * @return array{account: ExternalAccount, relinked: bool, created: bool}
     *
     * @throws AccountAssignedElsewhere 409 — the same real account already feeds another project
     * @throws PlanLimitReached 422 — no seat left for one more account
     */
    public function execute(ProviderConnection $connection, Project $project, array $listed): array
    {
        $externalId = (string) $listed['external_id'];
        $facts = [
            'name' => (string) $listed['name'],
            'currency' => $listed['currency'] ?? null,
            'timezone' => $listed['timezone'] ?? null,
            'status' => (string) ($listed['status'] ?? 'active'),
            'parent_external_id' => $listed['parent_external_id'] ?? null,
            'parent_name' => $listed['parent_name'] ?? null,
        ];

        return DB::transaction(function () use ($connection, $project, $externalId, $facts): array {
            $now = Carbon::now();

            $here = ExternalAccount::withoutGlobalScopes()
                ->where('provider_connection_id', $connection->getKey())
                ->where('external_id', $externalId)
                ->where('account_type', 'ad_account')
                ->lockForUpdate()
                ->first();

            $older = $this->readByThisProjectThroughAnotherConnection($connection, $project, $externalId);

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
                    'metadata' => array_merge((array) ($older->metadata ?? []), [
                        'moved_from_connection_id' => $from, 'moved_at' => $now->toIso8601String(), 'moved_by_relink_of_project' => (string) $project->getKey(),
                    ]),
                ])->save();

                $this->firstSync->start([(string) $older->getKey()], source: 'reconnect');

                return ['account' => $older, 'relinked' => true, 'created' => false];
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
                'account_type' => 'ad_account',
                'discovered_at' => $now,
                'access_lost_at' => null,
            ])->save();

            // Throws inside this transaction, so a refused binding leaves no row behind.
            $this->confirm->execute($connection, $project, [(string) $account->getKey()], 'advertising');

            return ['account' => $account, 'relinked' => false, 'created' => $created];
        });
    }

    /** The row of the same REAL account that this project already holds actively, on a different connection. */
    private function readByThisProjectThroughAnotherConnection(ProviderConnection $connection, Project $project, string $externalId): ?ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $connection->tenant_id)
            ->where('provider', $connection->provider)
            ->where('external_id', $externalId)
            ->where('account_type', 'ad_account')
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
