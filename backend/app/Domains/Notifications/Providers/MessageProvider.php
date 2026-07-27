<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Providers;

/**
 * A delivery adapter for one channel (email / whatsapp / sms). The rest of the app never talks to a concrete
 * provider — it asks the registry for the channel's adapter. A real provider is plugged in by binding a
 * configured implementation; until then the Null adapter reports "not configured" and nothing is sent.
 */
interface MessageProvider
{
    public function channel(): string;

    /** True only when real credentials are wired. When false, callers record awaiting_provider_credentials. */
    public function isConfigured(): bool;

    /**
     * Attempt a send. Implementations MUST return a documented result and never claim success without a
     * confirmed provider acknowledgement.
     *
     * @param  array<string,mixed>  $payload
     * @return array{status: string, provider_message_id?: string|null, error?: string|null}
     *                                                                                       status ∈ sent|failed|retrying|awaiting_credentials
     */
    public function send(string $destination, array $payload): array;
}
