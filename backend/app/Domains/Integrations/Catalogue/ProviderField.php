<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * PROVCFG-001 — one value the platform operator has to obtain from a provider's developer console.
 *
 * A field is described rather than merely named because the person filling it in is looking at two
 * consoles at once. `where` says which screen of the PROVIDER's console holds it — that sentence is
 * the difference between a form somebody can complete and one they abandon.
 *
 * `secret` is not cosmetic. It decides three separate behaviours: the value is stored inside the
 * encrypted payload, it is never returned to any client, and the API answers with a masked hint
 * instead. A field marked non-secret is echoed back in full, so getting this wrong leaks a key.
 */
final class ProviderField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $labelAr,
        public readonly bool $secret,
        public readonly bool $required,
        public readonly string $where,
        public readonly string $whereAr,
    ) {}

    public static function secret(string $key, string $label, string $labelAr, string $where, string $whereAr, bool $required = true): self
    {
        return new self($key, $label, $labelAr, true, $required, $where, $whereAr);
    }

    public static function plain(string $key, string $label, string $labelAr, string $where, string $whereAr, bool $required = true): self
    {
        return new self($key, $label, $labelAr, false, $required, $where, $whereAr);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'label_ar' => $this->labelAr,
            'secret' => $this->secret,
            'required' => $this->required,
            'where' => $this->where,
            'where_ar' => $this->whereAr,
        ];
    }
}
