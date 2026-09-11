<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Http\Controllers;

use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Services\ShortLinkDestinations;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SHORT-LINKS-001 — two fields, one action, and a list that shows only what a person acts on.
 *
 * The API is deliberately small because the feature is. There is no update endpoint and no slug
 * parameter: the owner ruled out custom slugs, redirect types and tracking configuration by name,
 * and an endpoint that accepted them would be the place they came back.
 */
final class ShortLinkController extends Controller
{
    /** The cap exists so a workspace with a thousand links returns a page rather than all of them. */
    private const PAGE = 100;

    public function __construct(
        private readonly ShortLinkDestinations $destinations,
        private readonly ShortLinkHops $hops,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $links = ShortLink::query()->latest()->limit(self::PAGE)->get();

        return ApiResponse::success(
            $links->map(fn (ShortLink $l): array => $this->shape($l))->all(),
            'Short links retrieved.',
            ['total' => ShortLink::query()->count(), 'limit' => self::PAGE],
        );
    }

    /**
     * Create one, from the kind and the single value the person typed.
     *
     * `value` rather than `phone` or `url`: the form has ONE field whose meaning follows the choice
     * above it, and naming it after either kind would put the technical distinction back into the
     * request the interface exists to hide.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.update'), 403);

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.ShortLink::KIND_WHATSAPP.','.ShortLink::KIND_LINK],
            'value' => ['required', 'string', 'max:2048'],
        ]);

        $resolved = $this->destinations->resolve((string) $data['kind'], (string) $data['value']);

        $link = ShortLink::create($resolved + [
            'tenant_id' => (string) app(TenantContext::class)->tenantId(),
            'slug' => $this->hops->mint(),
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        return ApiResponse::success($this->shape($link), 'Short link created.', status: 201);
    }

    /**
     * Turning one off rather than deleting it.
     *
     * A short link that has been sent to people cannot be un-sent, so removing the row would turn
     * every copy of it into a broken address with nothing to explain it. Disabled, the platform still
     * owns the hop and can say what happened.
     */
    public function disable(Request $request, string $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.update'), 403);

        $model = ShortLink::query()->findOrFail($link);
        $model->update(['is_active' => false]);

        return ApiResponse::success($this->shape($model->refresh()), 'Short link disabled.');
    }

    /**
     * DELETE short-links/{link} — remove it from the library.
     *
     * «Disable» stops a link resolving and leaves it on the screen; this takes it off the screen and
     * stops it resolving, which is what somebody means by «delete that». It is a SOFT delete, so the
     * clicks the platform counted survive as audit — removing the link and losing the record that it
     * existed are different things.
     *
     * `findOrFail` runs through the tenant scope, so another tenant's link is a 404 rather than a
     * refusal that confirms the id exists. The permission is checked first, and both are the
     * SERVER's: the row's delete control is presentation.
     */
    public function destroy(Request $request, string $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.update'), 403);

        ShortLink::query()->findOrFail($link)->delete();

        return ApiResponse::success(null, 'Short link deleted.');
    }

    /** @return array<string, mixed> */
    private function shape(ShortLink $link): array
    {
        return [
            'id' => (string) $link->getKey(),
            'slug' => $link->slug,
            'kind' => $link->kind,
            'short_url' => $this->hops->shareUrl($link),
            /*
             * What they typed, not what we derived.
             *
             * Showing `https://wa.me/9665…` back to somebody who entered a phone number is showing
             * them our internals; the destination is still carried for a Link, where the two are the
             * same thing and the address IS what they chose.
             */
            'shows' => $link->source_value ?? $link->destination,
            'clicks' => $link->clicks,
            'last_clicked_at' => $link->last_clicked_at?->toIso8601String(),
            'is_active' => $link->is_active,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
