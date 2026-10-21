<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Http\Controllers;

use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Services\ShortLinkDestinations;
use App\Domains\ShortLinks\Services\ShortLinkHistory;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        private readonly ShortLinkHistory $history,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $links = ShortLink::query()->latest()->limit(self::PAGE)->get();

        /*
         * SHORT-LINK-HOPS-001 — the counter and the recorded history, side by side.
         *
         * `clicks` covers all of time; `recorded` covers the period this installation has been
         * writing follows down. For any link older than that table the first is larger, and the
         * difference is not an error — it is the part of the link's life nobody timed. The meta
         * carries `recording_since` so a surface can say which period its curve speaks for instead
         * of drawing a line across a window it cannot account for.
         */
        $since = $this->history->recordingSince();
        $recorded = $this->history->recordedTotals($links->map(fn (ShortLink $l): string => (string) $l->getKey())->all());

        /* The workspace's own curve over the last 30 days — filled only from the day recording began. */
        $to = Carbon::now();
        $from = $to->copy()->subDays(29);
        $series = $this->history->fill($this->history->dailyForTenant($from, $to), $from, $to, $since);

        return ApiResponse::success(
            $links->map(fn (ShortLink $l): array => $this->shape($l, $recorded[(string) $l->getKey()] ?? 0))->all(),
            'Short links retrieved.',
            [
                'total' => ShortLink::query()->count(),
                'limit' => self::PAGE,
                'recording_since' => $since?->toIso8601String(),
                /*
                 * Empty when nothing has been recorded, which is NOT a flat line at zero. The page
                 * draws no trend in that case and says why — the links may well have been followed.
                 */
                'daily' => $series,
            ],
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
            'message' => ['nullable', 'string', 'max:1000'],
            'mode' => ['nullable', 'string', 'in:'.ShortLink::MODE_REDIRECT.','.ShortLink::MODE_LANDING],
        ]);

        $resolved = $this->destinations->resolve((string) $data['kind'], (string) $data['value'], $data['message'] ?? null);
        $resolved['mode'] = (string) ($data['mode'] ?? ShortLink::MODE_REDIRECT);

        $link = ShortLink::create($resolved + [
            'tenant_id' => (string) app(TenantContext::class)->tenantId(),
            'slug' => $this->hops->mint(),
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        return ApiResponse::success($this->shape($link), 'Short link created.', status: 201);
    }

    /**
     * SHORT-LINK-HOPS-001 — GET short-links/{link}/history: when this link was followed.
     *
     * The window is the caller's, defaulting to the last 30 days. Days with no follows come back as
     * zero HERE, because this endpoint also returns the boundary that makes a zero readable: a day
     * inside the recorded period with no follows really was quiet, and a day before it is not
     * reported at all rather than reported as nothing.
     */
    /**
     * PATCH short-links/{link} — change a WhatsApp link's pre-filled message or its mode.
     *
     * The slug and the recipient never change: a link already sent to people must keep opening the
     * same chat, and only the words waiting in the composer are the Owner's to edit.
     */
    public function update(Request $request, string $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.update'), 403);

        $model = ShortLink::query()->findOrFail($link);

        $data = $request->validate([
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'mode' => ['sometimes', 'string', 'in:'.ShortLink::MODE_REDIRECT.','.ShortLink::MODE_LANDING],
        ]);

        if (array_key_exists('message', $data)) {
            abort_unless($model->kind === ShortLink::KIND_WHATSAPP, 422, 'Only a WhatsApp link carries a message.');

            $resolved = $this->destinations->resolve(ShortLink::KIND_WHATSAPP, (string) $model->source_value, $data['message']);
            $model->forceFill(['destination' => $resolved['destination'], 'message' => $resolved['message']]);
        }

        if (isset($data['mode'])) {
            $model->forceFill(['mode' => $data['mode']]);
        }

        $model->save();

        return ApiResponse::success($this->shape($model->refresh()), 'Short link updated.');
    }

    public function history(Request $request, string $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        /* Through the tenant scope: another tenant's link is a 404, not a refusal naming the id. */
        $model = ShortLink::query()->findOrFail($link);

        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $to = isset($data['to']) ? Carbon::parse((string) $data['to']) : Carbon::now();
        $from = isset($data['from']) ? Carbon::parse((string) $data['from']) : $to->copy()->subDays(29);

        $since = $this->history->recordingSince();
        $daily = $this->history->dailyFor((string) $model->getKey(), $from, $to);

        /*
         * The series is filled only from the day recording began. Before that there is no row to
         * find and no zero to report — the link may well have been followed, and saying «0» would
         * turn «we were not writing this down» into «nobody came», which is the one substitution
         * this product never makes.
         */
        $series = $this->history->fill($daily, $from, $to, $since);
        $recordedInWindow = array_sum($daily);

        return ApiResponse::success(
            [
                'id' => (string) $model->getKey(),
                'slug' => $model->slug,
                'series' => $series,
                /* All of time, from the counter — the number the link's own row shows. */
                'clicks_all_time' => $model->clicks,
                /* Only what was placed in time, inside this window. */
                'recorded_in_window' => $recordedInWindow,
            ],
            'Short link history retrieved.',
            [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'recording_since' => $since?->toIso8601String(),
                /*
                 * The honest headline: how much of this link's life the curve speaks for. A reader
                 * seeing 12 of 85 knows to treat the shape as recent history and not as the story.
                 */
                'unrecorded_before_window' => $since === null,
            ],
        );
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
     * SHORT-LINK-RESUME-001 — turning one back on.
     *
     * The same slug, the same destination, the same counters: a link switched off for a day (a sold-out
     * offer, a wrong message) resumes at the address people already hold, and the clicks it counted
     * before continue the same series. Nothing is minted; a disabled link that has been deleted is a 404
     * here like everywhere else on this controller.
     */
    public function enable(Request $request, string $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.update'), 403);

        $model = ShortLink::query()->findOrFail($link);
        $model->update(['is_active' => true]);

        return ApiResponse::success($this->shape($model->refresh()), 'Short link enabled.');
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
    private function shape(ShortLink $link, ?int $recorded = null): array
    {
        return [
            'id' => (string) $link->getKey(),
            'slug' => $link->slug,
            'kind' => $link->kind,
            'mode' => $link->mode ?? ShortLink::MODE_REDIRECT,
            'message' => $link->message,
            // SHORT-LINK-COMPLIANCE-001 — the proof, admin-side: where this hop really ends.
            'destination_host' => parse_url((string) $link->destination, PHP_URL_HOST) ?: null,
            'destination_is_whatsapp' => parse_url((string) $link->destination, PHP_URL_HOST) === 'wa.me',
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
            /*
             * How many of those follows this installation can place in time.
             *
             * Null where the caller did not ask for it, which is not the same as zero: `store()` and
             * `disable()` shape ONE link and do not run the history query, so they say «not asked»
             * rather than «none recorded». A surface that reads 0 here is being told a true thing.
             */
            'recorded_follows' => $recorded,
            'last_clicked_at' => $link->last_clicked_at?->toIso8601String(),
            'is_active' => $link->is_active,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
