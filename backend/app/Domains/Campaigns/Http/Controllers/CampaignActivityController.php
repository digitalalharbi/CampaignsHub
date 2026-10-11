<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified activity timeline for ONE campaign, built from the append-only audit log — the real system
 * events (create / status / classification / budget / link-unlink / sync / reports / alerts), never a
 * React-side fabrication. Feeds CMC-14 (full Activity) and CMC-5 (recent timeline). Project + tenant
 * scoped via a fail-closed campaign lookup, then constrained to this campaign and its externals.
 */
final class CampaignActivityController extends Controller
{
    /** Human labels for the audit actions surfaced on the timeline. */
    private const LABELS = [
        'campaign.created' => 'أُنشئت الحملة',
        'campaign.updated' => 'تعديل بيانات الحملة',
        'campaign.paused' => 'إيقاف الحملة',
        'campaign.activated' => 'تفعيل الحملة',
        'campaign.archived' => 'أرشفة الحملة',
        'campaign.external_linked' => 'ربط حملة خارجية',
        'campaign.external_unlinked' => 'فك ربط حملة خارجية',
        'campaign.provider_write' => 'تغيير نُفِّذ على المنصة',
        'campaign.provider_write_refused' => 'رفضت المنصة التغيير',
        'campaign.provider_created' => 'أُنشئت الحملة على المنصة',
    ];

    /**
     * CAMPAIGN-VIEWS-001 — the project's change history: every audited campaign event across the
     * project, newest first, each naming its campaign. The per-campaign timeline below answers «what
     * happened to this one»; this answers «what changed in this project», which is the view the
     * Campaigns surface was missing.
     */
    public function project(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $campaigns = UnifiedCampaign::query()->get(['id', 'name', 'budget_currency'])->keyBy(fn (UnifiedCampaign $c) => (string) $c->id);
        $externalToCampaign = ExternalCampaign::query()
            ->whereIn('unified_campaign_id', $campaigns->keys()->all())
            ->pluck('unified_campaign_id', 'id');
        $entityIds = array_merge($campaigns->keys()->all(), array_map('strval', $externalToCampaign->keys()->all()));
        $limit = min(200, max(1, (int) $request->integer('limit', 100)));
        $logs = $entityIds === [] ? collect() : AuditLog::query()
            ->whereIn('entity_id', $entityIds)
            ->where('action', 'like', 'campaign.%')
            ->latest('created_at')
            ->limit($limit)
            ->get();
        $userNames = User::query()
            ->whereIn('id', $logs->pluck('user_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        $events = $logs->map(function (AuditLog $log) use ($campaigns, $externalToCampaign, $userNames): array {
            $campaignId = (string) ($externalToCampaign[$log->entity_id] ?? $log->entity_id);
            $campaign = $campaigns[$campaignId] ?? null;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'label' => self::LABELS[$log->action] ?? $log->action,
                'actor' => $log->user_id ? ($userNames[$log->user_id] ?? 'مستخدم') : 'النظام',
                'at' => $log->created_at?->toIso8601String(),
                'before' => $log->before,
                'after' => $log->after,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign?->name,
                'budget_currency' => $campaign?->budget_currency,
            ];
        })->values();

        return ApiResponse::success($events, 'Project campaign activity.');
    }

    public function index(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        // Fail-closed: 404 for a cross-project / unknown campaign (project + tenant global scopes apply).
        $model = UnifiedCampaign::query()->findOrFail($campaign);

        // This campaign's own events + events on the external campaigns it groups.
        $externalIds = ExternalCampaign::query()->where('unified_campaign_id', $model->id)->pluck('id')->all();
        $entityIds = array_merge([(string) $model->id], array_map('strval', $externalIds));

        $limit = min(100, max(1, (int) $request->integer('limit', 50)));
        $logs = AuditLog::query()
            ->whereIn('entity_id', $entityIds)
            ->where('action', 'like', 'campaign.%')
            ->latest('created_at')
            ->limit($limit)
            ->get();

        $userNames = User::query()
            ->whereIn('id', $logs->pluck('user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $events = $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'label' => self::LABELS[$log->action] ?? $log->action,
            'actor' => $log->user_id ? ($userNames[$log->user_id] ?? 'مستخدم') : 'النظام',
            'at' => $log->created_at?->toIso8601String(),
            'before' => $log->before,
            'after' => $log->after,
            'source' => $log->entity_type === ExternalCampaign::class ? 'external_campaign' : 'campaign',
        ])->all();

        return ApiResponse::success($events, 'Campaign activity.');
    }
}
