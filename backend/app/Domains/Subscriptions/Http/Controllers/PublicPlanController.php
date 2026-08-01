<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The plans a visitor may choose, before they have an account (PLAN-001).
 *
 * Public because the pricing page and the sign-up form are, and both have to quote the SAME figures
 * the checkout will charge. A catalogue duplicated into the marketing site is a catalogue that will
 * eventually advertise a price nobody is billed.
 *
 * Only `is_public` plans appear, so a plan withdrawn from sale stops being offered without stranding
 * the customers already on it.
 */
final class PublicPlanController extends Controller
{
    public function __construct(private readonly PlanCatalogue $catalogue) {}

    /** GET /api/v1/plans */
    public function index(): JsonResponse
    {
        return ApiResponse::success(['plans' => $this->catalogue->toArray()], 'Plans.');
    }

    /**
     * GET /api/v1/plans/{code}/quote?interval=monthly|annual
     *
     * What choosing this plan on this term actually commits the customer to — what is taken today,
     * what falls due later, and when. One structure, so the figure quoted and the figure charged
     * cannot disagree.
     */
    public function quote(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['interval' => ['sometimes', 'in:monthly,annual']]);
        $interval = $data['interval'] ?? 'monthly';

        $plan = $this->catalogue->byCode($code);

        if ($plan === null || ! $plan->is_active || ! $plan->is_public) {
            return ApiResponse::error(__('billing.plan_not_available'), status: 404);
        }

        $quote = $this->catalogue->quote($plan, $interval);

        if ($quote === null) {
            // Not a 404: the plan exists, the TERM does not. Falling back to the other term's price
            // would quote a figure the customer did not ask for.
            return ApiResponse::error(__('billing.plan_term_not_sold'), status: 422);
        }

        return ApiResponse::success(['quote' => $quote], 'Quote.');
    }
}
