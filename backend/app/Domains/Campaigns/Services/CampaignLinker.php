<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the rules for linking external (platform) campaigns to a unified campaign (spec §9):
 *  - an external campaign links to at most ONE unified campaign within a project;
 *  - re-linking it to a different unified campaign requires explicit confirmation;
 *  - unlink never deletes the external campaign or touches the platform;
 *  - auto-suggest ranks unlinked external campaigns by name similarity.
 *
 * All queries run under the active tenant + project global scopes, so callers can only ever act on
 * their own data.
 */
final class CampaignLinker
{
    public function __construct(private readonly CampaignObjectiveResolver $objectives) {}

    /**
     * Link an external campaign to a unified campaign. If it is already linked to a *different*
     * unified campaign and $confirm is false, returns a needs-confirmation result instead of moving it.
     */
    public function link(UnifiedCampaign $unified, ExternalCampaign $external, bool $confirm, ?int $userId): LinkResult
    {
        // Already linked to this unified campaign → idempotent success.
        if ($external->unified_campaign_id === $unified->id) {
            return LinkResult::linked($external);
        }

        // Linked to a different unified campaign → require deliberate confirmation to move it.
        if ($external->unified_campaign_id !== null && ! $confirm) {
            return LinkResult::needsConfirmation($external);
        }

        $previous = $external->unified_campaign_id;

        return DB::transaction(function () use ($unified, $external, $userId, $previous): LinkResult {
            $external->forceFill([
                'unified_campaign_id' => $unified->id,
                'linked_at' => now(),
                'linked_by' => $userId,
            ])->save();

            /*
             * Linking is the moment the platform's objective becomes knowable, so it is the moment
             * to adopt it (REPORT-OBJECTIVE-002). Before this line ran, an imported campaign kept
             * the column default forever and the objective-based report classified from a value
             * nobody had set.
             *
             * The resolver refuses to touch a `manual` objective, so a person's correction survives
             * every later link and every later sync.
             */
            $this->objectives->sync($unified);

            return LinkResult::linked($external->refresh(), $previous);
        });
    }

    /** Unlink an external campaign from its unified campaign. Platform state is untouched. */
    public function unlink(ExternalCampaign $external): ExternalCampaign
    {
        $external->forceFill([
            'unified_campaign_id' => null,
            'linked_at' => null,
            'linked_by' => null,
            /*
             * CAMPAIGNS-ADOPT-001 — the decision, recorded.
             *
             * A null `unified_campaign_id` is ambiguous: it is what an unlink produces AND what a
             * campaign that has never been adopted looks like. The importer needs to tell them
             * apart, and this stamp is the only thing that can. Without it, «adopt anything
             * unlinked» would undo this on the next sweep, and «adopt only new rows» leaves every
             * campaign discovered before adoption existed invisible forever — which is what
             * happened on the live account.
             */
            'unlinked_at' => now(),
        ])->save();

        return $external->refresh();
    }

    /**
     * Suggest unlinked external campaigns in the current project, ranked by name similarity to the
     * unified campaign's name (highest first). Deterministic; no external calls.
     *
     * @return Collection<int, ExternalCampaign>
     */
    public function suggestions(UnifiedCampaign $unified, int $limit = 10): Collection
    {
        return ExternalCampaign::query()
            ->whereNull('unified_campaign_id')
            ->get()
            ->map(function (ExternalCampaign $ec) use ($unified): array {
                similar_text(
                    mb_strtolower($unified->name),
                    mb_strtolower($ec->name),
                    $percent,
                );

                return ['campaign' => $ec, 'score' => $percent];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $row): ExternalCampaign => $row['campaign'])
            ->values();
    }
}
