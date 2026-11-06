<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Providers\MetaConnector;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EXPIRED-MEDIA-RENEWAL — a Meta creative whose signed link has lapsed and that the last listing did not return.
 *
 * Runs at the end of the structure sync, so it needs no schedule of its own and no opening of a page ever calls
 * Meta. Only links are written, and only ones Meta sent: a refused or empty read leaves the row as it was, which
 * is the `expired` state with its reason. One pass is bounded, and the creative tried longest ago goes first, so
 * a node Meta keeps refusing cannot hold the head of the queue.
 */
final class RenewExpiredCreativeMedia
{
    private const PER_SWEEP = 100;

    /**
     * @return array{expired:int,asked:int,renewed:int,still_expired:int,stopped_by:?string}|null null when nothing has expired
     */
    public function execute(ExternalAccount $account, MetaConnector $connector): ?array
    {
        $now = Carbon::now();
        $expired = ExternalCreative::withoutGlobalScopes()
            ->where('provider', 'meta')
            ->where('is_demo', false)
            ->whereIn('external_campaign_id', DB::table('external_campaigns')->where('external_account_id', $account->getKey())->select('id'))
            ->whereNotNull('asset_expires_at')
            ->where('asset_expires_at', '<=', $now);

        $total = (clone $expired)->count();

        if ($total === 0) {
            return null;
        }

        $rows = $expired
            ->orderByRaw("(raw->>'media_renewal_attempted_at') ASC NULLS FIRST")
            ->limit(max(1, (int) config('ad_platforms.platforms.meta.media_renewal_per_sweep', self::PER_SWEEP)))
            ->get();

        $wanted = [];
        foreach ($rows as $row) {
            $wanted[(string) $row->external_creative_id] = [
                'external_id' => (string) $row->external_creative_id,
                'format' => $row->format,
                'media_refs' => (array) (((array) $row->raw)['media_refs'] ?? []),
            ];
        }

        try {
            $result = $connector->renewCreativeMedia((string) $account->external_id, array_values($wanted));
        } catch (Throwable $e) {
            Log::warning('Meta expired media could not be renewed.', ['reason' => ProviderErrorText::forDisplay($e->getMessage())]);

            return ['expired' => $total, 'asked' => 0, 'renewed' => 0, 'still_expired' => $total, 'stopped_by' => 'unclassified'];
        }

        $renewed = 0;
        foreach ($rows as $row) {
            $fresh = $result['creatives'][(string) $row->external_creative_id] ?? [];
            $links = array_intersect_key($fresh, ['asset_url' => true, 'video_url' => true, 'thumbnail_url' => true]);
            $raw = [...(array) $row->raw, 'media_renewal_attempted_at' => $now->toIso8601String()];

            if ($links === []) {
                $row->forceFill(['raw' => $raw])->save();

                continue;
            }

            // A renewed link that states no expiry says nothing about one; the lapsed date must not stay beside it.
            $row->forceFill([...$links, 'asset_expires_at' => $fresh['asset_expires_at'] ?? null, 'raw' => $raw])->save();
            $renewed++;
        }

        return [
            'expired' => $total,
            'asked' => count($wanted),
            'renewed' => $renewed,
            'still_expired' => $total - $renewed,
            'stopped_by' => $result['outcome']['stopped_by'],
        ];
    }
}
