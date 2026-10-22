<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Services\AttributionCredit;
use App\Domains\Metrics\Settings\AttributionPresets;
use App\Domains\Metrics\Settings\AttributionSettings;
use App\Domains\Metrics\Settings\ProjectAttributionSetting;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * ATTRIB-CREDIT-001 — Settings → Analytics → Attribution, for one project.
 *
 * Both routes sit behind `module:attribution_intelligence`, so a project that has not switched the module on gets a
 * refusal that names the module, not an empty form. Saving is audited with its before and after, and a configuration
 * the engine cannot run is refused here with the reason, not stored.
 */
final class AttributionSettingsController extends Controller
{
    public function __construct(private readonly AttributionSettings $settings, private readonly AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        $row = ProjectAttributionSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->first();

        return ApiResponse::success([
            'configured' => $row !== null,
            'models_available' => AttributionCredit::MODELS,
            'defaults' => ['models' => AttributionCredit::MODELS, 'lookback_days' => AttributionCredit::DEFAULT_LOOKBACK_DAYS, 'half_life_days' => AttributionCredit::DEFAULT_HALF_LIFE_DAYS, 'weights' => AttributionCredit::DEFAULT_WEIGHTS],
            'current' => $row === null ? null : [
                'models' => $row->models, 'lookback_days' => $row->lookback_days, 'half_life_days' => $row->half_life_days,
                'weights' => $row->weights, 'version' => $row->version,
                'preset' => $row->preset ?? AttributionPresets::DEFAULT, 'advanced' => $row->advanced,
            ],
            'current_version' => AttributionCredit::VERSION,
            // ATTRIB-PRESETS-001 — the four presets, the default, and this project's change history.
            'presets' => AttributionPresets::catalogue(),
            'default_preset' => AttributionPresets::DEFAULT,
            'advanced_defaults' => AttributionPresets::ADVANCED_DEFAULTS,
            'versions' => $this->settings->versions($tenantId, $projectId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        $data = $request->validate([
            // ATTRIB-PRESETS-001 — with a preset, the models are the preset's; without one, the old contract.
            'preset' => ['nullable', 'string', 'in:'.implode(',', AttributionPresets::ALL)],
            'advanced' => ['nullable', 'array'],
            'advanced.honour_consent' => ['nullable', 'boolean'],
            'advanced.exclude_refunds' => ['nullable', 'boolean'],
            'advanced.eligible_channels' => ['nullable', 'array'],
            'advanced.eligible_channels.*' => ['string'],
            'models' => ['required_without:preset', 'array', 'min:1'],
            'models.*' => ['string'],
            'lookback_days' => ['nullable', 'integer'],
            'half_life_days' => ['nullable', 'integer'],
            'weights' => ['nullable', 'array'],
            'weights.*' => ['numeric'],
        ]);

        $before = ProjectAttributionSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->first();

        try {
            $row = $this->settings->save($tenantId, $projectId, array_filter($data, static fn ($v): bool => $v !== null), (int) $request->user()->getKey());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), ['configuration' => [$e->getMessage()]], status: 422);
        }

        $this->audit->log(
            'attribution.settings_changed', 'project', $projectId,
            $before === null ? null : ['preset' => $before->preset, 'models' => $before->models, 'lookback_days' => $before->lookback_days, 'half_life_days' => $before->half_life_days],
            ['preset' => $row->preset, 'models' => $row->models, 'lookback_days' => $row->lookback_days, 'half_life_days' => $row->half_life_days, 'version' => $row->version],
        );

        return $this->show();
    }

    /** @return array{0: string, 1: string} */
    private function scope(): array
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        return [$tenantId, $projectId];
    }
}
