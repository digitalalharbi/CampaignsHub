<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Http\Controllers;

use App\Domains\Influencers\Actions\LinkCreatorAccount;
use App\Domains\Influencers\Models\Influencer;
use App\Http\Controllers\Controller;
use App\Rules\PhoneNumberRule;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

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
            'contact_phone' => ['nullable', 'string', 'max:32', new PhoneNumberRule],
            'status' => ['nullable', 'string', 'max:64'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /** @return array<string, mixed> */
    /**
     * POST /api/v1/influencers/roster/{influencer}/access — let this creator into their own portal.
     *
     * Reports honestly: `created_account` says whether a login was made, and nothing here claims an
     * invitation was emailed, because none is sent. The creator reaches their portal through the
     * ordinary password-reset flow, and telling the operator otherwise would have them waiting for a
     * message that does not exist.
     */
    public function grantAccess(Request $request, string $influencer, LinkCreatorAccount $link): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.manage'), 403);

        $model = Influencer::query()->whereKey($influencer)->first();
        abort_if($model === null, 404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $result = $link->execute($model, $data['email'], $user);
        } catch (RuntimeException $e) {
            // 422 with the actual reason. A generic failure here would leave an operator retrying a
            // link that will never succeed, with no way to learn that the address belongs to staff.
            return ApiResponse::error($e->getMessage(), null, [], 422);
        }

        return ApiResponse::success([
            'influencer' => $this->present($model->fresh()->loadCount('collaborations'), true),
            'created_account' => $result['created_account'],
            'sign_in_email' => $result['user']->email,
            // Named for what actually happens rather than what an operator might assume.
            'delivery' => 'not_sent',
        ], 'Portal access granted.');
    }

    /** DELETE /api/v1/influencers/roster/{influencer}/access */
    public function revokeAccess(Request $request, string $influencer, LinkCreatorAccount $link): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.manage'), 403);

        $model = Influencer::query()->whereKey($influencer)->first();
        abort_if($model === null, 404);

        $link->unlink($model);

        return ApiResponse::success(
            ['influencer' => $this->present($model->fresh()->loadCount('collaborations'), true)],
            'Portal access withdrawn.',
        );
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
            // Whether this creator can sign in and see their own work (INFL-002). A boolean, not the
            // account: the roster list is not the place to publish someone's login address.
            'has_portal_access' => $i->hasPortalAccess(),
        ], $canManage ? [
            // Contact details and private notes are for the people who run the roster, not everyone
            // who can read it.
            'contact_email' => $i->contact_email,
            'contact_phone' => $i->contact_phone,
            'internal_notes' => $i->internal_notes,
        ] : []);
    }
}
