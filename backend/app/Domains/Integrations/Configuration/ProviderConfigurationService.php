<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Configuration;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Enums\ProviderSetupState;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;

/**
 * PROVCFG-001 — the one place that answers "what is this install configured to do with this provider".
 *
 * Everything downstream asks this object rather than reading config or the table: the connectors, the
 * OAuth controller, the admin console, the customer-facing integrations page and the sync scheduler.
 * One definition of configured, and it cannot drift into a laxer one somewhere convenient.
 *
 * ## Database over environment, and why the fallback exists at all
 *
 * A stored value always wins over the matching `.env` entry. The environment fallback is kept for two
 * honest reasons and no others: a developer running the stack locally, and CI, where nobody is going
 * to open an admin console to fill in a form. It is never a way to configure production behind the
 * operator's back — the console shows WHICH source each value came from, so a value nobody typed into
 * it is visible as such.
 *
 * ## The read path never decrypts more than it needs, and never leaks what it decrypts
 *
 * `values()` returns plaintext and is called only by the OAuth and API layers. Everything an interface
 * sees goes through `summary()`, which returns presence and a four-character hint and has no branch
 * that can emit a value. There is deliberately no `revealAll()`: a console able to display a client
 * secret is a console whose compromise hands over every customer's ad accounts, and an operator who
 * has lost a secret must rotate it at the provider, which is the only safe answer anyway.
 */
final class ProviderConfigurationService
{
    /** @var array<string, ProviderConfiguration|null> */
    private array $rows = [];

    /**
     * Every credential value for a provider, database first, environment second.
     *
     * @return array<string, string|null> keyed by the catalogue's field keys
     */
    public function values(string $provider): array
    {
        $definition = ProviderCatalogue::get($provider);
        $row = $this->row($definition->key);
        $fallback = $this->environmentValues($definition);

        $values = [];

        foreach ($definition->fields as $field) {
            $values[$field->key] = $row?->secretValue($field->key) ?? $fallback[$field->key] ?? null;
        }

        return $values;
    }

    public function value(string $provider, string $key): ?string
    {
        return $this->values($provider)[$key] ?? null;
    }

    /** @return list<string> the scopes to ask for — an operator override, or the catalogue's default */
    public function scopes(string $provider): array
    {
        $definition = ProviderCatalogue::get($provider);
        $override = $this->row($definition->key)?->scopes;

        return is_array($override) && $override !== [] ? array_values($override) : $definition->scopes;
    }

    /**
     * Which required values are absent — the exact list the setup screen shows.
     *
     * Names rather than a boolean, because «awaiting credentials» is not actionable and
     * «ينقص: developer_token» is.
     *
     * @return list<string>
     */
    public function missing(string $provider): array
    {
        $values = $this->values($provider);

        return array_values(array_filter(
            ProviderCatalogue::get($provider)->requiredKeys(),
            static fn (string $key) => ($values[$key] ?? null) === null,
        ));
    }

    public function isConfigured(string $provider): bool
    {
        return $this->missing($provider) === [];
    }

    /**
     * Whether a workspace may be offered this provider at all.
     *
     * Both halves matter. A disabled provider is one the operator has deliberately taken out of
     * service and must not be connectable however complete its keys are; a provider whose setup has
     * never made a successful round trip must not be offered as production-ready. Disabling does NOT
     * touch a single stored credential or an existing connection — see `setEnabled()`.
     */
    public function isConnectable(string $provider): bool
    {
        return $this->isEnabled($provider) && $this->state($provider)->allowsConnecting();
    }

    public function isEnabled(string $provider): bool
    {
        return $this->row(ProviderCatalogue::get($provider)->key)?->enabled ?? true;
    }

    public function environment(string $provider): string
    {
        return $this->row(ProviderCatalogue::get($provider)->key)?->environment ?? 'sandbox';
    }

    /**
     * The five-state answer.
     *
     * Read the order carefully: a failed test outranks a complete key set, because a configuration
     * that was refused by the provider is worse than one nobody has tried yet — it is known broken,
     * and reporting it as `ready_to_connect` would send a customer into a flow that has already failed.
     */
    public function state(string $provider): ProviderSetupState
    {
        $definition = ProviderCatalogue::get($provider);
        $row = $this->row($definition->key);
        $missing = $this->missing($definition->key);
        $present = count($definition->requiredKeys()) - count($missing);

        if ($missing !== []) {
            return $present === 0
                ? ProviderSetupState::NotConfigured
                : ProviderSetupState::AwaitingCredentials;
        }

        if ($row?->last_test_status === 'failed') {
            return ProviderSetupState::ConfigurationError;
        }

        /*
         * The verdict no longer consults `environment` — ENV-FAKE-001.
         *
         * It used to require `environment === 'production'`, which was the only thing that column
         * ever decided. It changes no authorize URL, no token URL and no API base for any of the
         * eight providers, because none of them has a separate sandbox host wired here. So the
         * Sandbox/Production control was decoration whose single effect was gating an overclaim —
         * and with that overclaim gone (PROBE-CLAIM-001) it had no behaviour left at all.
         *
         * The column is kept and still written: production rows hold a value, and dropping it would
         * be a migration nobody needs. It simply stops deciding anything, and the interface stops
         * offering a switch that does nothing.
         */
        return $row?->last_test_status === 'passed'
            ? ProviderSetupState::CredentialsVerified
            : ProviderSetupState::ReadyToConnect;
    }

    /**
     * Everything an interface may know about one provider. No secret can reach this array.
     *
     * @return array<string, mixed>
     */
    public function summary(string $provider): array
    {
        $definition = ProviderCatalogue::get($provider);
        $row = $this->row($definition->key);
        $fallback = $this->environmentValues($definition);
        $values = $this->values($definition->key);

        return [
            ...$definition->toArray(),
            'state' => $this->state($definition->key)->value,
            'enabled' => $this->isEnabled($definition->key),
            'environment' => $this->environment($definition->key),
            'missing' => $this->missing($definition->key),
            'effective_scopes' => $this->scopes($definition->key),
            'connectable' => $this->isConnectable($definition->key),
            // Per field: is it set, where did it come from, and the four characters that let an
            // operator recognise it. Never the value, for a secret or a plain field alike — a
            // "non-secret" client id is still a fact about this install's provider apps.
            'values' => array_map(
                fn ($field) => [
                    'key' => $field->key,
                    'present' => ($values[$field->key] ?? null) !== null,
                    'source' => $row?->secretValue($field->key) !== null
                        ? 'stored'
                        : (($fallback[$field->key] ?? null) !== null ? 'environment' : null),
                    'hint' => $row?->hint($field->key)
                        ?? (isset($fallback[$field->key]) ? mb_substr((string) $fallback[$field->key], -4) : null),
                ],
                $definition->fields,
            ),
            'last_tested_at' => $row?->last_tested_at?->toIso8601String(),
            'last_test_status' => $row?->last_test_status,
            'last_test_message' => $row?->last_test_message,
            'last_rotated_at' => $row?->last_rotated_at?->toIso8601String(),
            'configured_at' => $row?->configured_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string,mixed>> every provider, in the product's order */
    public function summaries(?ProviderKind $kind = null): array
    {
        $keys = $kind === null
            ? ProviderCatalogue::keys()
            : array_map(static fn (ProviderDefinition $d) => $d->key, ProviderCatalogue::ofKind($kind));

        return array_map(fn (string $key) => $this->summary($key), $keys);
    }

    /**
     * Write the values an operator typed. PARTIAL by design.
     *
     * A key absent from `$values` is left exactly as it was, and an empty string means the same thing.
     * This is what lets an operator change a redirect-scoped field without re-typing a client secret
     * they cannot read back — and it is why clearing one is its own explicit call (`forget()`) rather
     * than an emergent property of submitting a form with a blank box.
     *
     * @param  array<string, string|null>  $values
     * @return list<string> the field keys that actually changed — the audit record's whole content
     */
    public function save(string $provider, array $values, ?string $environment = null, ?int $actorId = null): array
    {
        $definition = ProviderCatalogue::get($provider);
        $row = $this->rowOrNew($definition->key);
        $credentials = $row->credentials ?? [];
        $changed = [];

        foreach ($definition->fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $incoming = is_string($values[$field->key]) ? trim($values[$field->key]) : null;

            if ($incoming === null || $incoming === '') {
                continue; // "left alone", never "set to empty" — see the doc block
            }

            if (($credentials[$field->key] ?? null) !== $incoming) {
                $credentials[$field->key] = $incoming;
                $changed[] = $field->key;
            }
        }

        if ($environment !== null && $environment !== $row->environment) {
            $row->environment = $environment;
            $changed[] = 'environment';
        }

        $row->credentials = $credentials;

        if ($changed !== []) {
            $row->configured_at = Carbon::now();
            $row->configured_by = $actorId;

            // A configuration that changed has not been tested. Keeping the old verdict would let a
            // provider stay `production_ready` on the strength of a round trip made with a different
            // client secret — the single most dangerous stale fact this table can hold.
            $row->last_test_status = null;
            $row->last_test_message = null;
            $row->last_tested_at = null;
        }

        $row->save();
        $this->forgetCache($definition->key);

        return $changed;
    }

    /**
     * Rotate one secret: store the new value and record when.
     *
     * Rotation is not a different write — it is the same write with a timestamp that says an operator
     * did it deliberately. What matters is what it does NOT do: existing `provider_connections` keep
     * their access tokens and keep working, because a customer's token was issued to the app, not to
     * the secret. Only the next token REFRESH uses the new secret, which is exactly when a bad
     * rotation surfaces — hence the test verdict being cleared here too.
     */
    public function rotate(string $provider, string $key, string $value, ?int $actorId = null): void
    {
        $this->save($provider, [$key => $value], actorId: $actorId);

        $row = $this->rowOrNew(ProviderCatalogue::get($provider)->key);
        $row->last_rotated_at = Carbon::now();
        $row->save();

        $this->forgetCache($row->provider);
    }

    /**
     * Take a provider out of service, or put it back.
     *
     * Disabling deletes nothing: not the credentials, not `provider_connections`, not a single
     * `external_account` or the metrics already synced through them. A customer whose provider is
     * disabled keeps every figure they have and simply stops receiving new ones — which is the
     * difference between an operator pausing an integration and an operator destroying a workspace's
     * history.
     */
    public function setEnabled(string $provider, bool $enabled): void
    {
        $row = $this->rowOrNew(ProviderCatalogue::get($provider)->key);
        $row->enabled = $enabled;
        $row->save();

        $this->forgetCache($row->provider);
    }

    /**
     * Clear one stored field.
     *
     * Explicit, single-field, and separate from `save()` so that removing a key is always something
     * somebody chose rather than something a blank form did.
     */
    public function forget(string $provider, string $key): bool
    {
        $row = $this->row(ProviderCatalogue::get($provider)->key);

        if ($row === null || $row->secretValue($key) === null) {
            return false;
        }

        $credentials = $row->credentials ?? [];
        unset($credentials[$key]);

        $row->credentials = $credentials;
        $row->last_test_status = null;
        $row->last_test_message = null;
        $row->last_tested_at = null;
        $row->save();

        $this->forgetCache($row->provider);

        return true;
    }

    /**
     * Record the outcome of a real round trip.
     *
     * The message is truncated and stored as the provider gave it. It is shown to the platform
     * operator only — never to a tenant — because a provider's refusal can name an app, an
     * organisation or an account belonging to somebody else's setup.
     */
    public function recordTest(string $provider, bool $passed, string $message): void
    {
        $row = $this->rowOrNew(ProviderCatalogue::get($provider)->key);
        $row->last_tested_at = Carbon::now();
        $row->last_test_status = $passed ? 'passed' : 'failed';
        $row->last_test_message = mb_substr(trim($message), 0, 500);
        $row->save();

        $this->forgetCache($row->provider);
    }

    /** Drop the per-request memo. Called after every write, and by tests that seed rows directly. */
    public function forgetCache(?string $provider = null): void
    {
        if ($provider === null) {
            $this->rows = [];

            return;
        }

        unset($this->rows[AdPlatforms::canonical($provider)]);
    }

    private function row(string $provider): ?ProviderConfiguration
    {
        $key = AdPlatforms::canonical($provider);

        if (! array_key_exists($key, $this->rows)) {
            $this->rows[$key] = ProviderConfiguration::query()->where('provider', $key)->first();
        }

        return $this->rows[$key];
    }

    private function rowOrNew(string $provider): ProviderConfiguration
    {
        $key = AdPlatforms::canonical($provider);

        return $this->row($key) ?? new ProviderConfiguration([
            'provider' => $key,
            'environment' => 'sandbox',
            'enabled' => true,
        ]);
    }

    /**
     * The `.env` half, read through the config file that matches the provider's kind.
     *
     * @return array<string, string|null>
     */
    private function environmentValues(ProviderDefinition $definition): array
    {
        $file = $definition->kind === ProviderKind::Commerce ? 'commerce_platforms' : 'ad_platforms';
        $config = config("{$file}.platforms.{$definition->key}");

        if (! is_array($config)) {
            return [];
        }

        $values = [];

        foreach ($definition->fields as $field) {
            $value = $config[$field->key] ?? null;
            $values[$field->key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $values;
    }
}
