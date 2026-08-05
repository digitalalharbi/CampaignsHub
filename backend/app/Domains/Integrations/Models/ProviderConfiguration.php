<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * PROVCFG-001 — the stored half of one provider's system configuration.
 *
 * Note the absence of `BelongsToTenant`: this row is the PLATFORM's, and a tenant scope on it would
 * mean each workspace configuring its own developer app. See the migration for the full reasoning.
 *
 * `credentials` is hidden AND cast encrypted, which are two different protections doing two different
 * jobs — the cast keeps it ciphertext at rest, `$hidden` keeps a careless `->toArray()` from putting
 * the decrypted values in a response. Neither is sufficient alone, and the API layer adds a third:
 * it never serialises this model, only `ProviderConfigurationService`'s own summary.
 */
final class ProviderConfiguration extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'provider', 'environment', 'credentials', 'scopes', 'enabled',
        'last_tested_at', 'last_test_status', 'last_test_message',
        'last_rotated_at', 'configured_at', 'configured_by',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'scopes' => 'array',
        'enabled' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_rotated_at' => 'datetime',
        'configured_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    /**
     * One stored value, or null when it was never set.
     *
     * An empty string is treated as absent on purpose. A form that posts `client_secret=""` means
     * "I left this alone", and storing it would turn a working configuration into one that
     * authenticates with a blank secret and fails at the provider with a message about nothing.
     */
    public function secretValue(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> which of the provider's fields currently hold a value */
    public function presentKeys(): array
    {
        return array_values(array_filter(
            array_map(static fn ($f) => $f->key, ProviderCatalogue::get($this->provider)->fields),
            fn (string $key) => $this->secretValue($key) !== null,
        ));
    }

    /**
     * The last four characters of a stored value — enough for an operator to recognise which key is
     * in place without the value being recoverable from it.
     */
    public function hint(string $key): ?string
    {
        $value = $this->secretValue($key);

        return $value === null ? null : mb_substr($value, -4);
    }
}
