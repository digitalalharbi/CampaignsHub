<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;

/**
 * COMMAND-CENTER §12 — a name is a name. An identifier is never one.
 *
 * ## The screen this exists to stop
 *
 * Picking four accounts out of 309 is a task somebody performs by READING. When the provider returns
 * no usable name, the product used to fall back to whatever it had, and the customer was asked to
 * choose between:
 *
 * ```
 *   ○ 8f3ac1de-90b2-4c77-b0e1-2a4419d7c5aa
 *   ○ 3b71f9c0-4a55-4e18-9d2c-77ba0e6f1e93
 * ```
 *
 * There is no answer to that question. It is not a hard choice, it is an unanswerable one, and every
 * such row is a chance to attach the wrong client's spend to the wrong project.
 *
 * ## What this returns instead
 *
 * Two fields, never merged:
 *
 *  - `name` — what to READ. The provider's name when there is one; otherwise an explicit statement
 *    that the provider gave none, in words, in the interface language. Never an identifier.
 *  - `reference` — what to MATCH against the provider's own console, shown as secondary detail.
 *    Always the raw external id, never dressed up as a name.
 *
 * Saying «(no name from Snapchat)» beside the id is worth more than the id alone: it tells the
 * customer the blank is the provider's, not ours, so they go and name it there rather than
 * hunting for a setting here.
 *
 * ## Why the provider's name is not simply trusted
 *
 * Connectors normalise missing names by writing the external id into `name`, and several providers
 * return the id as the name themselves. So a `name` equal to the `external_id` is not a name that
 * happens to look like an id — it is the absence of a name, already stored. Detecting it here keeps
 * that from reaching the screen as though somebody had chosen it.
 */
final class AccountLabel
{
    /**
     * The display name and the reference, separated.
     *
     * @return array{name: string, reference: string, named_by_provider: bool}
     */
    public function describe(ExternalAccount $account): array
    {
        $raw = trim($account->name);
        $externalId = trim($account->external_id);

        $named = $raw !== '' && $raw !== $externalId && ! $this->looksLikeIdentifier($raw);

        return [
            'name' => $named ? $raw : $this->unnamed($account),
            'reference' => $externalId,
            'named_by_provider' => $named,
        ];
    }

    /** Just the readable part, for callers building a one-line label. */
    public function nameFor(ExternalAccount $account): string
    {
        return $this->describe($account)['name'];
    }

    /**
     * Words, in the interface language, saying the provider supplied no name.
     *
     * Deliberately NOT «حساب بدون اسم» alone — naming the provider and the kind of account is what
     * makes the row distinguishable from the next unnamed one at a glance, before the reference is
     * read at all.
     */
    private function unnamed(ExternalAccount $account): string
    {
        return __('integrations.account_unnamed', [
            'provider' => __('providers.'.$account->provider),
            'type' => __('integrations.account_type.'.$account->account_type),
        ]);
    }

    /**
     * Whether a string is an identifier wearing a name's clothes.
     *
     * Three shapes, all of which really occur across the eight providers: a UUID, a long run of
     * digits (Meta's `act_` ids and Google's customer ids once the prefix is stripped), and a
     * provider-prefixed id. A short numeric string is deliberately NOT matched — «2024» is a
     * plausible campaign-account name and refusing it would invent a blank the provider did not have.
     */
    private function looksLikeIdentifier(string $value): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        if (preg_match('/^\d{9,}$/', $value) === 1) {
            return true;
        }

        return preg_match('/^(act_|urn:li:|customers\/)[A-Za-z0-9_-]+$/i', $value) === 1;
    }
}
