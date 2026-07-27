<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /** Notification type → preference category. */
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
        $dedupKey = $this->dedupKey($tenantId, $userId, $type, $p);

        // 1) Dedup — an identical notification within the window is collapsed.
        if ($this->isDuplicate($tenantId, $userId, $dedupKey)) {
            return null;
        }

        $prefs = $this->preferences($tenantId, $userId, $p['client_workspace_id'] ?? null);
        $inAppEnabled = $this->channelEnabled($prefs, $category, 'in_app');
        $emailEnabled = $this->channelEnabled($prefs, $category, 'email');
        $inQuietHours = $this->inQuietHours($prefs);

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
        ]);

        // In-app is delivered immediately (a passive inbox is not silenced by quiet hours).
        $this->logDelivery($tenantId, (string) $notification->id, 'in_app', 'sent', $dedupKey);

        // 3) Email delivery state (never "sent" — no provider yet).
        $emailStatus = ! $emailEnabled ? 'suppressed_by_preference'
            : ($inQuietHours ? 'suppressed_by_quiet_hours' : 'awaiting_credentials');
        $this->logDelivery($tenantId, (string) $notification->id, 'email', $emailStatus, $dedupKey);

        return $notification;
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

    /** @param  array<string,mixed>|null  $prefs */
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
