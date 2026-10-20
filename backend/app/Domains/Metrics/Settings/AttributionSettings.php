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
        ];
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
        $config = AttributionCredit::config($raw);

        return ProjectAttributionSetting::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
            [
                'models' => $config['models'],
                'lookback_days' => $config['lookback_days'],
                'half_life_days' => $config['half_life_days'],
                'weights' => $config['weights'],
                'version' => AttributionCredit::VERSION,
                'updated_by' => $userId,
            ],
        );
    }
}
