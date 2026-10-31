<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Legal\Models\PlatformSetting;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PAY-GATEWAY-CONSOLE-001 — the ONE place every payment adapter, checkout, webhook and readiness check reads a gateway's
 * effective credentials, mode, activation and the platform's primary gateway from.
 *
 * Source: the gateway's encrypted row `payment.{key}` in `provider_configurations`, written from the platform console. An install
 * configured before the console existed keeps working from its environment keys: while a gateway has NO row, its fields fall back to
 * `config('services.{key}.*')`. Once a row holds credentials, the row is the only source for that gateway — fields are never mixed
 * across the two, so an environment test key can never pair with a stored live key.
 *
 * Read per call and never cached in the instance: a saved key, a switched primary or a disabled gateway is in effect on the next
 * request and the next queued job, with no restart and no config cache to clear.
 *
 * The separate acts and their rules:
 * - save: write-only; a blank field keeps the stored value; nothing is ever returned. Changing credentials or mode makes the last
 *   test stale. A key whose prefix contradicts the chosen mode is refused.
 * - test: recorded with a fingerprint of the exact credentials and mode it ran with.
 * - enable: only with a current passed test; live mode needs an explicit confirmation.
 * - primary: only an enabled gateway; new payments go to it, every existing payment keeps the gateway it was made with.
 */
final class PaymentGatewaySettings
{
    public const MODES = ['test', 'live'];

    public function __construct(private readonly AuditLogger $audit) {}

    public static function storageKey(string $gateway): string
    {
        return 'payment.'.$gateway;
    }

    /** The value an adapter uses, or null. */
    public function value(string $gateway, string $field): ?string
    {
        $row = $this->row($gateway);

        if ($this->rowHasCredentials($row)) {
            return $row->secretValue($field);
        }

        $legacy = config("services.{$gateway}.{$field}");

        return is_string($legacy) && trim($legacy) !== '' ? $legacy : null;
    }

    /** Where the effective values come from: `console`, `environment` or `none`. */
    public function source(string $gateway): string
    {
        if ($this->rowHasCredentials($this->row($gateway))) {
            return 'console';
        }

        foreach (PaymentGatewayCatalogue::get($gateway)['fields'] as $field) {
            if ($this->value($gateway, $field['key']) !== null) {
                return 'environment';
            }
        }

        return 'none';
    }

    /** @return list<string> required fields with no value */
    public function missing(string $gateway): array
    {
        $out = [];

        foreach (PaymentGatewayCatalogue::get($gateway)['fields'] as $field) {
            if ($field['required'] && $this->value($gateway, $field['key']) === null) {
                $out[] = $field['key'];
            }
        }

        return $out;
    }

    public function isConfigured(string $gateway): bool
    {
        return PaymentGatewayCatalogue::get($gateway)['fields'] !== [] && $this->missing($gateway) === [];
    }

    /** `test` or `live`. A stored mode wins; otherwise it is read from the key's own prefix, and an unknown key is `test`. */
    public function mode(string $gateway): string
    {
        $stored = $this->row($gateway)?->environment;

        if (in_array($stored, self::MODES, true)) {
            return $stored;
        }

        return $this->modeOfKey($gateway, $this->prefixedValue($gateway)) ?? 'test';
    }

    /**
     * Whether new payments may be opened through this gateway.
     *
     * A console row decides. Without one, an environment-configured gateway is treated as enabled — that is exactly what it was
     * before the console existed, and turning it off silently would strand the checkouts of an install nobody touched.
     */
    public function isEnabled(string $gateway): bool
    {
        $row = $this->row($gateway);

        if ($row !== null) {
            return (bool) $row->enabled;
        }

        return $this->source($gateway) === 'environment' && $this->isConfigured($gateway);
    }

    public function testIsCurrent(string $gateway): bool
    {
        $row = $this->row($gateway);

        return $row !== null && $row->last_test_status === 'passed'
            && $row->tested_fingerprint !== null && hash_equals($row->tested_fingerprint, $this->fingerprint($gateway));
    }

    /** The gateway new subscription payments are opened through. */
    public function primary(): string
    {
        $chosen = PlatformSetting::current()->payment_primary_gateway;

        return is_string($chosen) && $chosen !== '' ? $chosen : (string) config('subscriptions.default');
    }

    public function primaryIsFromConsole(): bool
    {
        $chosen = PlatformSetting::current()->payment_primary_gateway;

        return is_string($chosen) && $chosen !== '';
    }

    /**
     * Store credentials and, optionally, the mode. Write-only: blank fields keep what is stored; values are never returned.
     *
     * @param  array<string, mixed>  $values
     * @return list<string> the field names that changed
     */
    public function save(string $gateway, array $values, ?string $mode, ?int $actorId): array
    {
        $definition = PaymentGatewayCatalogue::get($gateway);

        if ($definition['fields'] === []) {
            throw ValidationException::withMessages(['gateway' => 'This gateway has no adapter yet, so its keys cannot be stored.']);
        }

        if ($mode !== null && ! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Mode is test or live.']);
        }

        $row = $this->row($gateway) ?? new ProviderConfiguration(['provider' => self::storageKey($gateway), 'enabled' => false]);
        // Starting from the environment's values the first time, so an install moving to the console keeps what it had for any
        // field the operator leaves blank, and the row then holds the whole set.
        $credentials = $this->rowHasCredentials($row) ? (array) $row->credentials : $this->legacyValues($gateway);
        $changed = [];

        foreach ($definition['fields'] as $field) {
            $incoming = $values[$field['key']] ?? null;

            if (! is_string($incoming) || trim($incoming) === '') {
                continue;
            }

            $incoming = trim($incoming);

            if (($credentials[$field['key']] ?? null) !== $incoming) {
                $credentials[$field['key']] = $incoming;
                $changed[] = $field['key'];
            }
        }

        $newMode = $mode ?? ($this->modeOfKey($gateway, $credentials[$definition['prefixed_field'] ?? ''] ?? null) ?? ($row->environment ?: 'test'));
        $prefixed = $definition['prefixed_field'] !== null ? ($credentials[$definition['prefixed_field']] ?? null) : null;
        $keyMode = $this->modeOfKey($gateway, $prefixed);

        // A key's own prefix is the truth about where money goes; a mode that contradicts it is how an operator ends up certain
        // they are testing while taking real money.
        if ($keyMode !== null && $keyMode !== $newMode) {
            throw ValidationException::withMessages(['mode' => "The {$definition['label']['en']} key is a {$keyMode} key, so the mode must be {$keyMode}."]);
        }

        if ($row->environment !== $newMode) {
            $changed[] = 'mode';
        }

        if ($changed === [] && $row->exists) {
            return [];
        }

        $row->credentials = $credentials;
        $row->environment = $newMode;
        $row->configured_at ??= Carbon::now();
        $row->configured_by = $actorId;
        $row->last_rotated_at = Carbon::now();
        $row->save();

        $this->audit->log(
            action: 'platform.payments.credentials_saved',
            entityType: 'payment_gateway',
            entityId: $gateway,
            // Field NAMES only. A secret, a hint of one or its length never reaches the audit trail.
            after: ['fields' => array_values(array_diff($changed, ['mode'])), 'mode' => $newMode, 'test_current' => $this->testIsCurrent($gateway)],
            userId: $actorId,
        );

        return $changed;
    }

    public function recordTest(string $gateway, bool $passed, ?string $message, ?int $actorId): void
    {
        $row = $this->row($gateway) ?? new ProviderConfiguration([
            'provider' => self::storageKey($gateway), 'enabled' => $this->isEnabled($gateway), 'environment' => $this->mode($gateway),
        ]);

        // A first test of an environment-configured gateway copies its keys into the row, so the fingerprint describes what ran.
        if (! $this->rowHasCredentials($row)) {
            $row->credentials = $this->legacyValues($gateway);
        }

        $row->last_tested_at = Carbon::now();
        $row->last_test_status = $passed ? 'passed' : 'failed';
        $row->last_test_message = $message === null ? null : mb_substr($message, 0, 500);
        $row->save();

        $row->tested_fingerprint = $passed ? $this->fingerprint($gateway) : null;
        $row->save();

        $this->audit->log(
            action: 'platform.payments.connection_tested',
            entityType: 'payment_gateway',
            entityId: $gateway,
            after: ['passed' => $passed, 'mode' => $this->mode($gateway)],
            userId: $actorId,
        );
    }

    public function setEnabled(string $gateway, bool $enabled, bool $confirmLive, ?int $actorId): void
    {
        if ($enabled) {
            if (! $this->isConfigured($gateway)) {
                throw ValidationException::withMessages(['gateway' => 'Store the required keys first.']);
            }
            if (! $this->testIsCurrent($gateway)) {
                throw ValidationException::withMessages(['gateway' => 'Run a passing connection test with the current keys first.']);
            }
            if ($this->mode($gateway) === 'live' && ! $confirmLive) {
                throw ValidationException::withMessages(['confirm_live' => 'Enabling live mode takes real money; confirm it explicitly.']);
            }
        } elseif ($this->primary() === $gateway && $this->primaryIsFromConsole()) {
            throw ValidationException::withMessages(['gateway' => 'This is the primary gateway; choose another primary before disabling it.']);
        }

        $row = $this->row($gateway) ?? new ProviderConfiguration([
            'provider' => self::storageKey($gateway), 'environment' => $this->mode($gateway), 'credentials' => $this->legacyValues($gateway),
        ]);
        $row->enabled = $enabled;
        $row->save();

        $this->audit->log(
            action: $enabled ? 'platform.payments.gateway_enabled' : 'platform.payments.gateway_disabled',
            entityType: 'payment_gateway',
            entityId: $gateway,
            after: ['mode' => $this->mode($gateway)],
            userId: $actorId,
        );
    }

    /** New payments go to `$gateway`; nothing that exists moves. */
    public function setPrimary(string $gateway, ?int $actorId): void
    {
        if (! $this->isEnabled($gateway)) {
            throw ValidationException::withMessages(['gateway' => 'Only an enabled gateway can be primary.']);
        }

        $before = $this->primary();
        $settings = PlatformSetting::current();
        $settings->forceFill([
            'payment_primary_gateway' => $gateway,
            'payment_primary_set_at' => Carbon::now(),
            'payment_primary_set_by' => $actorId,
        ])->save();

        $this->audit->log(
            action: 'platform.payments.primary_changed',
            entityType: 'payment_gateway',
            entityId: $gateway,
            before: ['primary' => $before],
            after: ['primary' => $gateway, 'mode' => $this->mode($gateway)],
            userId: $actorId,
        );
    }

    /** A digest of the exact credentials and mode in effect; never stored anywhere a value could be read back from. */
    public function fingerprint(string $gateway): string
    {
        $values = [];
        foreach (PaymentGatewayCatalogue::get($gateway)['fields'] as $field) {
            $values[$field['key']] = $this->value($gateway, $field['key']);
        }

        return hash_hmac('sha256', json_encode([$values, $this->mode($gateway)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** The last four characters of a stored secret, so an operator can tell which key is in place without it being shown. */
    public function hint(string $gateway, string $field): ?string
    {
        $value = $this->value($gateway, $field);

        return $value === null ? null : '••••'.mb_substr($value, -4);
    }

    public function row(string $gateway): ?ProviderConfiguration
    {
        return ProviderConfiguration::query()->where('provider', self::storageKey($gateway))->first();
    }

    private function rowHasCredentials(?ProviderConfiguration $row): bool
    {
        return $row !== null && array_filter((array) ($row->credentials ?? []), static fn ($v) => is_string($v) && $v !== '') !== [];
    }

    /** @return array<string, string> */
    private function legacyValues(string $gateway): array
    {
        $out = [];
        foreach (PaymentGatewayCatalogue::get($gateway)['fields'] as $field) {
            $value = config("services.{$gateway}.{$field['key']}");
            if (is_string($value) && trim($value) !== '') {
                $out[$field['key']] = $value;
            }
        }

        return $out;
    }

    private function prefixedValue(string $gateway): ?string
    {
        $field = PaymentGatewayCatalogue::get($gateway)['prefixed_field'];

        return $field === null ? null : $this->value($gateway, $field);
    }

    private function modeOfKey(string $gateway, ?string $key): ?string
    {
        $definition = PaymentGatewayCatalogue::get($gateway);

        if ($key === null || $definition['test_prefix'] === null) {
            return null;
        }

        return match (true) {
            str_starts_with($key, $definition['test_prefix']) => 'test',
            str_starts_with($key, (string) $definition['live_prefix']) => 'live',
            default => null,
        };
    }
}
