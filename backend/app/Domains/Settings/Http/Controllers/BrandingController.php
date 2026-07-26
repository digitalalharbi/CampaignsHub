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

/**
 * Organization branding (logo, colours, portal name, report footer). Stored in tenant.settings.branding
 * and consumed by the app shell + report/client surfaces. Read for members; write requires settings.manage.
 */
final class BrandingController extends Controller
{
    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'logo_url' => null,
        'primary_color' => '#0d8a6f',
        'report_accent' => '#0d8a6f',
        'portal_name' => 'CampaignsHub',
        'report_footer' => null,
        'client_logo_url' => null,
    ];

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $branding = array_merge(self::DEFAULTS, (array) (($tenant->settings ?? [])['branding'] ?? []));

        return ApiResponse::success(['branding' => $branding], 'Branding retrieved.');
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenant = $this->tenant();

        $data = $request->validate([
            'branding.logo_url' => ['nullable', 'url', 'max:2048'],
            'branding.client_logo_url' => ['nullable', 'url', 'max:2048'],
            'branding.primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.report_accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.portal_name' => ['required', 'string', 'max:80'],
            'branding.report_footer' => ['nullable', 'string', 'max:280'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $before = $settings['branding'] ?? null;
        $settings['branding'] = array_merge(self::DEFAULTS, $data['branding']);
        $tenant->forceFill(['settings' => $settings])->save();

        $audit->log(action: 'settings.branding.updated', entityType: 'tenant', entityId: (string) $tenant->id, before: $before, after: $settings['branding']);

        return $this->show($request);
    }

    private function tenant(): Tenant
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return Tenant::query()->findOrFail($id);
    }
}
