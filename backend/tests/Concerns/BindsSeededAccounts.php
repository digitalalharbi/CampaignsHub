<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Str;
use Tests\Support\SeededAccountBinder;

/**
 * Deny by default — a project reads only the accounts it has ACTIVELY selected (BoundAccountVisibility::UNBOUND_ROWS_VISIBLE is
 * false). A test world that seeds rows for an account has to select that account, exactly as an operator does. Most worlds get this
 * from {@see SeededAccountBinder} automatically; this is the explicit form, for a world that wants a named account bound up front.
 */
trait BindsSeededAccounts
{
    /** Make sure the ad account exists and has an ACTIVE binding to the project. Idempotent. Returns the account id. */
    protected function bindSeededAccount(string $projectId, ?string $accountId = null, string $provider = 'meta'): string
    {
        return SeededAccountBinder::ensure($projectId, $accountId ?? (string) Str::uuid(), $provider);
    }
}
