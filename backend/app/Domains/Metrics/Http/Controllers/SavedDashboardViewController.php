<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Metrics\Models\SavedDashboardView;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DASH-010-E — saved dashboard views (persisted server-side, per user + tenant). Every query is constrained
 * to the authenticated user's own views (on top of the tenant global scope), so views never leak across
 * users or tenants. Exactly one default per (user, module) is enforced by a DB partial-unique index.
 */
final class SavedDashboardViewController extends Controller
{
    /** Base query: only the current user's views (tenant scope applied by the model). */
    private function owned(Request $request): Builder
    {
        return SavedDashboardView::query()->where('user_id', $request->user()->id);
    }

    private function find(Request $request, string $id): SavedDashboardView
    {
        $model = $this->owned($request)->find($id);
        abort_if($model === null, 404); // another user's/tenant's view is invisible → 404, never mutated.

        return $model;
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'module' => ['sometimes', 'string', 'max:60'],
            'filters' => ['nullable', 'array'],
            'date_range' => ['nullable', 'array'],
            'comparison' => ['nullable', 'array'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $module = (string) $request->query('module', 'dashboard');

        return ApiResponse::success(
            $this->owned($request)->where('module', $module)->orderBy('sort_order')->orderBy('created_at')->get()->all(),
            'Saved dashboard views.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: false));

        $view = SavedDashboardView::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'module' => $data['module'] ?? 'dashboard',
            'filters' => $data['filters'] ?? null,
            'date_range' => $data['date_range'] ?? null,
            'comparison' => $data['comparison'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return ApiResponse::success($view, 'Dashboard view saved.', status: 201);
    }

    public function show(Request $request, string $view): JsonResponse
    {
        return ApiResponse::success($this->find($request, $view), 'Saved dashboard view.');
    }

    public function update(Request $request, string $view): JsonResponse
    {
        $model = $this->find($request, $view);
        $model->update($request->validate($this->rules(partial: true)));

        return ApiResponse::success($model->fresh(), 'Dashboard view updated.');
    }

    public function destroy(Request $request, string $view): JsonResponse
    {
        $this->find($request, $view)->delete();

        return ApiResponse::success(['deleted' => true], 'Dashboard view deleted.');
    }

    /** Make this the single default for (user, module) — clears any other default first (index enforces it). */
    public function setDefault(Request $request, string $view): JsonResponse
    {
        $model = $this->find($request, $view);

        DB::transaction(function () use ($request, $model): void {
            $this->owned($request)->where('module', $model->module)->where('id', '!=', $model->id)->update(['is_default' => false]);
            $model->update(['is_default' => true]);
        });

        return ApiResponse::success($model->fresh(), 'Default dashboard view set.');
    }
}
