<?php

declare(strict_types=1);

namespace App\Domains\Tracking\Services;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Models\TrackingEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * TRACKING-EVENTS-001 — the one way a measured interaction is written down.
 *
 * Four rules, enforced here so no caller can forget one:
 *
 *  1. NOTHING IS RECORDED unless the workspace has switched `advanced_tracking` on for the project in question.
 *     The module is off by default; a workspace that never activated it has no new rows and loses nothing.
 *  2. A person's identifier is kept only with consent. Without it the row keeps a hash salted with the day, which
 *     separates two opens on one day and cannot be followed to the next.
 *  3. No IP, no user agent and no full URL is stored; see the migration.
 *  4. Recording can never break the thing being measured. A failure is logged and swallowed: a redirect that
 *     fails because its counter failed is worse than a counter that missed one.
 */
final class TrackingRecorder
{
    public const MODULE = 'advanced_tracking';

    public function __construct(private readonly Capabilities $capabilities) {}

    /** Whether tracking is on for this workspace and project. Cached briefly; switching a module drops the cache. */
    public function enabled(string $tenantId, ?string $projectId): bool
    {
        $key = 'tracking-enabled:'.$this->capabilities->version($tenantId).':'.$tenantId.':'.($projectId ?? '-');

        return (bool) Cache::remember($key, 60, function () use ($tenantId, $projectId): bool {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                return false;
            }
            $clientId = null;
            if ($projectId !== null) {
                $clientId = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($projectId)->value('client_workspace_id');
            }

            return $this->capabilities->allows(self::MODULE, $tenant, $clientId === null ? null : (string) $clientId, $projectId);
        });
    }

    /**
     * @param  array<string,mixed>  $event  tenant_id, project_id?, event_type, subject_type, subject_id, source, destination_type?,
     *                                      destination_host?, occurred_at?, consent_state?, visitor_key?, utm_*, dedupe_key?
     */
    public function record(array $event, ?Request $request = null): ?TrackingEvent
    {
        $type = (string) ($event['event_type'] ?? '');
        if (! in_array($type, TrackingEvent::TYPES, true)) {
            throw new InvalidArgumentException("Unknown tracking event type: {$type}");
        }
        foreach (['tenant_id', 'subject_type', 'subject_id', 'source'] as $required) {
            if (($event[$required] ?? '') === '') {
                throw new InvalidArgumentException("A tracking event needs {$required}.");
            }
        }

        $tenantId = (string) $event['tenant_id'];
        $projectId = isset($event['project_id']) ? (string) $event['project_id'] : null;

        if (! $this->enabled($tenantId, $projectId)) {
            return null;
        }

        try {
            return $this->write($event, $tenantId, $projectId, $type, $request);
        } catch (Throwable $e) {
            Log::warning('tracking event not recorded', ['type' => $type, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    /** @param array<string,mixed> $event */
    private function write(array $event, string $tenantId, ?string $projectId, string $type, ?Request $request): TrackingEvent
    {
        $consent = (string) ($event['consent_state'] ?? 'unknown');
        if (! in_array($consent, ['granted', 'not_required', 'denied', 'unknown'], true)) {
            $consent = 'unknown';
        }

        $dedupe = isset($event['dedupe_key']) ? substr((string) $event['dedupe_key'], 0, 128) : null;
        if ($dedupe !== null) {
            $existing = TrackingEvent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('dedupe_key', $dedupe)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $bot = $request === null ? ['is_bot' => false, 'reason' => null, 'family' => 'none'] : BotClassifier::classify($request->userAgent());
        $at = isset($event['occurred_at']) ? Carbon::parse($event['occurred_at']) : Carbon::now();

        $visitorKey = $consent === 'granted' && ($event['visitor_key'] ?? null) !== null ? substr((string) $event['visitor_key'], 0, 64) : null;
        $hash = $request === null || $visitorKey !== null ? null : $this->dailyHash($tenantId, $request, $at);
        $confidence = $visitorKey !== null ? 'consented_id' : ($hash !== null ? 'daily_hash' : 'none');

        return TrackingEvent::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'event_type' => $type,
            'subject_type' => (string) $event['subject_type'],
            'subject_id' => (string) $event['subject_id'],
            'source' => (string) $event['source'],
            'destination_type' => $event['destination_type'] ?? null,
            'destination_host' => isset($event['destination_host']) ? substr(mb_strtolower((string) $event['destination_host']), 0, 190) : null,
            'occurred_at' => $at,
            'recorded_at' => Carbon::now(),
            'consent_state' => $consent,
            'visitor_key' => $visitorKey,
            'visitor_hash' => $hash,
            'visitor_confidence' => $confidence,
            'is_bot' => $bot['is_bot'],
            'bot_reason' => $bot['reason'],
            'ua_family' => $bot['family'],
            'utm_source' => $event['utm_source'] ?? null,
            'utm_medium' => $event['utm_medium'] ?? null,
            'utm_campaign' => $event['utm_campaign'] ?? null,
            'utm_content' => $event['utm_content'] ?? null,
            'dedupe_key' => $dedupe,
            'session_id' => isset($event['session_id']) ? substr((string) $event['session_id'], 0, 64) : null,
            'lead_id' => $event['lead_id'] ?? null,
            'step' => isset($event['step']) ? (int) $event['step'] : null,
            'click_id_type' => isset($event['click_id_type']) ? substr((string) $event['click_id_type'], 0, 16) : null,
        ]);
    }

    /**
     * A hash of (day, workspace, IP, user agent), keyed with the application secret.
     *
     * It answers one question — «were these two opens on the same day probably the same device?» — and no other.
     * The day is part of the input, so the same device hashes differently tomorrow and the value cannot be used to
     * follow anyone. It is not an identifier, and it is never described as one.
     */
    private function dailyHash(string $tenantId, Request $request, Carbon $at): ?string
    {
        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();
        if ($ip === '' && $ua === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $at->copy()->utc()->toDateString().'|'.$tenantId.'|'.$ip.'|'.$ua, (string) config('app.key')), 0, 40);
    }
}
