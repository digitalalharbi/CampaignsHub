<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Catalogue\ProviderHierarchy;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;

/**
 * ORCH-100 §39 §41 — where a connection has got to, worked out from the record rather than remembered.
 *
 * ## Why this is derived and not stored
 *
 * A wizard that keeps its progress in the browser loses it when somebody closes the tab, and the
 * product's answer to that was to send them back through OAuth — re-consenting to an authorisation
 * that was already granted and still valid. A wizard that keeps its progress in a session table has
 * to be expired, cleaned up, and reconciled when the two disagree.
 *
 * There is a third option, and it is the honest one: the state IS the data. A connection either has
 * discovered accounts or it has not; those accounts either have active bindings or they do not. Every
 * step of the wizard is a question the database can already answer, so nothing can drift, nothing
 * expires, and «resume» is just asking again.
 *
 * That is what makes the live Snapchat connection resumable at all: it was authorised days ago, in a
 * browser that has long since been closed, and it can still be picked up at exactly the step it
 * reached — because that step is a fact about 309 discovered accounts with no bindings, not a cookie.
 *
 * ## The states, and why «connected» is not one of them
 *
 * The interface used to collapse all of this into a green «متصل» chip, which is how an integration
 * that had done nothing but authorise could look finished. Each state below names a different next
 * action, which is the only reason to distinguish them.
 */
final class ConnectionWizardState
{
    /** Authorised, and the provider returned nothing to choose from. Rare, and worth saying plainly. */
    public const NO_ACCOUNTS = 'authorized_no_accounts';

    /** Authorised, accounts discovered, none chosen yet — the live Snapchat case, 309 of them. */
    public const NEEDS_SELECTION = 'needs_selection';

    /** Accounts chosen and assigned, but no successful sync has run against any of them. */
    public const FIRST_SYNC_PENDING = 'first_sync_pending';

    /** At least one assigned account has really synced. */
    public const ACTIVE = 'active';

    /** The provider has stopped honouring the authorisation. */
    public const ACCESS_REVOKED = 'access_revoked';

    public function __construct(private readonly AccountHealth $health) {}

    /**
     * Everything the integrations page needs to say what is true and offer the next step.
     *
     * @return array{
     *     state: string,
     *     discovered: int,
     *     assigned: int,
     *     synced: int,
     *     has_parent: bool,
     *     resumable: bool,
     *     next_step: ?string,
     *     health: array{connected:int, healthy:int, needs_attention:int, pending_first_sync:int, states:array<string,int>},
     * }
     */
    public function for(ProviderConnection $connection): array
    {
        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account');

        $discovered = (clone $accounts)->count();

        $assigned = ProjectIntegrationBinding::withoutGlobalScopes()
            ->whereIn('external_account_id', (clone $accounts)->select('id'))
            ->where('is_active', true)
            ->distinct()
            ->count('external_account_id');

        // A real sync, not a discovery: `last_synced_at` is only written when data actually arrives
        // (DISCOVERY-NOT-SYNC-001), so this counts accounts that have genuinely produced something.
        $synced = (clone $accounts)->whereNotNull('last_synced_at')->count();

        $state = match (true) {
            in_array($connection->status, ['revoked', 'disconnected', 'error'], true) => self::ACCESS_REVOKED,
            $discovered === 0 => self::NO_ACCOUNTS,
            $assigned === 0 => self::NEEDS_SELECTION,
            $synced === 0 => self::FIRST_SYNC_PENDING,
            default => self::ACTIVE,
        };

        return [
            'state' => $state,
            'discovered' => $discovered,
            'assigned' => $assigned,
            'synced' => $synced,
            'has_parent' => ProviderHierarchy::hasParent($connection->provider),
            /*
             * Resumable means: there is an authorisation here worth continuing from, so do NOT ask
             * for consent again. This is the whole point of deriving the state — a connection sitting
             * at `needs_selection` for a week is still resumable, because the token is still good.
             */
            'resumable' => $state === self::NEEDS_SELECTION,
            /*
             * RUNTIME-100 §31 — a connection's headline is a SUMMARY of its accounts.
             *
             * Ten accounts behind one authorisation, nine syncing and one whose access was withdrawn,
             * used to render as a single green «متصل» — and that one account is the only fact on the
             * card anybody needed. «10 مربوطة · 9 سليمة · 1 يحتاج انتباه» is the sentence that says it.
             */
            'health' => $this->health->summarise((string) $connection->getKey()),
            'next_step' => match ($state) {
                self::NEEDS_SELECTION => ProviderHierarchy::hasParent($connection->provider) ? 'parent' : 'accounts',
                self::FIRST_SYNC_PENDING => 'sync',
                self::NO_ACCOUNTS, self::ACCESS_REVOKED => 'reconnect',
                default => null,
            },
        ];
    }
}
