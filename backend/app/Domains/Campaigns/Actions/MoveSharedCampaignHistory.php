<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\ExternalCampaign;
use Illuminate\Support\Facades\DB;

/**
 * SHARED-ACCOUNT-PROJECTS-001 — a campaign of a SHARED account moved from one of its projects to another takes its stored
 * history with it.
 *
 * The usual re-file (ACCOUNT-SCOPE-ISOLATION-001) leaves old rows where they are, because there the account LEFT the old
 * project and that history is the old project's. Here both projects still read the account and the Owner said which
 * project the campaign belongs to: one campaign, one project, so its spend before the move must not stay counted in the
 * project it left while the backfill writes it again in the one it joined — that is the same money in two reports.
 *
 * Runs inside the importer's transaction, right after the campaign row was re-filed and adopted in the target. Moves:
 * the campaign's daily figures and ad-set/ad figures, and its creatives (with their figures) where the target has no row
 * for the same creative yet. Ad sets and ads follow on their own: the structure import re-stamps them from the campaign.
 */
final class MoveSharedCampaignHistory
{
    /** @return array<string,int> rows moved per table */
    public function execute(ExternalCampaign $campaign, string $from): array
    {
        $to = (string) $campaign->project_id;
        $done = ['daily_metrics' => 0, 'entity_daily_metrics' => 0, 'external_creatives' => 0, 'creative_daily_metrics' => 0];

        if ($from === '' || $from === $to) {
            return $done;
        }

        $id = (string) $campaign->getKey();
        $unified = $campaign->unified_campaign_id === null ? null : (string) $campaign->unified_campaign_id;

        foreach (['daily_metrics', 'entity_daily_metrics'] as $table) {
            // The target holds rows for this campaign only if it was here before; the history being moved in supersedes them.
            DB::table($table)->where('external_campaign_id', $id)->where('project_id', $to)->delete();
            $done[$table] = DB::table($table)->where('external_campaign_id', $id)->where('project_id', $from)
                ->update(['project_id' => $to] + ($table === 'daily_metrics' ? ['unified_campaign_id' => $unified] : []));
        }

        $creatives = DB::table('external_creatives as c')
            ->where('c.external_campaign_id', $id)
            ->where('c.project_id', $from)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('external_creatives as t')
                ->where('t.project_id', $to)
                ->whereColumn('t.provider', 'c.provider')
                ->whereColumn('t.external_creative_id', 'c.external_creative_id')
                ->where(fn ($w) => $w->whereColumn('t.external_account_id', 'c.external_account_id')
                    ->orWhere(fn ($n) => $n->whereNull('t.external_account_id')->whereNull('c.external_account_id'))))
            ->pluck('c.id')
            ->all();

        if ($creatives !== []) {
            $done['creative_daily_metrics'] = DB::table('creative_daily_metrics')->whereIn('creative_id', $creatives)->where('project_id', $from)
                ->update(['project_id' => $to, 'campaign_id' => $unified]);
            $done['external_creatives'] = DB::table('external_creatives')->whereIn('id', $creatives)
                ->update(['project_id' => $to, 'campaign_id' => $unified]);
        }

        return $done;
    }
}
