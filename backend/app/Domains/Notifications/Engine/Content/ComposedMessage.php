<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Content;

/** The words of one delivery. `externalReady` is true only for a composer that carries the mandatory external parts (§8, §12, §13). */
final class ComposedMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly string $title,
        public readonly string $body,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $url = null,
        public readonly bool $externalReady = false,
        public readonly ?string $senderName = null,
        public readonly ?string $replyTo = null,
    ) {}
}
