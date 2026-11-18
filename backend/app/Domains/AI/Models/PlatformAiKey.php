<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * AI-PLATFORM-KEY-001 — the platform's own AI key, used when a workspace has none of its own.
 *
 * The PLATFORM's row, so no `BelongsToTenant` (like `ProviderConfiguration`). One row per provider; the first enabled row in
 * `ORDER` is the one used. The secret is encrypted at rest AND hidden from serialisation, and the API only ever returns
 * `last_four`. Usage on this key is the platform's cost: every reply is counted per person (`ai_messages_platform`) against the
 * person's monthly allowance, and the tokens are recorded (`ai_tokens_platform`) so the operator sees what it costs.
 */
final class PlatformAiKey extends Model
{
    use HasUuidKey;

    /** The providers this product has a client for, in the order a platform key is chosen. */
    public const ORDER = ['anthropic', 'openai', 'gemini'];

    protected $table = 'platform_ai_keys';

    protected $fillable = ['provider', 'encrypted_secret', 'last_four', 'enabled', 'configured_by'];

    protected $casts = [
        'encrypted_secret' => 'encrypted',
        'enabled' => 'boolean',
    ];

    protected $hidden = ['encrypted_secret'];

    public function setSecret(string $plain): void
    {
        $this->encrypted_secret = $plain;
        $this->last_four = substr($plain, -4);
    }

    /** Server-side only: never returned to a client. */
    public function revealSecret(): string
    {
        return (string) $this->encrypted_secret;
    }

    public function maskedKey(): string
    {
        return $this->last_four !== null ? '••••'.$this->last_four : '••••';
    }

    /** The key in use: the first enabled provider in `ORDER` that holds a secret, or null. */
    public static function active(): ?self
    {
        $rows = self::query()->where('enabled', true)->whereIn('provider', self::ORDER)->get()
            ->filter(fn (self $k): bool => $k->last_four !== null);

        foreach (self::ORDER as $provider) {
            $row = $rows->firstWhere('provider', $provider);
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }
}
