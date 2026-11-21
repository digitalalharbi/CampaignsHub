<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Models\User;
use App\Support\PhoneNumber;

/**
 * Withdrawing WhatsApp consent — NOTIFICATION-CHANNELS-001.
 *
 * Two doors, one effect: a person switches it off in settings (`WhatsAppOptInController`), or replies
 * STOP to a message and the vendor's inbound webhook hands that reply to `handleInbound()`.
 *
 * The inbound route is NOT registered. A webhook has to prove it came from the vendor (Twilio signs each
 * request with the Auth Token), and a route that trusted an unsigned POST would let anybody withdraw
 * anybody's consent by guessing a number. This class is the decision; wiring and signature checking land
 * with the credentials, and `docs/` records that as the open follow-up.
 */
final class WhatsAppOptOut
{
    /** What a person may type to stop, case-insensitively. The first four are the vendor's own keywords. */
    private const STOP_WORDS = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit', 'ايقاف', 'إيقاف', 'الغاء', 'إلغاء'];

    public static function isStop(string $body): bool
    {
        return in_array(mb_strtolower(trim($body)), self::STOP_WORDS, true);
    }

    /**
     * @param  string  $from  the sender as the vendor reports it, with or without the `whatsapp:` prefix
     * @return int how many people were opted out
     */
    public function handleInbound(string $from, string $body): int
    {
        if (! self::isStop($body)) {
            return 0;
        }

        $e164 = PhoneNumber::normalise(preg_replace('/^whatsapp:/i', '', trim($from)) ?? '');

        return $e164 === null ? 0 : $this->optOutNumber($e164);
    }

    public function optOutNumber(string $e164): int
    {
        return User::query()->where('whatsapp_number', $e164)->whereNotNull('whatsapp_opt_in_at')->update(['whatsapp_opted_out_at' => now()]);
    }
}
