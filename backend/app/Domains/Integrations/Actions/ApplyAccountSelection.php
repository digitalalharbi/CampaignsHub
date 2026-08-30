<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §8 — «Manage accounts» saves a DIFF, not a fresh connection.
 *
 * ## What the product could not do before this
 *
 * `ConfirmAccountSelection` is the first commit: it takes a list of accounts, refuses an empty one,
 * charges the plan for what is new, and starts the first sync. It has no concept of REMOVAL, because
 * the wizard that calls it is answering «which accounts shall this project start with».
 *
 * A customer who has connected six LinkedIn accounts and wants five has a different question, and
 * the only answers the product had were: detach one binding at a time from a raw inventory, or
 * disconnect the provider and authorise again from scratch. The second one is what people actually
 * did — and it costs the connection, every binding under it, and the sync history.
 *
 * ## The diff, and why it is computed here rather than in the browser
 *
 * The client sends the DESIRED SET. The server compares it with what is bound now and derives the
 * three groups — added, unchanged, removed. A client that sent «add these, remove those» would be
 * describing a state it read some seconds ago, and two operators managing the same project would
 * each remove what the other had just added. The desired set is idempotent: sending it twice is the
 * same decision, and the second time changes nothing.
 *
 * ## Removal deactivates
 *
 * `is_active = false`, never a delete. The binding is what says a metric row belongs to this
 * project, and a deleted binding orphans months of history that the project is still entitled to
 * show. Re-selecting the same account reactivates the same row — which is also why re-adding does
 * not charge the plan twice.
 */
final class ApplyAccountSelection
{
    public function __construct(
        private readonly ConfirmAccountSelection $confirm,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $desiredAccountIds  the accounts this project should end up with
     * @return array{added: list<string>, unchanged: list<string>, removed: list<string>}
     */
    public function execute(
        ProviderConnection $connection,
        Project $project,
        array $desiredAccountIds,
        string $purpose = 'advertising',
    ): array {
        $desired = array_values(array_unique($desiredAccountIds));

        /*
         * What this project holds from THIS connection — not from the tenant, and not from another
         * provider. A diff computed over a wider set would unbind a project's Meta accounts because
         * somebody managed its LinkedIn ones.
         */
        $currentBindings = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $project->getKey())
            ->where('is_active', true)
            ->whereIn(
                'external_account_id',
                ExternalAccount::withoutGlobalScopes()
                    ->where('provider_connection_id', $connection->getKey())
                    ->select('id'),
            )
            ->get();

        $current = $currentBindings
            ->map(static fn (ProjectIntegrationBinding $b): string => (string) $b->external_account_id)
            ->all();

        $added = array_values(array_diff($desired, $current));
        $unchanged = array_values(array_intersect($desired, $current));
        $removed = array_values(array_diff($current, $desired));

        /*
         * The additions go through `ConfirmAccountSelection` unchanged: the plan quota, the
         * client-workspace fence, the «assigned elsewhere» refusal and the first sync are its
         * responsibilities and must not be reimplemented here. It is called only when something was
         * actually added, because it refuses an empty list — correctly, for its own use.
         */
        if ($added !== []) {
            $this->confirm->execute(
                connection: $connection,
                project: $project,
                accountIds: array_values(array_unique([...$added, ...$unchanged])),
                purpose: $purpose,
            );
        }

        if ($removed !== []) {
            DB::transaction(function () use ($currentBindings, $removed): void {
                foreach ($currentBindings as $binding) {
                    if (in_array((string) $binding->external_account_id, $removed, true)) {
                        // Deactivated, never deleted — the binding is what makes months of metrics
                        // this project's, and a delete would orphan them.
                        $binding->update(['is_active' => false]);
                    }
                }
            });
        }

        if ($added !== [] || $removed !== []) {
            $this->audit->log(
                action: 'integration.selection.updated',
                entityType: Project::class,
                entityId: (string) $project->getKey(),
                after: [
                    'provider' => $connection->provider,
                    'connection' => (string) $connection->getKey(),
                    'added' => $added,
                    'removed' => $removed,
                    'unchanged' => $unchanged,
                ],
            );
        }

        return ['added' => $added, 'unchanged' => $unchanged, 'removed' => $removed];
    }
}
