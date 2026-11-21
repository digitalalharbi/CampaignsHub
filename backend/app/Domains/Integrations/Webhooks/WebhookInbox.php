<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Catalogue\WebhookSupport;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * INTEG-WEBHOOK-ENGINE-001 — the inbox's two write paths that are not «a verified delivery».
 *
 * ## A refused delivery is recorded, but its body is not
 *
 * Until now an unverified delivery left no trace at all, which made a wrong secret, a mis-pointed
 * subscription and a probe of the public URL indistinguishable from silence. The row says WHAT was
 * decided (provider, verdict, time, a hash of the body, the correlation id) and nothing the sender
 * chose: no payload, no header, no signature, no query string. The table a consumer reads as trusted
 * therefore still never holds attacker-controlled content.
 *
 * ## The fingerprint can never collide with a genuine event's
 *
 * Verified events are unique on `(provider, sha256(event id or body))`. If a rejected row used the
 * same fingerprint, anyone who guessed an event id could post it with a bad signature and make the
 * genuine delivery of that id look like a duplicate. Rejected rows hash a `rejected|verdict|` prefix
 * into their own space, and a repeat of the same refused body counts up instead of adding rows.
 *
 * ## Bounded
 *
 * The endpoint is public. Past a per-provider hourly cap the refusal is counted in the log instead of
 * stored, so a flood cannot become a storage problem.
 */
final class WebhookInbox
{
    public function rejected(
        ProviderDefinition $definition,
        string $rawBody,
        string $verdict,
        string $reason,
        bool $signatureVerified = false,
        ?Carbon $eventTime = null,
    ): ?IntegrationWebhookEvent {
        $fingerprint = hash('sha256', 'rejected|'.$verdict.'|'.$rawBody);

        $existing = IntegrationWebhookEvent::query()
            ->where('provider', $definition->key)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'duplicate_count' => $existing->duplicate_count + 1,
                'last_duplicate_at' => Carbon::now(),
            ])->save();

            return $existing;
        }

        $cap = (int) config('integrations.webhooks.rejected_record_cap_per_hour', 500);
        $recent = IntegrationWebhookEvent::query()
            ->where('provider', $definition->key)
            ->where('status', IntegrationWebhookEvent::REJECTED)
            ->where('received_at', '>=', Carbon::now()->subHour())
            ->count();

        if ($recent >= $cap) {
            Log::warning('webhook.rejected_flood_not_recorded', ['provider' => $definition->key, 'verdict' => $verdict]);

            return null;
        }

        try {
            return DB::transaction(static fn () => IntegrationWebhookEvent::create([
                'provider' => $definition->key,
                'kind' => $definition->kind->value,
                'fingerprint' => $fingerprint,
                'payload' => null,
                'signature_verified' => $signatureVerified,
                'status' => IntegrationWebhookEvent::REJECTED,
                'verdict' => $verdict,
                'source' => 'webhook',
                'error' => $reason,
                'correlation_id' => self::correlationId(),
                'event_time' => $eventTime,
                'received_at' => Carbon::now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null; // a concurrent identical refusal won the insert; the count is not worth a retry
        }
    }

    /** Whether replay protection applies: only where the provider's signature covers the body that carries the time. */
    public function timestampIsSigned(ProviderDefinition $definition): bool
    {
        return $definition->webhooks === WebhookSupport::Supported;
    }

    /** @param array<string,mixed> $payload the verified payload */
    public function staleReason(ProviderDefinition $definition, array $payload): ?string
    {
        if (! $this->timestampIsSigned($definition)) {
            return null;
        }

        $time = WebhookEventFacts::eventTime($payload);

        if ($time === null) {
            return null; // nothing signed to judge by; dedup still applies
        }

        $age = $time->diffInSeconds(Carbon::now(), false);

        if ($age > (int) config('integrations.webhooks.replay_tolerance_seconds', 172800)) {
            return 'The delivery is older than the replay window.';
        }

        // A timestamp with no zone (Salla's «Y-m-d H:i:s» may be the store's local time) can be up to fourteen
        // hours «ahead» of UTC without being wrong, so only a stamp that says its zone is held to the tight skew.
        $skew = (int) config('integrations.webhooks.future_skew_seconds', 900) + (WebhookEventFacts::hasZone($payload) ? 0 : 14 * 3600);

        if (-$age > $skew) {
            return 'The delivery is stamped in the future.';
        }

        return null;
    }

    private static function correlationId(): string
    {
        $id = request()->attributes->get('correlation_id');

        return is_string($id) && $id !== '' ? mb_substr($id, 0, 64) : (string) Str::uuid();
    }
}
