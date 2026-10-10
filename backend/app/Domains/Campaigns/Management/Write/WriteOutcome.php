<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

/**
 * What the PROVIDER said, not what CampaignsHub hoped.
 *
 * `ok` is decided by the provider's own success contract (`PlatformHttp::succeeded` plus any
 * per-item status the provider reports inside a 200). `message` is the provider's text, verbatim
 * up to the storage ceiling, so the operator reads the platform's reason rather than a paraphrase.
 * `mirror` is what may be written to the local copy because the provider confirmed it.
 */
final class WriteOutcome
{
    /** @param  array<string, mixed>  $mirror */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $message = null,
        public readonly ?string $requestId = null,
        public readonly ?string $newExternalId = null,
        public readonly array $mirror = [],
        public readonly int $httpStatus = 200,
    ) {}

    /** @param  array<string, mixed>  $mirror */
    public static function confirmed(array $mirror = [], ?string $requestId = null, ?string $newExternalId = null): self
    {
        return new self(true, null, $requestId, $newExternalId, $mirror);
    }

    public static function refused(string $message, ?string $requestId = null, int $httpStatus = 422): self
    {
        return new self(false, $message, $requestId, null, [], $httpStatus);
    }
}
