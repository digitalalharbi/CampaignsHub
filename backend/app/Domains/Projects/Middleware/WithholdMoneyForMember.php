<?php

declare(strict_types=1);

namespace App\Domains\Projects\Middleware;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MEMBER-VISIBILITY-001 — a member whose membership withholds `money.view` never receives a money figure
 * from a project-scoped answer, whichever endpoint served it.
 *
 * ## Why a response layer and not a per-reader flag
 *
 * Money is served by dozens of readers (metrics, campaigns, entities, creatives, budgets, reports,
 * recommendations…). A flag threaded through each would be forgotten by the next one — the client-link
 * redaction already proved that a value hidden in one reader leaks through the next. This layer sees
 * the finished JSON of every project route and strips money by KEY: spend, revenue, budgets, costs and
 * every ratio over them. Nothing is recomputed; a subtotal with its money removed is a subtotal with
 * its money removed, and `meta.money_withheld` says so to the page.
 *
 * Exports (CSV, XLSX, PDF) are refused outright for such a member: a file is the one shape this
 * layer cannot redact after the fact.
 */
final class WithholdMoneyForMember
{
    /** A key is money by name: the figure itself, a budget, a cost, or a ratio over spend. */
    public const MONEY_KEY = '/^(spend|revenue|budget|cost|roas|cpa|cpc|cpm|cpl|cpi|cpe|aov)$|spend|revenue|budget|cost_per|^cost|_cost$|roas|aov|price|fee|commission|margin|profit/i';

    public function __construct(
        private readonly ProjectAbilities $abilities,
        private readonly ProjectContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $projectId = $this->context->projectId() ?? (string) $request->route('project');

        if ($user === null || $projectId === '' || $this->abilities->allows($user, $projectId, ProjectCapability::MONEY_VIEW)) {
            return $next($request);
        }

        if ($this->isExport($request)) {
            abort(403, 'Money figures are withheld for your membership on this project, and an export would carry them.');
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $payload = $response->getData(true);
            if (is_array($payload) && array_key_exists('data', $payload)) {
                $payload['data'] = self::strip($payload['data']);
                $payload['meta'] = (is_array($payload['meta'] ?? null) ? $payload['meta'] : []) + ['money_withheld' => true];
                $response->setData($payload);
            }
        }

        return $response;
    }

    private function isExport(Request $request): bool
    {
        $path = $request->path();

        return (bool) preg_match('#/(export|download|csv|xlsx|pdf)(/|$)#i', '/'.$path);
    }

    /** Money keys removed recursively; everything else untouched. */
    public static function strip(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::MONEY_KEY, $key) === 1) {
                continue;
            }
            $out[$key] = self::strip($item);
        }

        return $out;
    }
}
