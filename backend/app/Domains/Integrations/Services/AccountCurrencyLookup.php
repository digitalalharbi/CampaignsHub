<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Metrics\Services\SourceCurrency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MONEY-CURRENCY-NEVER-ASSUMED-001 — ask the provider what currency an ad account is in, once, and record it.
 *
 * ## Where it sits in the rule
 *
 * Money is converted from the row's currency, else the account's. When the account was stored without
 * one, the sync asks the provider BEFORE it reads any money — through the same listing discovery uses
 * (`listAdAccounts()`, which for TikTok already includes its `advertiser/info/` detail call) — and writes
 * the answer onto the account. If the provider does not state it either, the money is withheld; nothing
 * here ever guesses.
 *
 * ## Deliberately narrow
 *
 * It is not `AccountDiscovery::refresh()`. A refresh creates accounts, renames them and marks the ones it
 * did not see as unreachable — the right things for an operator pressing «refresh», and far too much for
 * a metrics sync to do on the side. This fills ONE column, only where it is empty, and only on accounts
 * of the connection it was asked about (every currency-less sibling in the same answer is filled from the
 * one call, so twenty-seven accounts on one connection cost one request, not twenty-seven).
 *
 * ## «Once»
 *
 * A provider that does not publish an account currency at all (X: the account object carries none) would
 * otherwise be asked by every half-hourly sweep of every account, for ever. The question is asked at most
 * once per connection per {@see RETRY_AFTER_HOURS}; in between, the outcome is `asked_recently` and the
 * money stays withheld, which is the truthful state.
 */
final class AccountCurrencyLookup
{
    public const RETRY_AFTER_HOURS = 6;

    /** The account already has a currency; nothing was asked. */
    public const KNOWN = 'known';

    /** The provider stated it and it is now recorded on the account. */
    public const FOUND = 'found';

    /** The provider answered and did not state a currency for this account. */
    public const NOT_STATED = 'not_stated';

    /** The provider could not be asked (the call failed). Money is withheld; the next window asks again. */
    public const FAILED = 'failed';

    /** Asked within the retry window and not answered; not asked again yet. */
    public const ASKED_RECENTLY = 'asked_recently';

    /**
     * @param  ApiAdvertisingConnector  $connector  already bound to the account's connection
     * @return self::KNOWN|self::FOUND|self::NOT_STATED|self::FAILED|self::ASKED_RECENTLY
     */
    public function ensure(ExternalAccount $account, ApiAdvertisingConnector $connector): string
    {
        if (SourceCurrency::clean($account->currency) !== null) {
            return self::KNOWN;
        }

        // `add` is atomic: two sweeps reaching the same connection together ask once between them.
        if (! Cache::add($this->throttleKey($account), 1, now()->addHours(self::RETRY_AFTER_HOURS))) {
            return self::ASKED_RECENTLY;
        }

        try {
            /*
             * INTEG-ON-DEMAND-DISCOVERY-001 — a project's connection asks about THIS account only where the
             * platform can answer for one account; the listing is the fallback for a platform that cannot,
             * and for every workspace-wide connection, exactly as before. Either way only a currency is written.
             */
            $one = $this->belongsToAProject($account) ? $connector->readAdAccount((string) $account->external_id) : null;
            $listed = $one !== null ? [$one] : $connector->listAdAccounts();
        } catch (Throwable $e) {
            // Released, so a transient refusal does not cost six hours of withheld money.
            Cache::forget($this->throttleKey($account));
            Log::warning('The account currency could not be asked of the provider; money is withheld until it is known.', [
                'provider' => $account->provider,
                'account' => substr((string) $account->getKey(), 0, 8),
                'reason' => ProviderErrorText::forStorage($e->getMessage()),
            ]);

            return self::FAILED;
        }

        $stated = [];
        foreach ($listed as $row) {
            $currency = SourceCurrency::clean($row['currency'] ?? null);
            if ($currency !== null) {
                $stated[(string) ($row['external_id'] ?? '')] = $currency;
            }
        }

        if ($stated === []) {
            return self::NOT_STATED;
        }

        // Every currency-less ad account of this connection that the answer names — never one that has a currency.
        $siblings = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $account->provider_connection_id)
            ->where('account_type', 'ad_account')
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', ''))
            ->whereIn('external_id', array_map('strval', array_keys($stated)))
            ->get();

        foreach ($siblings as $sibling) {
            $sibling->forceFill(['currency' => $stated[(string) $sibling->external_id]])->save();
        }

        $found = $stated[(string) $account->external_id] ?? null;
        if ($found === null) {
            return self::NOT_STATED;
        }

        // The caller's own instance carries the answer into the rest of the sync.
        $account->forceFill(['currency' => $found])->syncOriginalAttribute('currency');

        return self::FOUND;
    }

    private function belongsToAProject(ExternalAccount $account): bool
    {
        return ProviderConnection::withoutGlobalScopes()->whereKey($account->provider_connection_id)->whereNotNull('project_id')->exists();
    }

    private function throttleKey(ExternalAccount $account): string
    {
        return 'account-currency-lookup:'.$account->provider_connection_id;
    }
}
