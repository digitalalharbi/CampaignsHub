<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ExternalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records client-facing notifications (email + WhatsApp) for request lifecycle events. Honest: with no
 * provider configured nothing is sent — each row is "awaiting_provider_credentials", never "sent".
 * Deduplicated per (request, event, channel) so repeats don't spam. Each row carries a secure portal deep
 * link. When a real provider is wired, dispatch happens where noted; we never fake a send.
 *
 * EMAIL-GAPS — with `requests.client_mail_enabled` on, the email row is really mailed through
 * {@see RequestMailer}, and the mail ledger writes each outcome back to the row. Off, nothing changes.
 * A mail failure cannot reach the caller: the status change or reply it follows has already happened.
 */
final class ClientNotifier
{
    public function __construct(private readonly RequestMailer $mailer) {}

    /** @var list<string> */
    private const CHANNELS = ['email', 'whatsapp'];

    public function notify(ExternalRequest $request, string $event): void
    {
        $deepLink = "/client/requests/{$request->reference}";

        foreach (self::CHANNELS as $channel) {
            $destination = $channel === 'email' ? $request->contact_email : $request->contact_phone;
            if (! $destination) {
                continue;
            }
            $providerOn = (bool) config("requests.verification.providers.{$channel}", false);
            $dedupKey = hash('sha256', implode('|', [$request->tenant_id, $request->id, $event, $channel]));

            // Idempotent per (request,event,channel) — a unique index backs this; ignore duplicates.
            $mailKey = $channel === 'email' && RequestMailer::enabled() ? 'client-request:'.$dedupKey : null;

            $recorded = DB::table('client_notifications')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'tenant_id' => $request->tenant_id,
                'request_id' => $request->id,
                'event' => $event,
                'channel' => $channel,
                'destination' => $destination,
                'status' => $providerOn ? 'queued' : 'awaiting_provider_credentials',
                'attempts' => 0,
                'dedup_key' => $dedupKey,
                'deep_link' => $deepLink,
                'mail_dedup_key' => $mailKey,
                'created_at' => now(),
            ]);

            if ($recorded > 0 && $mailKey !== null) {
                try {
                    $state = $this->mailer->toClient($request, $event, (string) $destination, $deepLink, $dedupKey);

                    // Waiting for a worker is what this row should say until the ledger writes the outcome back.
                    if ($state === 'queued') {
                        DB::table('client_notifications')->where('mail_dedup_key', $mailKey)->update(['status' => 'queued']);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /** Map a request status key to a lifecycle notification event (client-meaningful ones only). */
    public function eventForStatus(string $statusKey): ?string
    {
        return match ($statusKey) {
            'approved', 'qualified' => 'approved',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'under_review' => 'status_changed',
            default => null,
        };
    }
}
