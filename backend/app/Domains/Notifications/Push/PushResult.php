<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

/** NOTIF-PUSH-001 — what a push service answered. `gone` is a 404 or 410: the browser dropped the subscription, so we do too. */
final class PushResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $gone = false,
        public readonly ?int $status = null,
        public readonly string $reason = '',
    ) {}
}
