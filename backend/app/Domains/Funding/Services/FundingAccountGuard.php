<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FIN-LOOP-001 — an ad account feeds ONE project's budget at a time (Owner decision 2026-11-13).
 *
 * Each project deducts the spend synced under it. An account actively selected by two projects that
 * both track a prepaid budget would let the same money leave two budgets, so the second selection is
 * refused with a message naming the project that already holds it. A project with no prepaid budget
 * is not affected: selecting an account for reporting there is still allowed.
 */
final class FundingAccountGuard
{
    /**
     * The first account of `$accountIds` already selected by ANOTHER project with a prepaid budget.
     *
     * @param  list<string>  $accountIds
     * @return array{account: string, project: string}|null
     */
    public function conflict(string $projectId, array $accountIds): ?array
    {
        if ($accountIds === []) {
            return null;
        }

        // The same real account can be reached through two connections (two rows): match the provider's own id too.
        $same = DB::table('external_accounts as mine')->join('external_accounts as twin', function ($j): void {
            $j->on('twin.provider', '=', 'mine.provider')->on('twin.external_id', '=', 'mine.external_id')
                ->on('twin.account_type', '=', 'mine.account_type')->on('twin.tenant_id', '=', 'mine.tenant_id');
        })->whereIn('mine.id', $accountIds)->pluck('twin.id')->map(static fn ($id): string => (string) $id)->all();

        $row = DB::table('project_integration_bindings as b')
            ->join('project_funding as f', 'f.project_id', '=', 'b.project_id')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->leftJoin('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->whereIn('b.external_account_id', array_values(array_unique([...$accountIds, ...$same])))
            ->where('b.project_id', '!=', $projectId)
            ->where('b.is_active', true)
            ->where('b.purpose', 'advertising')
            ->where('f.payment_method', 'prepaid')
            ->whereNull('f.voided_at')
            ->first(['a.name as account', 'p.name as project']);

        return $row === null ? null : ['account' => (string) ($row->account ?? ''), 'project' => (string) $row->project];
    }

    /** Refuse, in the reader's language, when the account already feeds another project's budget. */
    public function assertFree(string $projectId, string $accountId, string $field = 'external_account_id'): void
    {
        $hit = $this->conflict($projectId, [$accountId]);
        if ($hit !== null) {
            throw ValidationException::withMessages([$field => [__('funding.account_taken', ['project' => $hit['project']])]]);
        }
    }

    /** Whether this project tracks a prepaid budget — the only case in which its accounts must be exclusive. */
    public function tracksBudget(string $projectId): bool
    {
        return DB::table('project_funding')->where('project_id', $projectId)->where('payment_method', 'prepaid')->whereNull('voided_at')->exists();
    }

    /** @return list<string> the project's actively selected advertising accounts */
    public function accountsOf(string $projectId): array
    {
        return DB::table('project_integration_bindings')->where('project_id', $projectId)
            ->where('is_active', true)->where('purpose', 'advertising')
            ->pluck('external_account_id')->map(static fn ($id): string => (string) $id)->all();
    }
}
