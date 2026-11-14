<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Notifications\Support\NotificationChannelPolicy;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single entry point for raising a notification. Handles, in order:
 *   1. DEDUPLICATION — a stable dedup_key collapses repeats within a window (no notification storms);
 *   2. PREFERENCES — per-user, optionally per-client, per-category channel toggles;
 *   3. QUIET HOURS — email is held (suppressed_by_quiet_hours) during the user's quiet window;
 *   4. DELIVERY LOG — one row per channel with an explicit state.
 * Email never records "sent" — it stays awaiting_credentials until a real mail provider is wired.
 */
final class NotificationDispatcher
{
    private const DEDUP_WINDOW_MINUTES = 60;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly NotificationChoices $choices,
        private readonly SpendAudience $audience,
        private readonly WhatsAppChannel $whatsapp,
    ) {}

    /**
     * Notification type → preference category.
     *
     * Kept for the types that are NOT in `MessageCatalogue` — request-lifecycle strings such as
     * `request.journey.approved`, which are generated per state and cannot be enumerated. A type the
     * catalogue knows is answered by `NotificationChoices` instead, which reads the person's per-type
     * switch first and only then falls back to a map like this one.
     */
    private const CATEGORY = [
        'budget_risk' => 'budget',
        'sync_failed' => 'sync',
        'token_expiring' => 'token',
        'report_ready' => 'reports',
        'report_failed' => 'reports',
        'security' => 'security',
    ];

    /**
     * @param  array<string,mixed>  $p  requires tenant_id, type, title; optional user_id, client_workspace_id,
     *                                  project_id, severity, message, source, entity_type, entity_id,
     *                                  action_url, dedup_extra
     * @return AppNotification|null the created notification, or null if deduped / suppressed in-app
     */
    public function dispatch(array $p): ?AppNotification
    {
        $tenantId = (string) $p['tenant_id'];
        $userId = $p['user_id'] ?? null;
        $type = (string) $p['type'];
        $category = self::CATEGORY[$type] ?? 'performance';
        // The catalogue type a person's per-type switch is filed under: `request.assigned` → `request`.
        $switchKey = NotificationChannelPolicy::switchKey($type);
        $dedupKey = $this->dedupKey($tenantId, $userId, $type, $p);

        // 1) Dedup — an identical notification within the window is collapsed.
        if ($this->isDuplicate($tenantId, $userId, $dedupKey)) {
            return null;
        }

        $clientWorkspaceId = $p['client_workspace_id'] ?? null;
        $prefs = $this->preferences($tenantId, $userId, $clientWorkspaceId);
        $perClient = $this->hasClientOverride($tenantId, $userId, $clientWorkspaceId);
        $inAppEnabled = $this->wants($prefs, $tenantId, $userId, $switchKey, $category, 'in_app', $perClient);
        $emailEnabled = $this->wants($prefs, $tenantId, $userId, $switchKey, $category, 'email', $perClient);
        /*
         * The recipient's own night — MAIL-013.
         *
         * This compared the window against the SERVER's clock until now, so «22:00 to 08:00» meant
         * whatever the container thought the hour was. For readers in Riyadh on a UTC host that is a
         * three-hour error in both directions. `NotificationChoices` converts through their stored
         * timezone, which is the same value the digest schedule already uses.
         */
        $inQuietHours = $userId !== null && ! $perClient
            ? $this->choices->inQuietHours($userId, $tenantId)
            : $this->inQuietHours($prefs);

        // 2) In-app: respect the user's per-category choice; if off, record suppression and stop.
        if (! $inAppEnabled) {
            $this->logDelivery($tenantId, null, 'in_app', 'suppressed_by_preference', $dedupKey);

            return null;
        }

        $notification = AppNotification::create([
            'tenant_id' => $tenantId,
            'client_workspace_id' => $p['client_workspace_id'] ?? null,
            'project_id' => $p['project_id'] ?? null,
            'user_id' => $userId,
            'type' => $type,
            'severity' => $p['severity'] ?? 'info',
            'title' => $p['title'],
            'message' => $p['message'] ?? null,
            'source' => $p['source'] ?? null,
            'entity_type' => $p['entity_type'] ?? null,
            'entity_id' => isset($p['entity_id']) ? (string) $p['entity_id'] : null,
            'action_url' => $p['action_url'] ?? null,
            'status' => 'unread',
            'dedup_key' => $dedupKey,
            // DIGESTS-VISUAL-V2-001 — the Arabic wording, when the producer wrote it, so the bell is not English under an Arabic title.
            'title_ar' => ! empty($p['title_ar']) ? (string) $p['title_ar'] : null,
            'message_ar' => ! empty($p['message_ar']) ? (string) $p['message_ar'] : null,
        ]);

        // In-app is delivered immediately (a passive inbox is not silenced by quiet hours).
        $this->logDelivery($tenantId, (string) $notification->id, 'in_app', 'sent', $dedupKey);

        /*
         * 3) Email — EMAIL-DELIVERY-LEDGER-001.
         *
         * This row said `sent` whenever a provider was configured, and nothing here sent anything. So on
         * a live install the log claimed an email for every bell notification. The row now states what
         * actually happens to it: mailed here, mailed by its own sender with its own ledger, or not
         * mailed at all. A preference or quiet hours still come first.
         */
        $emailStatus = ! $emailEnabled ? 'suppressed_by_preference'
            : ($inQuietHours ? 'suppressed_by_quiet_hours' : $this->email($notification, $tenantId, $userId, $type, $switchKey, $p));
        $this->logDelivery($tenantId, (string) $notification->id, 'email', $emailStatus, $dedupKey);

        /*
         * 4) WhatsApp — a channel a person adds on top, never one they receive by default.
         *
         * A row is written only when this person has switched THIS type on for WhatsApp, so the
         * ledger holds attempts and refusals that mean something. Nothing here reaches a vendor
         * unless a provider reports configured AND the number has an opt-in; see `WhatsAppChannel`.
         */
        if ($userId !== null && $switchKey !== null && $this->choices->whatsapp($userId, $tenantId, $switchKey)) {
            $this->logDelivery($tenantId, (string) $notification->id, 'whatsapp', $this->whatsappState($notification, $userId, $switchKey, $inQuietHours, $p), $dedupKey);
        }

        return $notification;
    }

    /**
     * What happens to the email half of one notification.
     *
     * `NotificationChannelPolicy::routeFor()` says who sends it. `dispatcher` is sent from here, through
     * `TransactionalMailer` — the one mailer, its ledger row, the unsubscribe and the daily allowance.
     * `elsewhere` has a sender of its own whose ledger is the truth. Anything else is not mailed: a
     * type nobody classified is a decision to make in the policy, not a side effect of being honest here.
     *
     * Before a message is composed, in this order: a spend-bearing message is withheld from a member
     * whose membership withholds money; a type held for a digest is held; a floor on importance is
     * honoured; and one person is mailed at most `EMAIL_CEILING_PER_HOUR` times for one type, so a rule
     * matching two hundred campaigns is a bell with two hundred items and an inbox with five.
     *
     * @param  array<string,mixed>  $p
     */
    private function email(AppNotification $notification, string $tenantId, ?int $userId, string $type, ?string $switchKey, array $p): string
    {
        if (! $this->providers->isConfigured('email')) {
            return 'awaiting_credentials';
        }

        $route = NotificationChannelPolicy::routeFor($type, isset($p['source']) ? (string) $p['source'] : null);

        if ($route === 'elsewhere') {
            return 'handled_elsewhere';
        }

        $user = $userId === null ? null : User::query()->find($userId);

        if ($route !== 'dispatcher' || $switchKey === null || $user === null || $user->email === null) {
            return 'in_app_only';
        }

        $policy = (array) NotificationChannelPolicy::for($type);

        if (($policy['spend_bearing'] ?? false) && ! $this->audience->mayReceive($user, isset($p['project_id']) ? (string) $p['project_id'] : null)) {
            return 'suppressed_client_audience';
        }

        if (($policy['digestible'] ?? false) && $this->choices->rhythm($userId, $tenantId, $switchKey) !== 'immediate') {
            return 'held_for_digest';
        }

        $severity = (string) ($notification->severity ?? 'info');
        $severity = $severity === 'error' ? 'critical' : $severity;

        if (! $this->choices->meetsImportance($userId, $tenantId, $switchKey, $severity)) {
            return 'suppressed_by_importance';
        }

        $recent = DB::table('mail_deliveries')
            ->where('user_id', $userId)->where('kind', $switchKey)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();

        if ($recent >= NotificationChannelPolicy::EMAIL_CEILING_PER_HOUR) {
            return 'suppressed_by_ceiling';
        }

        $locale = in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar';
        $title = $locale === 'ar' && ! empty($p['title_ar']) ? (string) $p['title_ar'] : (string) $notification->title;
        $detail = $locale === 'ar' && ! empty($p['message_ar']) ? (string) $p['message_ar'] : (string) ($notification->message ?? $title);
        $url = (string) ($notification->action_url ?? '');
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return app(TransactionalMailer::class)->queue(
            recipient: (string) $user->email,
            mail: new OperationalMail(
                kind: $switchKey,
                severity: $severity,
                title: $title,
                detail: $detail,
                context: 'CampaignsHub',
                lang: $locale,
                recipientName: (string) $user->name,
                path: is_string($path) && $path !== '' ? $path.(is_string($query) ? '?'.$query : '') : '/app/notifications',
                // FIN-LOOP-001 — a producer that can draw its problem passes the shape; the shell draws it.
                viz: isset($p['viz']) && is_array($p['viz']) ? $p['viz'] : null,
            ),
            kind: $switchKey,
            template: 'mail.operational',
            locale: $locale,
            tenantId: $tenantId,
            userId: $userId,
            dedupKey: 'bell:'.$notification->id,
        );
    }

    /** @param  array<string,mixed>  $p */
    private function whatsappState(AppNotification $notification, int $userId, string $switchKey, bool $inQuietHours, array $p): string
    {
        try {
            return $this->whatsapp->deliver($notification, $userId, $switchKey, $inQuietHours, isset($p['project_id']) ? (string) $p['project_id'] : null);
        } catch (Throwable $e) {
            // A vendor or a bug must not take the bell down with it; the ledger says it failed and the log says why.
            Log::warning('WhatsApp delivery failed before the vendor answered.', ['type' => $switchKey, 'error' => $e::class]);

            return 'failed';
        }
    }

    /** @param  array<string,mixed>  $p */
    private function dedupKey(string $tenantId, ?int $userId, string $type, array $p): string
    {
        return hash('sha256', implode('|', [
            $tenantId, $userId ?? 'tenant', $type,
            $p['entity_type'] ?? '', $p['entity_id'] ?? '', $p['dedup_extra'] ?? '',
        ]));
    }

    private function isDuplicate(string $tenantId, ?int $userId, string $dedupKey): bool
    {
        return AppNotification::query()
            ->where('tenant_id', $tenantId)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id'))
            ->where('dedup_key', $dedupKey)
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::DEDUP_WINDOW_MINUTES))
            ->exists();
    }

    private function logDelivery(string $tenantId, ?string $notificationId, string $channel, string $status, string $dedupKey): void
    {
        // Suppression-only rows (no notification) still need a tenant-scoped ledger entry.
        NotificationDelivery::create([
            'tenant_id' => $tenantId,
            'notification_id' => $notificationId ?? $this->placeholderNotificationId($tenantId, $dedupKey),
            'channel' => $channel,
            'status' => $status,
            'attempts' => in_array($status, ['sent', 'failed', 'retrying'], true) ? 1 : 0,
            'dedup_key' => $dedupKey,
            'last_attempt_at' => $status === 'sent' ? Carbon::now() : null,
        ]);
    }

    /**
     * A suppressed-by-preference in-app notification has no AppNotification row, but the deliveries table
     * has a NOT NULL FK. We record the suppression against a lightweight tombstone notification so the
     * delivery ledger stays complete and auditable.
     */
    private function placeholderNotificationId(string $tenantId, string $dedupKey): string
    {
        $n = AppNotification::create([
            'tenant_id' => $tenantId, 'user_id' => null, 'type' => 'suppressed', 'severity' => 'info',
            'title' => 'suppressed', 'status' => 'resolved', 'dedup_key' => $dedupKey, 'resolved_at' => Carbon::now(),
        ]);

        return (string) $n->id;
    }

    /** @return array<string,mixed>|null */
    private function preferences(string $tenantId, ?int $userId, ?string $clientWorkspaceId): ?array
    {
        if ($userId === null) {
            return null; // tenant-level notifications: no per-user preference to honor
        }
        // Prefer a per-client override row, then the user's global row.
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where(function ($q) use ($clientWorkspaceId) {
                $q->where('client_workspace_id', $clientWorkspaceId);
                if ($clientWorkspaceId !== null) {
                    $q->orWhereNull('client_workspace_id');
                }
            })
            ->orderByRaw('client_workspace_id IS NULL') // non-null (specific) first
            ->first();
        if ($row === null) {
            return null;
        }

        return [
            'channels' => $row->channels ? json_decode($row->channels, true) : null,
            'categories' => $row->categories ? json_decode($row->categories, true) : null,
            'quiet_hours' => $row->quiet_hours ? json_decode($row->quiet_hours, true) : null,
        ];
    }

    /**
     * Does this person want this message on this channel — MAIL-011.
     *
     * Two paths, and the split is not arbitrary:
     *
     * - A type the catalogue knows goes through `NotificationChoices`, so the switch a person sees on
     *   their preferences screen is the switch that decides. That is the whole point of the unit: the
     *   screen used to offer six category checkboxes that controlled far more than they named.
     * - A type it does not know keeps the older category route below. `request.journey.approved` and
     *   its siblings are generated from a state machine and cannot be listed in a catalogue, and
     *   silently reclassifying them would move somebody's existing choice without telling them.
     *
     * A tenant-wide notification has no `$userId` and therefore no preference to honour.
     *
     * `$perClient` is the third case: this person has a preference row for THIS client workspace, and
     * a per-client row is a deliberate narrowing that the per-type screen does not yet write. Honour
     * it as it has always been honoured rather than overruling a stored choice with a newer surface.
     *
     * @param  array<string,mixed>|null  $prefs
     */
    private function wants(?array $prefs, string $tenantId, ?int $userId, ?string $type, string $category, string $channel, bool $perClient): bool
    {
        if ($userId === null) {
            return true;
        }

        if (! $perClient && $type !== null && MessageCatalogue::has($type)) {
            return $this->choices->wants($userId, $tenantId, $type, $channel);
        }

        return $this->channelEnabled($prefs, $category, $channel);
    }

    /** Whether a preference row exists for this exact client workspace. */
    private function hasClientOverride(string $tenantId, ?int $userId, ?string $clientWorkspaceId): bool
    {
        if ($userId === null || $clientWorkspaceId === null) {
            return false;
        }

        return DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('client_workspace_id', $clientWorkspaceId)
            ->exists();
    }

    /** @param  array<string,mixed>|null  $prefs */
    private function channelEnabled(?array $prefs, string $category, string $channel): bool
    {
        if ($prefs === null) {
            return true; // no explicit preference → deliver
        }
        if (isset($prefs['categories'][$category][$channel])) {
            return (bool) $prefs['categories'][$category][$channel];
        }
        if (isset($prefs['channels'][$channel])) {
            return (bool) $prefs['channels'][$channel];
        }

        return true;
    }

    /**
     * The per-CLIENT fallback only.
     *
     * A per-client preference row is a narrowing the per-type screen does not write, and it carries
     * its own quiet-hours map. It has no timezone of its own, so this one still reads the process
     * clock — the case is rare, it is the behaviour that row has always had, and inventing a
     * timezone for it would be a guess rather than a fix.
     *
     * @param  array<string,mixed>|null  $prefs
     */
    private function inQuietHours(?array $prefs): bool
    {
        $qh = $prefs['quiet_hours'] ?? null;
        if (! $qh || empty($qh['enabled'])) {
            return false;
        }
        $now = Carbon::now()->format('H:i');
        $start = $qh['start'] ?? '22:00';
        $end = $qh['end'] ?? '08:00';

        // Window may wrap past midnight (e.g. 22:00 → 08:00).
        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }
}
