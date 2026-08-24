<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignObjectiveResolver;

/**
 * OBJECTIVE-NORMALIZATION-002 — repair the campaigns already holding a platform's own word.
 *
 * `ImportExternalCampaigns` seeded `unified_campaigns.objective` with `$campaign->objective`, the
 * provider's raw string, on the argument that `CampaignObjectiveResolver` would re-derive it a moment
 * later. The resolver only rewrites what it can classify, so the one case that produced a raw value —
 * a platform word `PlatformObjectiveMap` did not know — is exactly the case it left standing.
 *
 * Production shows the cost: every campaign on the Snapchat account carries `SALES`,
 * `CampaignObjective::tryFrom('SALES')` fails, every creative resolves to `ObjectiveFamily::Unknown`,
 * and objective-aware KPI selection has been off for the whole account.
 *
 * The import is fixed, but a fixed import only helps rows imported afterwards. This repairs the rows
 * already written.
 *
 * ## The guard, and why it is the right one
 *
 * A campaign is touched only when its objective is NOT a valid {@see CampaignObjective} value. That
 * is self-limiting rather than a flag: once repaired, `tryFrom` succeeds and a second pass matches
 * nothing. It also cannot reach a campaign that was already correct, whatever its history.
 *
 * A `manual` objective is never touched, for the same reason the resolver refuses to: somebody looked
 * at the campaign and said what it was for, and a repair that overrode that would be the platform
 * winning an argument a person already settled.
 *
 * ## Nothing is guessed
 *
 * The classification comes from `CampaignObjectiveResolver`, reading the linked external campaigns —
 * the same path a live sync takes. A campaign it cannot classify is set to `other`, which is this
 * product's own «not classified» value and already keeps such spend out of a cost per order. The
 * platform's word is not destroyed: the resolver records it in `objective_platform_value` whether or
 * not it could classify, and this checks that it landed there before overwriting the column.
 */
final class ReclassifyCampaignObjectives
{
    public function __construct(private readonly CampaignObjectiveResolver $resolver) {}

    /**
     * @return array{examined:int, reclassified:int, unclassified:int}
     */
    public function execute(): array
    {
        $valid = array_map(static fn (CampaignObjective $c): string => $c->value, CampaignObjective::cases());

        $examined = 0;
        $reclassified = 0;
        $unclassified = 0;

        UnifiedCampaign::withoutGlobalScopes()
            ->where('objective_source', '!=', 'manual')
            ->whereNotIn('objective', $valid)
            ->chunkById(200, function ($campaigns) use (&$examined, &$reclassified, &$unclassified): void {
                foreach ($campaigns as $campaign) {
                    $examined++;

                    $raw = (string) $campaign->objective;

                    // The live path: read the linked external campaigns and adopt their objective when
                    // they agree. This is what should have happened at import.
                    $this->resolver->sync($campaign);
                    $campaign->refresh();

                    if (CampaignObjective::tryFrom((string) $campaign->objective) !== null) {
                        $reclassified++;

                        continue;
                    }

                    /*
                     * Still unclassified — the platform said something nobody has mapped, or the
                     * linked campaigns disagree. The column is NOT NULL and must hold a canonical
                     * value, so it becomes `other`.
                     *
                     * The raw word is preserved first. `CampaignObjectiveResolver` normally writes it,
                     * but a campaign with no linked external rows never reaches that line — and losing
                     * what the platform said is how an operator ends up with a blank to correct FROM.
                     */
                    $keep = $campaign->objective_platform_value === null
                        ? ['objective_platform_value' => $raw]
                        : [];

                    $campaign->forceFill([
                        'objective' => CampaignObjective::Other->value,
                        ...$keep,
                    ])->save();

                    $unclassified++;
                }
            });

        return ['examined' => $examined, 'reclassified' => $reclassified, 'unclassified' => $unclassified];
    }
}
