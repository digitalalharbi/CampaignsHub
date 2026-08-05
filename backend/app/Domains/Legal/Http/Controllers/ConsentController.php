<?php

declare(strict_types=1);

namespace App\Domains\Legal\Http\Controllers;

use App\Domains\Legal\PolicyRegistry;
use App\Domains\Legal\Services\AcceptanceRecorder;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LEGAL-003 — the binding policies a signed-in user still owes, and accepting them.
 *
 * ## Why there is no cookie-consent endpoint here
 *
 * There is nothing to consent to. This application sets strictly necessary cookies only — session,
 * CSRF, language and theme — and no analytics or marketing cookie exists to be gated. A consent
 * banner in front of cookies a visitor cannot refuse (because the site does not work without them)
 * asks a question with one answer, and a consent RECORD for a decision nobody was offered is worse:
 * it is evidence of a choice that never happened.
 *
 * If a non-essential cookie is ever introduced, the consent mechanism has to come with it, in the
 * same change — not be left standing here waiting to legitimise one.
 */
final class ConsentController extends Controller
{
    public function __construct(private readonly AcceptanceRecorder $acceptances) {}

    /**
     * The binding documents this signed-in user still has to accept.
     *
     * Read by the app shell so a new terms version can be put in front of somebody who is already
     * signed in, instead of waiting until their next payment.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'outstanding' => $user ? $this->acceptances->outstandingFor($user) : PolicyRegistry::binding(),
            'documents' => PolicyRegistry::all(),
        ], 'Outstanding acceptances.');
    }

    /** Accept the current versions while signed in — the re-acceptance path. */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accepted_policies' => ['required', 'array'],
            'accepted_policies.*' => ['string'],
        ]);

        $this->acceptances->recordBinding(
            request: $request,
            context: 'reacceptance',
            user: $request->user(),
            accepted: $data['accepted_policies'],
        );

        return ApiResponse::success(['outstanding' => []], 'Recorded.');
    }
}
