<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * XREL-001 — the chain that connects a campaign to everything around it:
 * client → project → platform → ad account → campaign → ad set → ad → creative, plus the alerts, tasks,
 * reports and finance records that reference it.
 *
 * Written as ONE endpoint returning counts and a handful of representative rows per relation, because
 * the point is navigation: every module should be one click from the entity you are looking at. Counts
 * come from real queries, so a relation with nothing in it reports zero instead of being hidden — an
 * absent link is information too.
 */
final class RelatedEntitiesController extends Controller
{
    private const SAMPLE = 5;

    public function campaign(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);

        $model = UnifiedCampaign::query()->with('clientWorkspace:id,name')->findOrFail($campaign);

        $externals = ExternalCampaign::query()->where('unified_campaign_id', $model->id)->get();
        $accountIds = $externals->pluck('external_account_id')->filter()->unique();
        $adSetIds = ExternalAdSet::query()->whereIn('external_campaign_id', $externals->pluck('id'))->pluck('id');

        $relations = [
            'platforms' => [
                'label_ar' => 'المنصات المرتبطة',
                'count' => $externals->pluck('provider')->unique()->count(),
                'items' => $externals->pluck('provider')->unique()->values()
                    ->map(fn (string $p) => ['id' => $p, 'label' => $p, 'to' => "/projects/{$project}/integrations"])->all(),
            ],
            'ad_accounts' => [
                'label_ar' => 'الحسابات الإعلانية',
                'count' => $accountIds->count(),
                // Resolve names — a raw uuid is not a label a human can navigate by.
                'items' => ExternalAccount::withoutGlobalScopes()->whereIn('id', $accountIds)->limit(self::SAMPLE)
                    ->get(['id', 'name'])
                    ->map(fn ($a) => ['id' => $a->id, 'label' => $a->name, 'to' => "/projects/{$project}/integrations"])->all(),
            ],
            'ad_sets' => [
                'label_ar' => 'المجموعات الإعلانية',
                'count' => $adSetIds->count(),
                'items' => ExternalAdSet::query()->whereIn('id', $adSetIds)->limit(self::SAMPLE)
                    ->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->name, 'to' => "/campaigns/{$project}/{$model->id}?tab=structure"])->all(),
            ],
            'ads' => [
                'label_ar' => 'الإعلانات',
                'count' => ExternalAd::query()->whereIn('external_ad_set_id', $adSetIds)->count(),
                'items' => ExternalAd::query()->whereIn('external_ad_set_id', $adSetIds)->limit(self::SAMPLE)
                    ->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->name, 'to' => "/campaigns/{$project}/{$model->id}?tab=structure"])->all(),
            ],
            'creatives' => [
                'label_ar' => 'المحتويات',
                'count' => ExternalCreative::query()->where('campaign_id', $model->id)->count(),
                'items' => ExternalCreative::query()->where('campaign_id', $model->id)->limit(self::SAMPLE)
                    ->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'to' => "/campaigns/{$project}/{$model->id}?tab=creatives"])->all(),
            ],
            'alerts' => [
                'label_ar' => 'التنبيهات',
                'count' => $this->countReferencing('app_notifications', $model->id),
                'items' => [],
                'to' => "/campaigns/{$project}/{$model->id}?tab=alerts",
            ],
            'reports' => [
                'label_ar' => 'التقارير',
                'count' => $this->countReferencing('reports', $model->id),
                'items' => [],
                'to' => "/campaigns/{$project}/{$model->id}?tab=reports",
            ],
        ];

        return ApiResponse::success([
            // The upward chain — where this campaign sits in the business.
            'context' => [
                'client' => $model->clientWorkspace ? ['id' => $model->clientWorkspace->id, 'name' => $model->clientWorkspace->name, 'to' => "/clients/{$model->clientWorkspace->id}"] : null,
                'project' => ['id' => $project, 'to' => "/projects/{$project}/integrations"],
                'campaign' => ['id' => $model->id, 'name' => $model->name],
            ],
            'relations' => $relations,
        ], 'Related entities.');
    }

    /**
     * Count rows of a table that reference this campaign, tolerating tables that do not have the column.
     * A missing column means "this module does not link to campaigns yet" — reported as 0, never faked.
     */
    private function countReferencing(string $table, string $campaignId): int
    {
        foreach (['unified_campaign_id', 'campaign_id'] as $column) {
            if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                continue;
            }

            return (int) DB::table($table)->where($column, $campaignId)->count();
        }

        return 0;
    }
}
