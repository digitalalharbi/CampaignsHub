<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\EntitlementMode;
use Illuminate\Support\Carbon;

/** How one workspace holds one key right now, with the limits that apply (spec §1). */
final class EntitlementState
{
    /** @param array<string, int|null> $limits null = unlimited */
    public function __construct(
        public readonly EntitlementKey $key,
        public readonly EntitlementMode $mode,
        public readonly array $limits = [],
        public readonly ?Carbon $graceEndsAt = null,
    ) {}

    public function allows(): bool
    {
        return $this->mode->allowsUse();
    }

    /** Settings of a key in view-only (or off) mode are shown, never edited (spec §1). */
    public function canEdit(): bool
    {
        return $this->mode->allowsUse();
    }

    public function upgradeRequired(): bool
    {
        return ! $this->mode->allowsUse() || $this->mode === EntitlementMode::Grace;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'mode' => $this->mode->value,
            'allowed' => $this->allows(),
            'can_edit' => $this->canEdit(),
            'upgrade' => $this->upgradeRequired(),
            'grace_ends_at' => $this->graceEndsAt?->toIso8601String(),
            'limits' => $this->limits,
        ];
    }
}
