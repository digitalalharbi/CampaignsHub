<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Settings;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Metrics\Services\AttributionCredit;
use App\Domains\Metrics\Services\AttributionModelComparison;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;

/**
 * ATTRIB-CREDIT-001 — the one place that answers «should this project's attribution report carry the fractional
 * models, and with which rules?».
 *
 * The answer is a configuration only when BOTH hold: the workspace has switched `attribution_intelligence` on for
 * this project (the registry decides, inheritance included), and the project has chosen its rules. Either missing
 * and the answer is null, which {@see AttributionModelComparison} reads as «leave the
 * report exactly as it was». Disabling the module therefore cannot change a figure that existed before it.
 */
final class AttributionSettings
{
    public const MODULE = 'attribution_intelligence';

    public function __construct(private readonly Capabilities $capabilities) {}

    public function enabled(string $tenantId, string $projectId): bool
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return false;
        }
        $client = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($projectId)->value('client_workspace_id');

        return $this->capabilities->allows(self::MODULE, $tenant, $client === null ? null : (string) $client, $projectId);
    }

    /** @return array<string,mixed>|null a configuration for {@see AttributionCredit::config()}, or null for «no fractional models» */
    public function creditConfig(string $tenantId, string $projectId): ?array
    {
        if (! $this->enabled($tenantId, $projectId)) {
            return null;
        }

        $row = ProjectAttributionSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->first();
        if ($row === null) {
            return null;
        }

        return [
            'models' => $row->models,
            'lookback_days' => $row->lookback_days,
            'half_life_days' => $row->half_life_days,
            'weights' => $row->weights,
            // ATTRIB-PRESETS-001 — the named preset and the advanced options (null unless ADVANCED or CUSTOM).
            'preset' => $row->preset ?? AttributionPresets::DEFAULT,
            'advanced' => $row->advanced,
        ];
    }

    /**
     * ATTRIB-PRESETS-001 — the versions of this project's setting, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $tenantId, string $projectId, int $limit = 20): array
    {
        return ProjectAttributionSettingVersion::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->orderByDesc('number')->limit($limit)->get()
            ->map(static fn (ProjectAttributionSettingVersion $v): array => [
                'number' => $v->number, 'preset' => $v->preset, 'models' => $v->models, 'lookback_days' => $v->lookback_days,
                'half_life_days' => $v->half_life_days, 'weights' => $v->weights, 'advanced' => $v->advanced,
                'version' => $v->version, 'changed_by' => $v->changed_by, 'created_at' => $v->created_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Validate, normalise and store a project's choice.
     *
     * @param  array<string,mixed>  $raw
     *
     * @throws \InvalidArgumentException when the configuration is not one the engine can run
     */
    public function save(string $tenantId, string $projectId, array $raw, ?int $userId): ProjectAttributionSetting
    {
        /*
         * ATTRIB-PRESETS-001 — a preset names the configuration; the engine still validates it.
         *
         * A request without a preset is the pre-preset contract (models and the rest, stored as
         * CUSTOM so the record says what it is). Every save writes a version row, so the comparison
         * a reader opens tomorrow can be named with the configuration it was computed under.
         */
        $preset = (string) ($raw['preset'] ?? '');
        $advanced = null;
        if ($preset !== '') {
            $resolved = AttributionPresets::resolve($preset, $raw);
            $raw = $resolved['config'];
            $advanced = $resolved['advanced'];
        } else {
            $preset = AttributionPresets::CUSTOM;
        }
        $config = AttributionCredit::config($raw);

        $row = ProjectAttributionSetting::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
            [
                'models' => $config['models'],
                'lookback_days' => $config['lookback_days'],
                'half_life_days' => $config['half_life_days'],
                'weights' => $config['weights'],
                'preset' => $preset,
                'advanced' => $advanced,
                'version' => AttributionCredit::VERSION,
                'updated_by' => $userId,
            ],
        );

        $number = 1 + (int) ProjectAttributionSettingVersion::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->max('number');
        ProjectAttributionSettingVersion::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'project_id' => $projectId, 'number' => $number,
            'preset' => $preset, 'models' => $config['models'], 'lookback_days' => $config['lookback_days'],
            'half_life_days' => $config['half_life_days'], 'weights' => $config['weights'], 'advanced' => $advanced,
            'version' => AttributionCredit::VERSION, 'changed_by' => $userId, 'created_at' => now(),
        ]);

        return $row;
    }
}
