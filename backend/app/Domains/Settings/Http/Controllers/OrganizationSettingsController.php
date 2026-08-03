<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Rules\PhoneNumberRule;
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
    /**
     * Read from the enum rather than restated here (REG-001).
     *
     * The hand-written list this replaces said `in_house` where the enum says `in_house_team`, and
     * omitted `self_serve_company` entirely — so a self-serve workspace opening this form was shown
     * a set of types that did not include its own, with `agency` preselected by the defaults below,
     * and saving reclassified it as an agency.
     *
     * @return list<string>
     */
    private static function accountTypes(): array
    {
        return AccountType::values();
    }

    private const DATE_FORMATS = ['DD/MM/YYYY', 'YYYY-MM-DD', 'MM/DD/YYYY', 'D MMM YYYY'];

    private const NUMBER_FORMATS = ['latin', 'grouped'];

    /*
     * `account_type` is deliberately ABSENT from these defaults (REG-001).
     *
     * It used to default to `agency`, so a workspace that had never chosen one was shown — and, on
     * the next save, permanently recorded as — an agency. The current value now comes from the
     * tenant's own column in `show()`, and is null when genuinely unset, which the form can present
     * as an unanswered question instead of a wrong answer.
     */

    /** @var array<string,mixed> */
    private const DEFAULTS = [
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

        /*
         * The account type comes from the tenant COLUMN, which is the one that matters (REG-001).
         *
         * It was previously stored a second time inside this JSONB blob, and only there: the form
         * read and wrote the copy while `Portal::forAccountType` and the entitlement layer read the
         * column. So changing "account type" in settings appeared to work and changed nothing about
         * the portal, and the two answers drifted apart silently. One field, one home.
         */
        $general['account_type'] = $tenant->account_type;

        return ApiResponse::success([
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'general' => $general,
            'options' => [
                'account_types' => self::accountTypes(),
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
            'general.account_type' => ['required', Rule::in(self::accountTypes())],
            'general.logo_url' => ['nullable', 'url', 'max:2048'],
            'general.contact_email' => ['nullable', 'email', 'max:180'],
            'general.contact_phone' => ['nullable', 'string', 'max:40', new PhoneNumberRule],
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

        $general = $data['general'];
        // Written to the column, not into the blob — see `show()`. Kept out of `settings.general`
        // entirely so a stale copy cannot outlive this change and be read by something later.
        $accountType = $general['account_type'];
        unset($general['account_type']);

        $settings['general'] = array_merge(self::DEFAULTS, $general);
        $tenant->forceFill([
            'name' => $data['name'],
            'account_type' => $accountType,
            'settings' => $settings,
        ])->save();

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
