<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Http\Controllers;

use App\Domains\Influencers\Models\Influencer;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The creator roster (INFL-001).
 *
 * Tenant-scoped by the model's global scope. The roster itself is NOT narrowed by client scope: a
 * creator is not owned by a client, and hiding the roster from an account manager would make them
 * re-add creators the agency already works with. What IS narrowed is the money and the client
 * relationship, which live on the collaboration.
 *
 * `internal_notes` is returned only to someone who may manage the roster. It is where "difficult to
 * work with" and "asked for double last time" get written, and it is never client-facing.
 */
final class InfluencerController extends Controller
{
    /** GET /api/v1/influencers/roster */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.view'), 403);

        $canManage = (bool) $user->hasPermission('influencers.manage');

        $query = Influencer::query()->orderBy('name');

        if (($status = $request->query('status')) !== null && $status !== '') {
            $query->where('status', $status);
        }
        if (($platform = $request->query('platform')) !== null && $platform !== '') {
            $query->where('primary_platform', $platform);
        }
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(handle) like ?', ['%'.mb_strtolower($term).'%']);
            });
        }

        $page = $query->withCount('collaborations')->paginate(25);

        return ApiResponse::success([
            'influencers' => collect($page->items())
                ->map(fn (Influencer $i) => $this->present($i, $canManage))->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'can_manage' => $canManage,
        ], 'Creator roster.');
    }

    /** POST /api/v1/influencers/roster */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.manage'), 403);

        $influencer = Influencer::create($this->validated($request));

        return ApiResponse::success(['influencer' => $this->present($influencer, true)], 'Creator added.', [], 201);
    }

    /** GET /api/v1/influencers/roster/{influencer} */
    public function show(Request $request, Influencer $influencer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.view'), 403);

        return ApiResponse::success(
            ['influencer' => $this->present($influencer->loadCount('collaborations'), (bool) $user->hasPermission('influencers.manage'))],
            'Creator.',
        );
    }

    /** PATCH /api/v1/influencers/roster/{influencer} */
    public function update(Request $request, Influencer $influencer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.manage'), 403);

        $influencer->fill($this->validated($request, partial: true))->save();

        return ApiResponse::success(['influencer' => $this->present($influencer->fresh(), true)], 'Creator updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'name' => [...$required, 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255'],
            'primary_platform' => ['nullable', 'string', 'max:64'],
            'profile_url' => ['nullable', 'url', 'max:2048'],
            'followers' => ['nullable', 'integer', 'min:0'],
            // A percentage, not a ratio — 4.25 means 4.25%. Bounded so a ratio pasted in is refused
            // rather than silently stored as a hundredth of what the user meant.
            'engagement_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tier' => ['nullable', 'string', 'max:64'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:64'],
            'country' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:16'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:64'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Influencer $i, bool $canManage): array
    {
        return array_merge([
            'id' => (string) $i->getKey(),
            'name' => $i->name,
            'handle' => $i->handle,
            'primary_platform' => $i->primary_platform,
            'profile_url' => $i->profile_url,
            'followers' => $i->followers,
            'engagement_rate' => $i->engagement_rate === null ? null : (string) $i->engagement_rate,
            'tier' => $i->tier,
            'categories' => $i->categories ?? [],
            'country' => $i->country,
            'language' => $i->language,
            'status' => $i->status,
            'collaborations_count' => (int) ($i->collaborations_count ?? 0),
        ], $canManage ? [
            // Contact details and private notes are for the people who run the roster, not everyone
            // who can read it.
            'contact_email' => $i->contact_email,
            'contact_phone' => $i->contact_phone,
            'internal_notes' => $i->internal_notes,
        ] : []);
    }
}
