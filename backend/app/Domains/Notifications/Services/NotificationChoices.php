<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Support\MessageCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * What one person has actually chosen, for one message type — MAIL-011.
 *
 * ## Why every sender asks this class instead of reading the row
 *
 * There were three readers of `notification_preferences` before this: `NotificationDispatcher` for
 * the bell, `NotificationAudience` for recipient arrangements, and `SendAlerts` for the sweep. They
 * agreed on the easy case and disagreed on every hard one — an absent key, a master switch that
 * contradicts a category, a type nobody had classified. Whichever of them a message happened to pass
 * through decided whether it arrived.
 *
 * ## The order, and why it is that order
 *
 * 1. **Mandatory types are on.** A password reset is not a preference. See `MessageCatalogue`.
 * 2. **The digests answer from `digests`,** because that is where they have always been stored and
 *    where `SendDailyDigests` reads them.
 * 3. **No preference row at all → the catalogue's default.** A person who has never opened their
 *    settings must still be reachable, or a manager's recipient list would work for nobody.
 * 4. **The master channel switch outranks everything below it.** «Email off» is off. A per-type
 *    switch under it would let the screen show something enabled that cannot be delivered.
 * 5. **The person's own per-type choice.**
 * 6. **Their older per-CATEGORY choice**, for types that genuinely lived under one. This is what
 *    keeps every row written before MAIL-011 meaning what its owner meant: somebody who switched
 *    «الميزانية» off in 2026 still has budget mail off today without touching the new screen.
 * 7. **The catalogue's default.**
 *
 * Step 6 is deliberately NOT applied to types whose legacy category is null. Those were unmapped and
 * landed in `performance` by accident — inheriting an accident is not backwards compatibility, it is
 * the same bug with a longer history.
 *
 * ## Memoised per instance, not cached
 *
 * The alert sweep asks about a dozen notes for one person in a row. A container-scoped memo makes
 * that one query; a cache would make a preference change take effect at some unstated later moment,
 * which is exactly the kind of «I turned it off and it kept coming» this unit exists to end.
 */
final class NotificationChoices
{
    /** @var array<string, ?object> */
    private array $rows = [];

    /**
     * Will this person receive `$type` on `$channel`?
     *
     * @param  'email'|'in_app'  $channel
     */
    public function wants(int $userId, string $tenantId, string $type, string $channel): bool
    {
        $chosen = $this->chose($userId, $tenantId, $type, $channel);

        if ($chosen !== null) {
            return $chosen;
        }

        $definition = MessageCatalogue::get($type);

        // A type this class has never heard of — an in-app string from a domain that has not joined
        // the catalogue yet. Deliver it: silence would be a decision nobody made.
        return $definition === null ? true : (bool) $definition['default_'.$channel];
    }

    /**
     * What this person has EXPLICITLY said about `$type`, or null if they have never said anything.
     *
     * ## Why the difference from `wants()` matters, and where
     *
     * `digests.alerts = true` is somebody saying «mail me findings as they happen». If the alert
     * sweep then asked `wants()`, the catalogue's defaults would silently narrow that to the subset
     * whose default is on — a person would opt in, receive four of the eight kinds of finding, and
     * have nothing on any screen to explain the other four.
     *
     * So the sweep asks this instead and skips only on an explicit `false`. The defaults still
     * decide everywhere the person has NOT already made a broader statement: the bell, and the
     * recipient arrangements a manager makes on their behalf.
     *
     * @param  'email'|'in_app'  $channel
     */
    public function chose(int $userId, string $tenantId, string $type, string $channel): ?bool
    {
        if (MessageCatalogue::isMandatory($type)) {
            return true;
        }

        $row = $this->row($userId, $tenantId);

        if (isset(MessageCatalogue::DIGEST_SWITCH[$type])) {
            // A digest is an email rhythm and has no in-app form — there is no bell entry for
            // «yesterday, summarised». Answering `true` for in_app would put a tick on the screen
            // beside a channel that will never carry it.
            if ($channel !== 'email') {
                return false;
            }

            $digests = $this->map($row, 'digests');

            return ($digests[MessageCatalogue::DIGEST_SWITCH[$type]] ?? false) === true;
        }

        if ($row === null) {
            return null;
        }

        $channels = $this->map($row, 'channels');
        if (($channels[$channel] ?? true) !== true) {
            return false;
        }

        $types = $this->map($row, 'types');
        if (isset($types[$type]) && array_key_exists($channel, (array) $types[$type])) {
            return (bool) $types[$type][$channel];
        }

        $definition = MessageCatalogue::get($type);
        if ($definition === null) {
            return null;
        }

        $categories = $this->map($row, 'categories');
        $legacy = $definition['legacy'];

        if ($legacy !== null && isset($categories[$legacy]) && array_key_exists($channel, (array) $categories[$legacy])) {
            return (bool) $categories[$legacy][$channel];
        }

        return null;
    }

    /**
     * The same question for a whole category — what a recipient arrangement names.
     *
     * A category is wanted when ANY type inside it is, because that is what the manager asked for:
     * «tell them about the budget». Answering «no» while `budget_pace` is switched on would silence a
     * message the person has explicitly asked to receive.
     */
    public function wantsCategory(int $userId, string $tenantId, string $category, string $channel = 'email'): bool
    {
        $category = MessageCatalogue::normaliseCategory($category);
        $types = MessageCatalogue::inCategory($category);

        if ($types === []) {
            return false;
        }

        foreach ($types as $type) {
            if (isset(MessageCatalogue::DIGEST_SWITCH[$type])) {
                continue; // a digest is a rhythm somebody opts into, not an alert category
            }
            if ($this->wants($userId, $tenantId, $type, $channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * When this person wants `$type` — `immediate`, `daily` or `weekly`.
     *
     * `immediate` unless they said otherwise, and only ever one of the rhythms the catalogue offers
     * for that type: a stored value that is no longer offered (a type that stopped being digestible)
     * must not silently hold a message forever.
     */
    public function rhythm(int $userId, string $tenantId, string $type): string
    {
        $offered = MessageCatalogue::rhythmsFor($type);

        if ($offered === [] || $offered === ['immediate']) {
            return 'immediate';
        }

        $types = $this->map($this->row($userId, $tenantId), 'types');
        $chosen = $types[$type]['rhythm'] ?? null;

        return is_string($chosen) && in_array($chosen, $offered, true) ? $chosen : 'immediate';
    }

    /** Forget the memo — for a caller that has just written the row and wants to read it back. */
    public function forget(): void
    {
        $this->rows = [];
    }

    private function row(int $userId, string $tenantId): ?object
    {
        $key = $tenantId.':'.$userId;

        return $this->rows[$key] ??= DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('client_workspace_id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function map(?object $row, string $column): array
    {
        $raw = $row?->{$column} ?? null;

        if ($raw === null) {
            return [];
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
