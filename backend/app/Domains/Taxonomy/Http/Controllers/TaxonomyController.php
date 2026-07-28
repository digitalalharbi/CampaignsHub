<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Http\Controllers;

use App\Domains\Taxonomy\Exceptions\TaxonomyException;
use App\Domains\Taxonomy\Services\TaxonomyService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-facing Taxonomy & Option management. Reads need taxonomies.view; each mutation needs its own
 * permission (options.create / options.update / options.reorder / options.merge / options.deactivate, and
 * options.manage_system_labels for editing a system option). Tenant isolation is enforced by the service,
 * which resolves options across the tenant global scope but re-asserts "platform ∪ current tenant" itself.
 */
final class TaxonomyController extends Controller
{
    public function __construct(private readonly TaxonomyService $taxonomy) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('taxonomies.view'), 403);

        $module = $request->string('module')->toString() ?: null;

        return $this->guard(fn () => ApiResponse::success(
            $this->taxonomy->definitions($module)->all(),
            'Taxonomy definitions.',
        ));
    }

    public function options(Request $request, string $key): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('taxonomies.view'), 403);

        return $this->guard(fn () => ApiResponse::success(
            $this->taxonomy->options($key)->all(),
            'Taxonomy options.',
        ));
    }

    public function storeOption(Request $request, string $key): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('options.create'), 403);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:128'],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:64'],
            'parent_option_id' => ['nullable', 'uuid'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        return $this->guard(fn () => ApiResponse::success(
            $this->taxonomy->createOption($key, $data),
            'Option created.',
            status: 201,
        ));
    }

    public function updateOption(Request $request, string $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id): JsonResponse {
            $option = $this->taxonomy->findOption($id);

            // A system option's labels are gated by the stricter manage_system_labels permission.
            $permission = $option->is_system ? 'options.manage_system_labels' : 'options.update';
            abort_unless((bool) $request->user()?->hasPermission($permission), 403);

            $data = $request->validate([
                'label_ar' => ['sometimes', 'string', 'max:255'],
                'label_en' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'color' => ['sometimes', 'nullable', 'string', 'max:32'],
                'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
                'is_active' => ['sometimes', 'boolean'],
                'is_default' => ['sometimes', 'boolean'],
                'sort_order' => ['sometimes', 'integer', 'min:0'],
                'parent_option_id' => ['sometimes', 'nullable', 'uuid'],
                'metadata' => ['sometimes', 'nullable', 'array'],
            ]);

            return ApiResponse::success($this->taxonomy->updateOption($option, $data), 'Option updated.');
        });
    }

    public function reorder(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('options.reorder'), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ]);

        return $this->guard(function () use ($data): JsonResponse {
            $this->taxonomy->reorder($data['ids']);

            return ApiResponse::success(null, 'Options reordered.');
        });
    }

    public function merge(Request $request, string $id): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('options.merge'), 403);

        $data = $request->validate(['into' => ['required', 'uuid']]);

        return $this->guard(fn () => ApiResponse::success(
            $this->taxonomy->merge($id, $data['into']),
            'Option merged.',
        ));
    }

    public function reassign(Request $request, string $id): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('options.merge'), 403);

        $data = $request->validate(['into' => ['required', 'uuid']]);

        return $this->guard(fn () => ApiResponse::success(
            $this->taxonomy->reassign($id, $data['into']),
            'Records reassigned.',
        ));
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('options.deactivate'), 403);

        return $this->guard(fn () => ApiResponse::success(
            $this->taxonomy->deactivate($this->taxonomy->findOption($id)),
            'Option deactivated.',
        ));
    }

    public function usage(Request $request, string $id): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('taxonomies.view'), 403);

        return $this->guard(fn () => ApiResponse::success(
            ['option_id' => $id, 'usage_count' => $this->taxonomy->usage($id)],
            'Option usage.',
        ));
    }

    /**
     * Run a controller action and translate a domain TaxonomyException into the API error envelope with its
     * intended HTTP status (404 unknown, 409 in-use, 422 rule violation).
     *
     * @param  callable():JsonResponse  $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (TaxonomyException $e) {
            return ApiResponse::error($e->getMessage(), status: $e->status());
        }
    }
}
