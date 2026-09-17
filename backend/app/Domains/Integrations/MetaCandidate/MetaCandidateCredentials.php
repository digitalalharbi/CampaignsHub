<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use Illuminate\Support\Carbon;

/**
 * META-CANDIDATE-001 — the Candidate Meta app's credentials, through the SAME mechanism as Live.
 *
 * Stored encrypted in `provider_configurations`, in its own row (`meta.candidate`), and falling back to
 * the `META_CANDIDATE_*` environment keys exactly as the Live row falls back to `META_ADS_*`. It never
 * falls back to a Live value: a candidate with no App ID is an unconfigured candidate, not the Live app
 * under another name — which is the whole point of testing it.
 *
 * As with the provider console, nothing here returns a stored value to an interface. `summary()` carries
 * presence, source and a four-character hint.
 */
final class MetaCandidateCredentials
{
    /** client_id = App ID, client_secret = App Secret, config_id = FLfB Configuration ID. */
    public const KEYS = ['client_id', 'client_secret', 'config_id'];

    public const SECRET_KEYS = ['client_secret'];

    /** The candidate asks for read access and nothing else unless the operator says otherwise. */
    public const DEFAULT_SCOPES = ['ads_read'];

    /** @return array<string, string|null> */
    public function values(): array
    {
        $row = $this->row();
        $env = $this->environmentValues();
        $values = [];

        foreach (self::KEYS as $key) {
            $values[$key] = $row?->secretValue($key) ?? $env[$key];
        }

        return $values;
    }

    public function value(string $key): ?string
    {
        return $this->values()[$key] ?? null;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $stored = $this->row()?->scopes;

        if (is_array($stored) && $stored !== []) {
            return array_values(array_map('strval', $stored));
        }

        $env = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('ad_platforms.meta_candidate.scopes', '')),
        ), static fn (string $s) => $s !== ''));

        return $env !== [] ? $env : self::DEFAULT_SCOPES;
    }

    /** @return list<string> */
    public function missing(): array
    {
        $values = $this->values();

        return array_values(array_filter(self::KEYS, static fn (string $k) => ($values[$k] ?? null) === null));
    }

    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }

    /**
     * A one-way fingerprint of the exact credentials a test ran with.
     *
     * Keyed with the application key so it cannot be used to confirm a guessed secret. A test result
     * only licenses a promotion of the credentials it actually proved — change the secret or the
     * configuration afterwards and the fingerprint no longer matches.
     */
    public function fingerprint(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $values = $this->values();

        return hash_hmac('sha256', implode("\n", [
            $values['client_id'], $values['client_secret'], $values['config_id'], implode(',', $this->scopes()),
        ]), (string) config('app.key'));
    }

    /**
     * Partial write, as the provider console does: an absent or blank field is left alone.
     *
     * @param  array<string, string|null>  $values
     * @param  list<string>|null  $scopes  null = leave alone, [] = back to the default
     * @return list<string> the field names that changed — the audit record's whole content
     */
    public function save(array $values, ?array $scopes = null, ?int $actorId = null): array
    {
        $row = $this->rowOrNew();
        $credentials = $row->credentials ?? [];
        $changed = [];

        foreach (self::KEYS as $key) {
            $incoming = isset($values[$key]) && is_string($values[$key]) ? trim($values[$key]) : '';

            if ($incoming !== '' && ($credentials[$key] ?? null) !== $incoming) {
                $credentials[$key] = $incoming;
                $changed[] = $key;
            }
        }

        if ($scopes !== null) {
            $normalised = array_values(array_unique(array_filter(array_map('trim', $scopes), static fn ($s) => $s !== '')));
            $new = $normalised === [] ? null : $normalised;

            if ($new !== $row->scopes) {
                $row->scopes = $new;
                $changed[] = 'scopes';
            }
        }

        $row->credentials = $credentials;

        if ($changed !== []) {
            $row->configured_at = Carbon::now();
            $row->configured_by = $actorId;
        }

        $row->save();
        $this->forgetCache();

        return $changed;
    }

    public function forget(string $key): bool
    {
        $row = $this->row();

        if ($row === null || $row->secretValue($key) === null) {
            return false;
        }

        $credentials = $row->credentials ?? [];
        unset($credentials[$key]);
        $row->credentials = $credentials;
        $row->save();
        $this->forgetCache();

        return true;
    }

    /** @return array<string, mixed> no value can reach this array */
    public function summary(): array
    {
        $row = $this->row();
        $env = $this->environmentValues();
        $values = $this->values();

        return [
            'profile' => MetaCredentialProfile::Candidate->value,
            'configured' => $this->isConfigured(),
            'missing' => $this->missing(),
            'effective_scopes' => $this->scopes(),
            // The SAME callback the Live app uses; the profile travels in the verified state.
            'redirect_uri' => ProviderCatalogue::get('meta')->redirectUri(),
            'values' => array_map(static fn (string $key) => [
                'key' => $key,
                'secret' => in_array($key, self::SECRET_KEYS, true),
                'present' => ($values[$key] ?? null) !== null,
                'source' => $row?->secretValue($key) !== null ? 'stored' : ($env[$key] !== null ? 'environment' : null),
                'hint' => ($values[$key] ?? null) === null ? null : mb_substr((string) $values[$key], -4),
            ], self::KEYS),
            'configured_at' => $row?->configured_at?->toIso8601String(),
        ];
    }

    /** Per-request memo; the binding is scoped, so a worker never answers with yesterday's keys. */
    private ?ProviderConfiguration $row = null;

    private bool $loaded = false;

    public function forgetCache(): void
    {
        $this->row = null;
        $this->loaded = false;
    }

    private function row(): ?ProviderConfiguration
    {
        if (! $this->loaded) {
            $this->row = ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Candidate->storageKey())->first();
            $this->loaded = true;
        }

        return $this->row;
    }

    private function rowOrNew(): ProviderConfiguration
    {
        return $this->row() ?? new ProviderConfiguration([
            'provider' => MetaCredentialProfile::Candidate->storageKey(),
            'environment' => 'production',
            // Not a provider anybody may connect through; `enabled` is simply not consulted for it.
            'enabled' => false,
        ]);
    }

    /** @return array<string, string|null> */
    private function environmentValues(): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $value = config("ad_platforms.meta_candidate.{$key}");
            $out[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $out;
    }
}
