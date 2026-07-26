<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organization ("General") settings: the tenant profile + display defaults, stored in tenant.settings
 * (JSONB). Read is available to any authenticated member; writes require settings.manage and are
 * validated + audited. These defaults feed currency/timezone/date-format across the app.
 */
final class OrganizationSettingsController extends Controller
{
    private const ACCOUNT_TYPES = ['agency', 'freelancer', 'in_house', 'brand'];

    private const DATE_FORMATS = ['DD/MM/YYYY', 'YYYY-MM-DD', 'MM/DD/YYYY', 'D MMM YYYY'];

    private const NUMBER_FORMATS = ['latin', 'grouped'];

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'account_type' => 'agency',
        'logo_url' => null,
        'contact_email' => null,
        'contact_phone' => null,
        'country' => 'SA',
        'default_locale' => 'ar',
        'default_currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
        'date_format' => 'DD/MM/YYYY',
        'number_format' => 'latin',
        'fiscal_year_start_month' => 1,
        'demo_mode' => false,
    ];

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $settings = (array) ($tenant->settings ?? []);
        $general = array_merge(self::DEFAULTS, (array) ($settings['general'] ?? []));

        return ApiResponse::success([
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'general' => $general,
            'options' => [
                'account_types' => self::ACCOUNT_TYPES,
                'date_formats' => self::DATE_FORMATS,
                'number_formats' => self::NUMBER_FORMATS,
            ],
        ], 'Organization settings retrieved.');
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenant = $this->tenant();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'general.account_type' => ['required', Rule::in(self::ACCOUNT_TYPES)],
            'general.logo_url' => ['nullable', 'url', 'max:2048'],
            'general.contact_email' => ['nullable', 'email', 'max:180'],
            'general.contact_phone' => ['nullable', 'string', 'max:40'],
            'general.country' => ['required', 'string', 'size:2'],
            'general.default_locale' => ['required', 'in:ar,en'],
            'general.default_currency' => ['required', 'string', 'size:3'],
            'general.timezone' => ['required', 'string', 'timezone'],
            'general.date_format' => ['required', Rule::in(self::DATE_FORMATS)],
            'general.number_format' => ['required', Rule::in(self::NUMBER_FORMATS)],
            'general.fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'general.demo_mode' => ['boolean'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $before = ['name' => $tenant->name, 'general' => $settings['general'] ?? null];

        $settings['general'] = array_merge(self::DEFAULTS, $data['general']);
        $tenant->forceFill(['name' => $data['name'], 'settings' => $settings])->save();

        $audit->log(
            action: 'settings.organization.updated',
            entityType: 'tenant',
            entityId: (string) $tenant->id,
            before: $before,
            after: ['name' => $tenant->name, 'general' => $settings['general']],
        );

        return $this->show($request);
    }

    private function tenant(): Tenant
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return Tenant::query()->findOrFail($id);
    }
}
