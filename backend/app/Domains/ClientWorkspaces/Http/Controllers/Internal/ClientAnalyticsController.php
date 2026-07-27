<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\ClientWorkspaces\Services\ClientAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Client-only analytics tab — delegates to the shared metrics layer, scoped to this client's projects. */
final class ClientAnalyticsController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientAnalyticsService $analytics,
    ) {}

    /** GET /app/clients/{client}/analytics?from=&to= */
    public function __invoke(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.view_analytics', $c);

        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::now();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $to->copy()->subDays(29);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return response()->json(['data' => $this->analytics->forClient($c, $from, $to)]);
    }
}
