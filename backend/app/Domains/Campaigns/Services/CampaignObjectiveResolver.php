<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;

/**
 * Adopt the platform's own objective, and never overwrite a person's (REPORT-OBJECTIVE-002).
 *
 * ## The gap this closes
 *
 * `external_campaigns.objective` carried what Meta, Google, TikTok, Snapchat, X and LinkedIn each
 * reported, and nothing ever copied it onto the unified campaign the reports read. So every imported
 * campaign sat at the column default with `objective_source = 'unset'`, and the objective-based
 * report — the one that decides whether a campaign's spend reaches a client's cost per order — was
 * classifying from a value nobody had set.
 *
 * ## The precedence rule, and why it only runs one way
 *
 * A `manual` objective always wins. Somebody looked at the campaign and said what it was for, and a
 * sync that ran afterwards must not quietly undo that: the correction exists precisely because the
 * platform's answer was wrong or absent, so letting the platform overwrite it would make the fix
 * last until the next sweep and no longer. Only `unset` and `platform` are ever rewritten.
 *
 * ## What is kept
 *
 * `objective_platform_value` holds the platform's raw string — `OUTCOME_SALES`, `RF_VIDEO_VIEWS` —
 * whatever happens afterwards. A manual correction changes the classification and does not erase
 * what the platform said, so the interface can show both and an operator can tell «the platform is
 * wrong about this» apart from «the platform never said».
 *
 * ## Two links, two objectives
 *
 * A unified campaign can gather several external campaigns, and they can disagree — one Meta reach
 * buy and one Meta sales buy under a single roll-up. There is no honest single answer, so the
 * resolver takes the objective only when every linked campaign that HAS one agrees. A disagreement
 * leaves the campaign `unset` for a person to settle, which keeps its spend off the sales path in
 * the meantime.
 */
final class CampaignObjectiveResolver
{
    public function __construct(
        private readonly PlatformObjectiveMap $map,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Bring a unified campaign's objective in line with its linked platform campaigns.
     *
     * @return bool whether anything changed
     */
    public function sync(UnifiedCampaign $campaign): bool
    {
        if ($campaign->objective_source === 'manual') {
            return false;
        }

        $externals = ExternalCampaign::withoutGlobalScopes()
            ->where('unified_campaign_id', $campaign->id)
            ->get(['provider', 'objective']);

        if ($externals->isEmpty()) {
            return false;
        }

        $resolved = [];
        $rawValues = [];

        foreach ($externals as $external) {
            $objective = $this->map->resolve((string) $external->provider, $external->objective);

            if ($objective === null) {
                continue;
            }

            $resolved[$objective->value] = $objective;
            $rawValues[] = strtoupper((string) $external->objective);
        }

        // Nothing recognised, or the linked campaigns disagree — either way there is no answer to
        // adopt, and inventing one is the failure this whole unit is about.
        if (count($resolved) !== 1) {
            return false;
        }

        $objective = reset($resolved);
        $rawValue = implode(' · ', array_unique($rawValues));

        if ($campaign->objective === $objective->value && $campaign->objective_source === 'platform') {
            // Still record the raw value if it arrived after the classification did.
            if ($campaign->objective_platform_value !== $rawValue) {
                $campaign->forceFill(['objective_platform_value' => $rawValue])->save();
            }

            return false;
        }

        $before = $campaign->objective;

        $campaign->forceFill([
            'objective' => $objective->value,
            'objective_source' => 'platform',
            'objective_platform_value' => $rawValue,
        ])->save();

        $this->audit->log(
            action: 'campaign.objective.derived',
            entityType: UnifiedCampaign::class,
            entityId: (string) $campaign->id,
            before: ['objective' => $before],
            after: [
                'objective' => $objective->value,
                'objective_source' => 'platform',
                'objective_platform_value' => $rawValue,
                'marketing_path' => $objective->path()->value,
            ],
        );

        return true;
    }

    /**
     * Everything a screen needs to say where a campaign's classification came from.
     *
     * Returned as a block rather than left to each caller to assemble, because «what does this
     * campaign count as, and who decided» has to read identically on the campaign page, in the
     * report and in the client's link — and it is the provenance of the one figure a client acts on.
     *
     * @return array<string,mixed>
     */
    public function provenance(UnifiedCampaign $campaign): array
    {
        $objective = CampaignObjective::tryFrom((string) $campaign->objective) ?? CampaignObjective::Other;
        $source = (string) ($campaign->objective_source ?? 'unset');

        return [
            'objective' => $objective->value,
            'objective_label_ar' => $objective->labels()['ar'],
            'objective_label_en' => $objective->labels()['en'],
            'marketing_path' => $objective->path()->value,
            'counts_as_sales' => $objective->isSales(),
            'source' => $source,
            // The platform's own word, kept whatever happens afterwards — so «the platform is wrong
            // about this» is distinguishable from «the platform never said».
            'platform_value' => $campaign->objective_platform_value,
            'corrected_by' => $campaign->objective_corrected_by,
            'corrected_at' => $campaign->objective_corrected_at?->toIso8601String(),
            'reviewed' => $source === 'manual',
            'note_ar' => match ($source) {
                'manual' => 'صُحّح يدويًا بعد المراجعة.',
                'platform' => 'مأخوذ من المنصة تلقائيًا.',
                default => 'لم يُصنَّف بعد — لا يدخل إنفاقه في تكلفة الطلب.',
            },
            'note_en' => match ($source) {
                'manual' => 'Corrected by a person after review.',
                'platform' => 'Taken from the platform automatically.',
                default => 'Not classified yet — its spend is kept out of the cost per order.',
            },
        ];
    }
}
