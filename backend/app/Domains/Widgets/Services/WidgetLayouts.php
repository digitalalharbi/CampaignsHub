<?php

declare(strict_types=1);

namespace App\Domains\Widgets\Services;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Widgets\Models\WidgetLayout;
use InvalidArgumentException;

/**
 * WIDGET-REGISTRY-001 — the one answer to «which widgets does this surface show here, and in what order?».
 *
 * Resolution runs in a fixed order and each step can only NARROW what the one before allowed:
 *
 *   1. the registry says which widgets exist for the surface and what their defaults are;
 *   2. the capability registry says whether the widget's module is on for this project — a widget whose module is
 *      off is `module_off`, listed (so an owner can see what they could switch on) on the agency surfaces and absent
 *      on the client-facing one;
 *   3. the project's own layout may hide a widget, reorder it, or resize it;
 *   4. on a SHARED LINK (the client-facing surface, with a link given) the project's layout is a permission, not a
 *      choice: a widget shows only if the project allows it AND the link's owner switched it on. A link can never show
 *      one the project forbids, and shows none until it is told to — otherwise every link shared before a project
 *      enabled anything would start showing it the moment the project did.
 *
 * Hiding a widget hides a picture. It never edits, removes or recomputes the figures underneath it; the figures a
 * hidden widget would have drawn are still in every total that was there before.
 */
final class WidgetLayouts
{
    public const SURFACES = ['dashboard', 'analytics', 'report'];

    public function __construct(private readonly Capabilities $capabilities) {}

    /** @return array<string, array<string,mixed>> */
    public static function catalogue(): array
    {
        /** @var array<string, array<string,mixed>> $widgets */
        $widgets = (array) config('widgets.widgets', []);

        return $widgets;
    }

    /** @return list<string> */
    public static function sizes(): array
    {
        return array_values(array_map('strval', (array) config('widgets.sizes', ['third', 'half', 'full'])));
    }

    /**
     * @return list<array{key: string, visible: bool, order: int, size: string, kind: string, module: ?string, reason: ?string, title: array<string,string>}>
     */
    public function resolve(Tenant $tenant, Project $project, string $surface, ?string $shareId = null): array
    {
        if (! in_array($surface, self::SURFACES, true)) {
            throw new InvalidArgumentException("Unknown surface: {$surface}");
        }

        $states = $this->capabilities->resolve($tenant, (string) $project->client_workspace_id, (string) $project->getKey());
        $projectItems = $this->items(WidgetLayout::PROJECT, (string) $project->getKey(), $surface, (string) $tenant->getKey());
        $linkItems = $shareId === null ? [] : $this->items(WidgetLayout::SHARE_LINK, $shareId, $surface, (string) $tenant->getKey());
        $clientFacing = $surface === 'report';

        $out = [];
        foreach (self::catalogue() as $key => $def) {
            if (! in_array($surface, (array) ($def['surfaces'] ?? []), true)) {
                continue;
            }

            $default = (array) ($def['defaults'][$surface] ?? []);
            $visible = ($default['visible'] ?? true) === true;
            $order = (int) ($default['order'] ?? 100);
            $size = (string) ($default['size'] ?? 'full');
            $reason = null;

            $module = $def['module'] ?? null;
            if (is_string($module) && ! (($states[$module] ?? null)?->enabled === true)) {
                if ($clientFacing) {
                    continue;
                }
                $visible = false;
                $reason = 'module_off';
            }

            if ($shareId !== null && $clientFacing) {
                /*
                 * A SHARED LINK is the strictest case, and it is opt-in. The project can forbid a widget on client
                 * links (a layout entry with visible false) and otherwise allows it; the link itself shows nothing
                 * until its owner switches a widget on. Otherwise every link shared before a project enabled
                 * anything would start showing it the moment the project did, to people who were never told.
                 */
                $allowed = ! isset($projectItems[$key]) || $projectItems[$key]['visible'];
                $enabled = isset($linkItems[$key]) && $linkItems[$key]['visible'];
                if (isset($linkItems[$key])) {
                    $order = $linkItems[$key]['order'];
                    $size = $linkItems[$key]['size'];
                } elseif (isset($projectItems[$key])) {
                    $order = $projectItems[$key]['order'];
                    $size = $projectItems[$key]['size'];
                }
                if ($reason === null) {
                    $visible = $allowed && $enabled;
                    $reason = ! $allowed ? 'hidden_by_project' : (! $enabled ? 'hidden_by_link' : null);
                }
            } elseif (isset($projectItems[$key])) {
                $order = $projectItems[$key]['order'];
                $size = $projectItems[$key]['size'];
                if ($reason === null) {
                    $visible = $projectItems[$key]['visible'];
                    $reason = $visible ? null : 'hidden_by_project';
                }
            }

            $out[] = [
                'key' => $key,
                'visible' => $visible,
                'order' => $order,
                'size' => $size,
                'kind' => (string) ($def['kind'] ?? 'kpis'),
                'module' => is_string($module) ? $module : null,
                'reason' => $reason,
                'title' => (array) ($def['title'] ?? []),
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return $out;
    }

    /**
     * Store what someone chose. Refuses a widget the registry does not know for this surface, a duplicate and a size
     * that is not one of the registry's.
     *
     * @param  list<array<string,mixed>>  $items
     */
    public function save(string $tenantId, string $scopeType, string $scopeId, string $surface, array $items, ?int $userId): WidgetLayout
    {
        if (! in_array($surface, self::SURFACES, true)) {
            throw new InvalidArgumentException("Unknown surface: {$surface}");
        }

        $catalogue = self::catalogue();
        $clean = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if (! isset($catalogue[$key]) || ! in_array($surface, (array) ($catalogue[$key]['surfaces'] ?? []), true)) {
                throw new InvalidArgumentException("Unknown widget for {$surface}: {$key}");
            }
            if (isset($clean[$key])) {
                throw new InvalidArgumentException("Widget listed twice: {$key}");
            }
            $size = (string) ($item['size'] ?? ($catalogue[$key]['defaults'][$surface]['size'] ?? 'full'));
            if (! in_array($size, self::sizes(), true)) {
                throw new InvalidArgumentException("Unknown size for {$key}: {$size}");
            }
            $clean[$key] = [
                'key' => $key,
                'visible' => ($item['visible'] ?? true) === true,
                'order' => max(0, min(1000, (int) ($item['order'] ?? 100))),
                'size' => $size,
            ];
        }

        return WidgetLayout::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'surface' => $surface],
            ['items' => array_values($clean), 'updated_by' => $userId],
        );
    }

    /** @return array<string, array{key: string, visible: bool, order: int, size: string}> */
    private function items(string $scopeType, string $scopeId, string $surface, string $tenantId): array
    {
        $row = WidgetLayout::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('surface', $surface)
            ->first();

        $out = [];
        foreach (($row instanceof WidgetLayout ? $row->items : []) as $item) {
            $out[(string) $item['key']] = $item;
        }

        return $out;
    }
}
