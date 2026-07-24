<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Resources\ExternalCampaignResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read view of all external (platform) campaigns imported into the active project — linked and
 * unlinked. Project-scoped by ProjectContext. External campaigns are created by the connector sync
 * ({@see ImportExternalCampaigns}), never by direct client input.
 */
final class ExternalCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);

        $query = ExternalCampaign::query()->latest('last_synced_at');

        if ($provider = $request->string('provider')->toString()) {
            $query->where('provider', $provider);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($request->has('linked')) {
            $request->boolean('linked')
                ? $query->whereNotNull('unified_campaign_id')
                : $query->whereNull('unified_campaign_id');
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        return ApiResponse::success(ExternalCampaignResource::collection($query->get()), 'External campaigns retrieved.');
    }
}
