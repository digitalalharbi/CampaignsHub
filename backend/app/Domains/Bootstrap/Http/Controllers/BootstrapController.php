<?php

declare(strict_types=1);

namespace App\Domains\Bootstrap\Http\Controllers;

use App\Domains\Access\Http\Controllers\MyAccessController;
use App\Domains\Access\Support\AccessSections;
use App\Domains\Funding\Http\Controllers\FundingController;
use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Notifications\Services\SoundPreferences;
use App\Domains\Settings\Http\Controllers\BrandingController;
use App\Domains\Subscriptions\Http\Controllers\SubscriptionController;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Http\Controllers\MembershipController;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * PERF-BOOTSTRAP-001 — GET /api/v1/bootstrap: the seven things every page load asks first, in one request.
 *
 * Measured on /agency/dashboard: thirteen parallel API calls on a 1-vCPU host with five PHP workers, and the cheapest of them
 * (branding, access, the subscription) took 1.0–1.4 s inside the burst against ~0.2 s alone — they were waiting for a worker, not
 * working. Seven of the thirteen are the same seven on every page, so they are answered by ONE request here.
 *
 * ## Every section is authorised exactly as its own endpoint is
 *
 * This is not a second implementation. Each section is produced by the very controller action behind the endpoint it replaces
 * (so the two cannot drift apart: `BootstrapTest` holds them byte-equal), and is gated by the same three things that endpoint's
 * route and action apply, evaluated on THIS request:
 *
 *  1. the **portal** — the route group's `portal:` list. Unlike `EnsurePortal`, a section whose portal is not the ACTIVE membership's
 *     is left out rather than served from another membership: the page then asks that endpoint itself, which does the swap;
 *  2. the **section switch** — `EnforceSectionAccess`, from the section the route name belongs to (`AccessSections::forRoute`);
 *  3. the **permission** — the action's own `hasPermission` (`subscriptions.view`), enforced inside the action.
 *
 * A section the caller may not read is `null` — never partial, never another tenant's. `null` is also what a section that failed
 * answers (the failure is reported, the others still go out), and the client treats `null` as «ask the endpoint yourself», which
 * then answers with its own 403 or its own error, exactly as before. Tenant scoping is the bound tenant of the request, as everywhere.
 */
final class BootstrapController extends Controller
{
    /**
     * Each section: the route of the endpoint it replaces, and the portals that route's group accepts (null: it names none).
     * Copied from the router and held against it by `BootstrapTest::test_every_section_is_gated_like_the_route_it_replaces`.
     *
     * @var array<string, array{0: string, 1: list<Portal>|null}>
     */
    public const ENDPOINTS = [
        'me' => ['api.v1.auth.me', null],
        'memberships' => ['api.v1.auth.memberships.index', null],
        'access' => ['api.v1.me.access', null],
        'subscription' => ['api.v1.subscriptions.current', [Portal::App, Portal::Agency]],
        'branding' => ['api.v1.settings.branding.show', [Portal::App, Portal::Agency, Portal::Influencers]],
        'notifications_summary' => ['api.v1.notifications.summary', [Portal::App, Portal::Agency, Portal::Influencers, Portal::ClientPortal]],
        'funding_disclaimer' => ['api.v1.funding.disclaimer', [Portal::App, Portal::Agency]],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        abort_if($request->user() === null, 401);

        $sections = [
            // GET /auth/me.
            'me' => $this->section($request, 'me', fn () => $this->unwrap(app(AuthController::class)->me($request))),
            // GET /auth/memberships (bootstrap carries no ?portal / ?intended, as the app's own call carries none).
            'memberships' => $this->section($request, 'memberships', fn () => $this->unwrap(app(MembershipController::class)->index($request))),
            // GET /me/access.
            'access' => $this->section($request, 'access', fn () => app(MyAccessController::class)->payload($request, app(ClientScopeResolver::class))),
            // GET /subscriptions/current — `subscriptions.view` is enforced inside the action.
            'subscription' => $this->section($request, 'subscription', fn () => app(SubscriptionController::class)->currentPayload($request)),
            // GET /settings/branding.
            'branding' => $this->section($request, 'branding', fn () => ['branding' => app(BrandingController::class)->branding()]),
            // GET /notifications/summary.
            'notifications_summary' => $this->section($request, 'notifications_summary', fn () => $this->unwrap(app(NotificationController::class)->summary($request, app(SoundPreferences::class)))),
            // GET /funding/disclaimer — the `budgets` section switch covers every `api.v1.funding.*` route.
            'funding_disclaimer' => $this->section($request, 'funding_disclaimer', fn () => app(FundingController::class)->disclaimerText()),
        ];

        return ApiResponse::success($sections, 'Bootstrap.');
    }

    /**
     * One section: null unless the caller passes the gates of the endpoint it stands for, and null if producing it fails.
     *
     * @param  Closure(): mixed  $produce
     */
    private function section(Request $request, string $key, Closure $produce): mixed
    {
        [$routeName, $portals] = self::ENDPOINTS[$key];

        if (! $this->permitted($request, $portals, $routeName)) {
            return null;
        }

        try {
            return $produce();
        } catch (Throwable $e) {
            // A refusal the action itself raises (403 for a missing permission) is the answer «not for you»; anything else is a fault.
            if (! $e instanceof HttpException) {
                report($e);
            }

            return null;
        }
    }

    /** @param  list<Portal>|null  $portals */
    private function permitted(Request $request, ?array $portals, string $routeName): bool
    {
        /** @var User $user */
        $user = $request->user();

        if ($portals !== null) {
            // `EnsurePortal`: a portal that is switched off is closed to everybody; the rest must include the ACTIVE membership's.
            $open = array_filter($portals, static fn (Portal $p): bool => $p->isEnabled());
            $active = app(MembershipContext::class)->membership();
            if ($open === [] || $active === null || ! in_array($active->portal, $open, true)) {
                return false;
            }
        }

        // `EnforceSectionAccess`: a section switched off for this member refuses every route of that section.
        $section = AccessSections::forRoute($routeName);
        if ($section !== null && $user->sectionSwitchedOff($section)) {
            return false;
        }

        return true;
    }

    /** The `data` of an action's JSON envelope. */
    private function unwrap(JsonResponse $response): mixed
    {
        // Decoded as objects, so an empty JSON object stays `{}` when it is encoded again, byte for byte as the endpoint wrote it.
        return $response->getData(false)->data ?? null;
    }
}
