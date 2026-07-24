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
