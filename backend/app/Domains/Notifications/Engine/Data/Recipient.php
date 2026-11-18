<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use App\Domains\Notifications\Engine\Enums\RecipientKind;

/** Who one delivery is for: a workspace member, or an external recipient row (spec §3, §9). */
final class Recipient
{
    public function __construct(
        public readonly RecipientKind $kind,
        public readonly ?int $userId,
        public readonly ?string $externalId,
        public readonly ?string $address,
        public readonly string $locale,
        public readonly string $roleKey,
    ) {}

    public static function user(int $userId, ?string $email, string $locale = 'ar', string $roleKey = 'other'): self
    {
        return new self(RecipientKind::User, $userId, null, $email, $locale, $roleKey);
    }

    public static function external(string $id, string $email, string $locale = 'ar', string $roleKey = 'other'): self
    {
        return new self(RecipientKind::External, null, $id, $email, $locale, $roleKey);
    }

    public function identity(): string
    {
        return $this->kind === RecipientKind::User ? 'u:'.$this->userId : 'x:'.$this->externalId;
    }
}
