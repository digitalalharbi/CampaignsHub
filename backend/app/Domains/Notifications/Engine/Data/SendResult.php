<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use Illuminate\Support\Carbon;

/** What a channel Sender reports. `accepted` is true ONLY when the provider accepted the message (spec §4). */
final class SendResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId,
        public readonly ?Carbon $acceptedAt,
        public readonly bool $permanent,
        public readonly ?string $reason,
        public readonly bool $skipped = false,
    ) {}

    public static function accepted(?string $providerMessageId = null, ?Carbon $at = null): self
    {
        return new self(true, $providerMessageId, $at ?? Carbon::now(), false, null);
    }

    public static function failed(string $reason, bool $permanent = false): self
    {
        return new self(false, null, null, $permanent, mb_substr($reason, 0, 120));
    }

    /** Nothing to send on this channel for this recipient (no device, no address): logged as skipped, never retried. */
    public static function skipped(string $reason): self
    {
        return new self(false, null, null, true, $reason, true);
    }
}
