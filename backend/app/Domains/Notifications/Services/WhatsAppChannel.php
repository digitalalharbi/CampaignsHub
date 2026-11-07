<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\NotificationChannelPolicy;
use App\Domains\Notifications\Support\WhatsAppTemplates;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The WhatsApp half of one notification — NOTIFICATION-CHANNELS-001. Prepared, not activated.
 *
 * Owner, 2026-11-06: «لاحقًا على الواتساب». Everything that decides whether a message may go is here and
 * tested; the one thing missing is credentials, and while they are missing the answer is
 * `awaiting_credentials` and no connection is opened.
 *
 * ## The refusals, in the order they are asked, and why that order
 *
 * 1. `not_offered` — the policy says this type never goes to WhatsApp. A stored switch cannot override it.
 * 2. `no_number` — the person has given no WhatsApp number.
 * 3. `no_opt_in` — a number without a PROVED opt-in. Business-initiated WhatsApp needs consent.
 * 4. `opted_out` — they withdrew, or replied STOP, after opting in. Consent is a state, not an event.
 * 5. `suppressed_client_audience` — it names a campaign or an amount and their membership withholds money.
 * 6. `awaiting_credentials` — no provider reports configured.
 * 7. `suppressed_by_quiet_hours` — the same window email honours.
 * 8. `template_missing` — no approved template for this type in the person's language. Never free text.
 * 9. `rate_limited` — at most `PER_HOUR` WhatsApp messages to one person an hour; the rest stay in the bell.
 *
 * Consent is checked BEFORE credentials so that, the day credentials arrive, the ledger already says
 * which people were never reachable and why — rather than every row turning from one refusal into
 * another.
 *
 * The state written is the ledger's vocabulary (`notification_deliveries.status`): `sent` only when the
 * vendor accepted the message, `retrying`/`failed` otherwise.
 */
final class WhatsAppChannel
{
    /** WhatsApp messages to one person in an hour, across every type. */
    public const PER_HOUR = 10;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SpendAudience $audience,
    ) {}

    public function deliver(AppNotification $notification, int $userId, string $type, bool $inQuietHours, ?string $projectId): string
    {
        $policy = NotificationChannelPolicy::for($type);

        if ($policy === null || $policy['whatsapp'] !== 'planned') {
            return 'not_offered';
        }

        $user = User::query()->find($userId);

        if ($user === null || trim((string) $user->whatsapp_number) === '') {
            return 'no_number';
        }

        if ($user->whatsapp_opt_in_at === null) {
            return 'no_opt_in';
        }

        if ($user->whatsapp_opted_out_at !== null && $user->whatsapp_opted_out_at >= $user->whatsapp_opt_in_at) {
            return 'opted_out';
        }

        if ($policy['spend_bearing'] && ! $this->audience->mayReceive($user, $projectId)) {
            return 'suppressed_client_audience';
        }

        $provider = $this->providers->for('whatsapp');

        if (! $provider->isConfigured()) {
            return 'awaiting_credentials';
        }

        if ($inQuietHours) {
            return 'suppressed_by_quiet_hours';
        }

        $template = WhatsAppTemplates::for($type);
        $locale = in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar';
        $sid = $template === null ? null : WhatsAppTemplates::contentSid($template['name'], $locale);

        if ($sid === null) {
            return 'template_missing';
        }

        $key = 'whatsapp:'.$userId;

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return 'rate_limited';
        }

        RateLimiter::hit($key, 3600);

        $result = $provider->send((string) $user->whatsapp_number, [
            'content_sid' => $sid,
            'variables' => WhatsAppTemplates::variables([
                'title' => (string) $notification->title,
                'detail' => (string) ($notification->message ?? $notification->title),
            ]),
        ]);

        return in_array($result['status'], ['sent', 'failed', 'retrying', 'awaiting_credentials'], true) ? $result['status'] : 'failed';
    }
}
