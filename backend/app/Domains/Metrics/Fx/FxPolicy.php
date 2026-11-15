<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Audit\AuditLogger;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use InvalidArgumentException;

/**
 * FX-ENGINE-001 — the platform's money-display choices, managed from /admin/settings/currency-rates.
 *
 * - `display_currency`: USD or SAR, the platform default, with an optional per-workspace choice. A DISPLAY choice only:
 *   money is still summed in USD (`ReportingCurrency::DEFAULT`) and converted on the way out through FxEngine.
 * - `auto_source_enabled`: the FREE automatic daily source (ECB euro reference rates — public, no key, no account).
 *   Default OFF. When on, `fx:rates` records each day's rates for the currencies in use, named `ecb_reference`.
 */
final class FxPolicy
{
    public const DISPLAY = ['USD', 'SAR'];

    public const AUTO_SOURCE = 'ecb_reference';

    private const DEFAULTS = ['display_currency' => 'USD', 'auto_source_enabled' => false];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{display_currency: string, auto_source_enabled: bool, auto_source: string, auto_source_label: string} */
    public function current(): array
    {
        $stored = (array) (PlatformSetting::current()->fx_policy ?? []);
        $policy = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));

        return [
            'display_currency' => in_array($policy['display_currency'], self::DISPLAY, true) ? (string) $policy['display_currency'] : 'USD',
            'auto_source_enabled' => (bool) $policy['auto_source_enabled'],
            'auto_source' => self::AUTO_SOURCE,
            'auto_source_label' => 'ECB euro reference rates (free, daily)',
        ];
    }

    public function autoSourceEnabled(): bool
    {
        return $this->current()['auto_source_enabled'];
    }

    /**
     * @param  array{display_currency?: string, auto_source_enabled?: bool}  $changes
     * @return array{display_currency: string, auto_source_enabled: bool, auto_source: string, auto_source_label: string}
     */
    public function update(array $changes, ?User $by): array
    {
        $before = $this->current();
        $next = [
            'display_currency' => strtoupper((string) ($changes['display_currency'] ?? $before['display_currency'])),
            'auto_source_enabled' => (bool) ($changes['auto_source_enabled'] ?? $before['auto_source_enabled']),
        ];
        if (! in_array($next['display_currency'], self::DISPLAY, true)) {
            throw new InvalidArgumentException('Display currency must be USD or SAR.');
        }

        $setting = PlatformSetting::current();
        $setting->forceFill(['fx_policy' => $next])->save();
        $this->audit->log('fx.policy.updated', 'platform_settings', (string) $setting->getKey(),
            before: ['display_currency' => $before['display_currency'], 'auto_source_enabled' => $before['auto_source_enabled']],
            after: $next, userId: $by?->id);

        return $this->current();
    }

    /** The currency money is DISPLAYED in for a workspace: its own choice, else the platform default. */
    public function displayCurrencyFor(?string $tenantId): string
    {
        if ($tenantId !== null) {
            $own = Tenant::query()->whereKey($tenantId)->value('settings');
            $choice = is_array($own) ? ($own['fx']['display_currency'] ?? null) : (is_string($own) ? (json_decode($own, true)['fx']['display_currency'] ?? null) : null);
            if (is_string($choice) && in_array($choice, self::DISPLAY, true)) {
                return $choice;
            }
        }

        return $this->current()['display_currency'];
    }

    /** Set (or clear, with null) a workspace's own display currency. */
    public function setWorkspaceDisplay(Tenant $tenant, ?string $currency, ?User $by): void
    {
        $currency = $currency === null ? null : strtoupper($currency);
        if ($currency !== null && ! in_array($currency, self::DISPLAY, true)) {
            throw new InvalidArgumentException('Display currency must be USD or SAR.');
        }

        $settings = (array) ($tenant->settings ?? []);
        $before = $settings['fx']['display_currency'] ?? null;
        if ($currency === null) {
            unset($settings['fx']['display_currency']);
        } else {
            $settings['fx']['display_currency'] = $currency;
        }
        $tenant->forceFill(['settings' => $settings])->save();

        $this->audit->log('fx.workspace_display.updated', Tenant::class, (string) $tenant->getKey(),
            before: ['display_currency' => $before], after: ['display_currency' => $currency], userId: $by?->id, tenantId: (string) $tenant->getKey());
    }

    /** @return list<array{id: string, name: string, display_currency: ?string, effective: string}> */
    public function workspaces(int $limit = 300): array
    {
        $default = $this->current()['display_currency'];

        return Tenant::query()->orderBy('name')->limit($limit)->get(['id', 'name', 'settings'])
            ->map(static function (Tenant $t) use ($default): array {
                $own = $t->settings['fx']['display_currency'] ?? null;
                $own = is_string($own) && in_array($own, self::DISPLAY, true) ? $own : null;

                return ['id' => (string) $t->id, 'name' => (string) $t->name, 'display_currency' => $own, 'effective' => $own ?? $default];
            })->values()->all();
    }
}
