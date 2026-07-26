<?php

declare(strict_types=1);

namespace App\Domains\Disclaimers\Services;

use App\Domains\Disclaimers\Models\Disclaimer;
use Illuminate\Support\Carbon;

/**
 * Resolves the effective disclaimer/methodology copy for a scope by deep-merging the immutable system
 * defaults (config/disclaimers.php) with active overrides in priority order:
 *
 *     system default  →  organization  →  client  →  project        (project wins)
 *
 * Only the sections an override actually sets are merged, so a project can tweak one line without
 * restating the rest. The result is embedded into report snapshots and served to live surfaces.
 */
final class DisclaimerResolver
{
    /**
     * @return array{version:int, locale_default:string, enabled:array<string,bool>, sections:array<string,mixed>, sources:list<string>}
     */
    public function resolve(string $tenantId, ?string $clientId = null, ?string $projectId = null): array
    {
        /** @var array<string,mixed> $base */
        $base = config('disclaimers');
        $result = [
            'version' => (int) ($base['version'] ?? 1),
            'locale_default' => (string) ($base['locale_default'] ?? 'ar'),
            'enabled' => (array) ($base['enabled'] ?? []),
            'sections' => (array) ($base['sections'] ?? []),
            'sources' => ['system'],
        ];

        $overrides = $this->activeOverrides($tenantId, $clientId, $projectId);

        // Apply lowest → highest priority so the highest scope overrides the rest.
        foreach (['organization', 'client', 'project'] as $scope) {
            $payload = $overrides[$scope] ?? null;
            if ($payload === null) {
                continue;
            }
            $result['sources'][] = $scope;
            if (isset($payload['locale_default']) && is_string($payload['locale_default'])) {
                $result['locale_default'] = $payload['locale_default'];
            }
            if (isset($payload['enabled']) && is_array($payload['enabled'])) {
                $result['enabled'] = array_merge($result['enabled'], $payload['enabled']);
            }
            if (isset($payload['sections']) && is_array($payload['sections'])) {
                $result['sections'] = $this->deepMerge($result['sections'], $payload['sections']);
            }
        }

        return $result;
    }

    /**
     * Convenience: the pieces a client-facing report footer needs, in one locale, honouring `enabled`.
     *
     * @return array{short:?string, full:?string, freshness:?string, methodology:?string, objective:?string}
     */
    public function forReport(string $tenantId, ?string $clientId, ?string $projectId, string $locale, ?string $objective = null): array
    {
        $r = $this->resolve($tenantId, $clientId, $projectId);
        $pick = function (string $key) use ($r, $locale): ?string {
            if (($r['enabled'][$key] ?? true) !== true) {
                return null;
            }
            $node = $r['sections'][$key] ?? null;

            return is_array($node) ? ($node[$locale] ?? $node['ar'] ?? null) : null;
        };

        $objectiveText = null;
        if (($r['enabled']['objectives'] ?? true) === true && $objective !== null) {
            $node = $r['sections']['objectives'][$objective] ?? null;
            $objectiveText = is_array($node) ? ($node[$locale] ?? $node['ar'] ?? null) : null;
        }

        return [
            'short' => $pick('short'),
            'full' => $pick('full'),
            'freshness' => $pick('freshness'),
            'methodology' => $pick('methodology'),
            'objective' => $objectiveText,
        ];
    }

    /** @return array<string, array<string,mixed>> keyed by scope */
    private function activeOverrides(string $tenantId, ?string $clientId, ?string $projectId): array
    {
        $rows = Disclaimer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_at')->orWhere('effective_at', '<=', Carbon::now());
            })
            ->where(function ($q) use ($clientId, $projectId) {
                $q->where('scope', 'organization');
                if ($clientId !== null) {
                    $q->orWhere(fn ($s) => $s->where('scope', 'client')->where('scope_id', $clientId));
                }
                if ($projectId !== null) {
                    $q->orWhere(fn ($s) => $s->where('scope', 'project')->where('scope_id', $projectId));
                }
            })
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->scope] = is_array($row->payload) ? $row->payload : [];
        }

        return $out;
    }

    /**
     * Recursive array merge where scalar leaves from $override win. Preserves untouched keys of $base.
     *
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $override
     * @return array<string,mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } elseif ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
