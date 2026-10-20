<?php

declare(strict_types=1);

namespace App\Domains\Widgets\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Widgets\Models\WidgetLayout;
use App\Domains\Widgets\Services\WidgetLayouts;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * WIDGET-REGISTRY-001 — GET/PUT /projects/{project}/widgets/{surface}.
 *
 * Reading answers «what does this surface show, and what is switched off and why», resolved on the server so the page
 * never decides visibility itself. Writing needs `reports.manage` on the project, validates against the registry, and
 * is audited with its before and after.
 */
final class WidgetLayoutController extends Controller
{
    public function __construct(private readonly WidgetLayouts $layouts, private readonly AuditLogger $audit) {}

    public function show(string $project, string $surface): JsonResponse
    {
        [$tenant, $model] = $this->scope();
        abort_unless(in_array($surface, WidgetLayouts::SURFACES, true), 404);

        return ApiResponse::success([
            'surface' => $surface,
            'widgets' => $this->layouts->resolve($tenant, $model, $surface),
            'sizes' => WidgetLayouts::sizes(),
        ]);
    }

    public function update(Request $request, string $project, string $surface): JsonResponse
    {
        [$tenant, $model] = $this->scope();
        abort_unless(in_array($surface, WidgetLayouts::SURFACES, true), 404);

        $data = $request->validate([
            'items' => ['present', 'array', 'max:50'],
            'items.*.key' => ['required', 'string', 'max:64'],
            'items.*.visible' => ['nullable', 'boolean'],
            'items.*.order' => ['nullable', 'integer'],
            'items.*.size' => ['nullable', 'string', 'max:16'],
        ]);

        $before = WidgetLayout::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())->where('scope_type', WidgetLayout::PROJECT)->where('scope_id', $model->getKey())->where('surface', $surface)->first();

        try {
            $row = $this->layouts->save((string) $tenant->getKey(), WidgetLayout::PROJECT, (string) $model->getKey(), $surface, $data['items'], (int) $request->user()->getKey());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), ['items' => [$e->getMessage()]], status: 422);
        }

        $this->audit->log('widgets.layout_changed', 'project', (string) $model->getKey(), ['surface' => $surface, 'items' => $before?->items], ['surface' => $surface, 'items' => $row->items]);

        return $this->show($project, $surface);
    }

    /** @return array{0: Tenant, 1: Project} */
    private function scope(): array
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        $tenant = Tenant::query()->findOrFail($tenantId);
        $model = Project::query()->whereKey($projectId)->firstOrFail();

        return [$tenant, $model];
    }
}
