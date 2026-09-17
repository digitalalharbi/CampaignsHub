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

    /**
     * INTEGRATION-DATASOURCE-WIZARD-001 §14 — the states a READER is shown, in one place.
     *
     * The five above are facts about the record; these are what a person is told, and every surface
     * was inventing its own vocabulary for them. The integrations card said «متصل», the wizard said
     * «needs selection», the project page said «قيد المزامنة», and none of the three agreed about a
     * connection whose accounts were bound but had never produced a row.
     *
     * The mapping is one-way and lives here: the record decides, this names it, and no surface adds
     * a tenth. They are RUNTIME states — what to show and what to offer — and are unrelated to the
     * Matrix's status vocabulary, which describes requirements rather than connections.
     */
    public const USER_NOT_CONNECTED = 'NOT_CONNECTED';

    public const USER_AUTH_REQUIRED = 'AUTH_REQUIRED';

    public const USER_ACCOUNT_SELECTION_REQUIRED = 'ACCOUNT_SELECTION_REQUIRED';

    public const USER_SYNCING = 'SYNCING';

    public const USER_HEALTHY = 'HEALTHY';

    public const USER_ATTENTION_REQUIRED = 'ATTENTION_REQUIRED';

    public const USER_REAUTH_REQUIRED = 'REAUTH_REQUIRED';

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
     *     user_state: string,
     *     health: array{connected:int, healthy:int, needs_attention:int, pending_first_sync:int, states:array<string,int>},
     * }
     */
    public function for(ProviderConnection $connection): array
    {
        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account');

        /*
         * GADS-STALE-PICKER-001 — «currently selectable», not «ever discovered».
         *
         * This counted every `ExternalAccount` row the connection had ever produced. The rows survive a
         * refused discovery on purpose — a temporary failure must not unbind work an operator already did
         * — so after a refusal this count offered accounts that the provider had just declined to confirm.
         * That is how the banner said «1 account available — Finish selecting accounts» while the card
         * said «0 ad accounts», and how the button opened a connection that answered «Item not found».
         *
         * A discovery that was REFUSED is not a discovery that found nothing, and neither is the same as
         * one that has never run. The rows are still there and still bound; what they are not is a current
         * answer to «what can be selected right now».
         */
        $everDiscovered = (clone $accounts)->count();
        $blocked = $connection->discovery_blocked_reason !== null;
        $discovered = $blocked ? 0 : $everDiscovered;

        $assigned = ProjectIntegrationBinding::withoutGlobalScopes()
            ->whereIn('external_account_id', (clone $accounts)->select('id'))
            ->where('is_active', true)
            ->distinct()
            ->count('external_account_id');

        // A real sync, not a discovery: `last_synced_at` is only written when data actually arrives
        // (DISCOVERY-NOT-SYNC-001), so this counts accounts that have genuinely produced something.
        // Only accounts that are SELECTED: a deselected account that once synced must not make a
        // never-synced selection read as active (ACCOUNT-SCOPE-ISOLATION-001).
        $synced = (clone $accounts)
            ->whereNotNull('last_synced_at')
            ->whereIn('id', ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->select('external_account_id'))
            ->count();

        $state = match (true) {
            in_array($connection->status, ['revoked', 'disconnected', 'error'], true) => self::ACCESS_REVOKED,
            $discovered === 0 => self::NO_ACCOUNTS,
            $assigned === 0 => self::NEEDS_SELECTION,
            $synced === 0 => self::FIRST_SYNC_PENDING,
            default => self::ACTIVE,
        };

        $health = $this->health->summarise((string) $connection->getKey());

        /*
         * INTEGRATION-DATASOURCE-WIZARD-001 §14 — what the reader is told, derived once.
         *
         * `ATTENTION_REQUIRED` outranks `HEALTHY` deliberately: ten accounts behind one
         * authorisation with one whose access was withdrawn is not a healthy connection, and the
         * card that called it one is why nobody went and looked. And a connection with bindings but
         * no row yet is SYNCING rather than healthy — «connected» over an empty dashboard is the
         * sentence customers read as «your data is gone».
         */
        /*
         * INTEGRATION-DATASOURCE-WIZARD-001 §9 — an authorisation can lapse without the connection
         * row ever hearing about it.
         *
         * `connections.status` is written when a token refresh fails. A customer who removes this
         * app's access at the PLATFORM, or whose ad-account permission is withdrawn one account at a
         * time, produces no refresh failure at all: the row still reads «connected», and every
         * account under it reads `revoked` or `access_lost` one by one.
         *
         * When that is true of ALL of them the authorisation is gone, whatever the row says, and
         * every action on the card except reconnecting is going to fail. One account in that state
         * out of ten is a different fact and stays «needs attention» — it is one account's
         * permission, and re-authorising the whole connection is not the answer to it.
         */
        $lostAccess = ($health['states'][AccountHealth::REVOKED] ?? 0)
            + ($health['states'][AccountHealth::ACCESS_LOST] ?? 0);
        $authorisationLost = ($health['connected'] ?? 0) > 0 && $lostAccess === $health['connected'];

        $userState = match (true) {
            $state === self::ACCESS_REVOKED, $authorisationLost => self::USER_REAUTH_REQUIRED,
            $state === self::NO_ACCOUNTS => self::USER_AUTH_REQUIRED,
            $state === self::NEEDS_SELECTION => self::USER_ACCOUNT_SELECTION_REQUIRED,
            ($health['needs_attention'] ?? 0) > 0 => self::USER_ATTENTION_REQUIRED,
            $state === self::FIRST_SYNC_PENDING => self::USER_SYNCING,
            // Nothing selected has produced data yet: still syncing, not «working».
            ($health['healthy'] ?? 0) === 0 && ($health['pending_first_sync'] ?? 0) > 0 => self::USER_SYNCING,
            default => self::USER_HEALTHY,
        };

        return [
            'state' => $state,
            'user_state' => $userState,
            'discovered' => $discovered,
            /*
             * Both numbers, because they answer different questions and the interface needs both: the
             * banner may only offer what is selectable NOW, while «you had 3 accounts here» is what makes
             * a blocked state legible rather than alarming.
             */
            'ever_discovered' => $everDiscovered,
            'discovery_blocked_reason' => $connection->discovery_blocked_reason,
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
            'health' => $health,
            'next_step' => match ($state) {
                self::NEEDS_SELECTION => ProviderHierarchy::hasParent($connection->provider) ? 'parent' : 'accounts',
                self::FIRST_SYNC_PENDING => 'sync',
                self::NO_ACCOUNTS, self::ACCESS_REVOKED => 'reconnect',
                default => null,
            },
        ];
    }
}
