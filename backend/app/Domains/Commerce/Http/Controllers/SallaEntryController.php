<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Commerce\Install\SallaMagicLink;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Frontend;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SALLA-ONBOARD-001 — the two public doors a Salla merchant uses.
 *
 * `open` is what the app's «Open app» link in the Salla dashboard points at. It cannot carry a signature (it
 * is one static address for every merchant), so it does not try to authenticate anybody: it sends the browser
 * to Salla's authorisation, which a merchant who has already installed the app approves without a prompt, and
 * Salla returns to the ordinary callback with a fresh code. THAT is the proof — Salla vouching for the merchant
 * who is sitting at the keyboard — and the callback then mints the single-use link below.
 *
 * `enter` redeems that link and opens a session. It runs under its own session middleware because the browser
 * arrives from the callback as a top-level navigation. Every failure is the same blank answer: the page never
 * says whether a link was forged, expired or already used.
 */
final class SallaEntryController extends Controller
{
    public function __construct(
        private readonly PortalResolver $portals,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /salla/open */
    public function open(): RedirectResponse
    {
        $creds = PlatformCredentials::for('salla');

        if (! $creds->isConfigured()) {
            return redirect()->away(Frontend::origin().'/login?salla=unavailable');
        }

        return redirect()->away($creds->authorizeUrl().'?'.http_build_query([
            'client_id' => $creds->get('client_id'),
            'redirect_uri' => $creds->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $creds->scopes()),
        ]));
    }

    /** GET /salla/enter?t=… */
    public function enter(Request $request): RedirectResponse
    {
        $redeemed = SallaMagicLink::redeem((string) $request->query('t', ''));
        $user = $redeemed === null ? null : User::query()->find($redeemed['user_id']);

        if ($user === null || $user->disabled_at !== null || (! $user->is_platform_admin && AccountSuspension::everyWorkspaceSuspendedFor($user))) {
            return redirect()->away(Frontend::origin().'/login?salla=expired');
        }

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        $this->audit->log(
            action: 'salla.magic_link.signed_in',
            entityType: User::class,
            entityId: (string) $user->getKey(),
            after: ['store' => $redeemed['store_id']],
            userId: (int) $user->getKey(),
        );

        return redirect()->to(Frontend::origin().$this->portals->landingPathFor($user));
    }
}
