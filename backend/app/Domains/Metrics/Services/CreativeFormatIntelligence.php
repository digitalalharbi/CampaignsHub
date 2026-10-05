<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use Illuminate\Support\Carbon;

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 — «image or video, HERE?», answered once for every surface.
 *
 * ## The question, and why it needs a scope before it needs an answer
 *
 * «What works better, image or video, and why» is only answerable about a particular advertiser in a
 * particular period doing a particular job. Asked of a project it blends an agency's four ad accounts
 * into one verdict; asked of a month that mixes a brand campaign and a sales campaign it divides one
 * objective's money by another objective's events. Both produce a number, and neither is an answer.
 *
 * So the scope is stated and narrow — project, optionally ONE ad account, optionally one campaign,
 * and a period — and the comparison is SPLIT by objective family before any winner exists. A reader
 * is given «sales: image vs video» and «traffic: image vs video» rather than one blended ranking.
 *
 * ## It owns no arithmetic
 *
 * Every figure comes from {@see CreativeMetrics} and every verdict from
 * {@see ContentIntelligence::byFormat()}, which already holds the hard parts: the metric is chosen
 * from the objective's own headline set, only a metric EVERY compared format reported may decide,
 * volumes and spend may never decide, and a format carried by one asset is held out as an anecdote
 * rather than ranked. A second implementation here is how Content and a report come to disagree
 * about the same account.
 *
 * What this adds is the three things a decision needs around that verdict:
 *
 *   COVERAGE   how many creatives each format has and how many of them reported anything. «90% of
 *              spend on video» reads as «video is 90% better» unless the evidence base is stated.
 *   SPEND MIX  every format group, including the ones too small to rank — carousel, collection,
 *              catalog and unlabelled stay visible even when the verdict is image against video.
 *   ACCOUNTS   which ad accounts the answer is actually about, so a project rollup can be drilled
 *              into rather than mistaken for one advertiser.
 *
 * ## Isolation
 *
 * ACCOUNT-SCOPE-ISOLATION-001 through `BoundAccountVisibility`, exactly as the Content library reads
 * it: a deselected account's creatives are not this project's to compare, and asking for one account
 * can never pull in another's simply because they share a provider. DISCOVERED ≠ SELECTED.
 */
final class CreativeFormatIntelligence
{
    /**
     * Below this a format's numbers are an anecdote, and above it they are still only moderate.
     *
     * Deliberately NOT a significance test: this product calculates none, and naming a confidence it
     * has not computed would be the invention the requirement forbids. These are counts, described
     * as counts — «high» means both ranked formats cleared the higher bar, nothing more.
     */
    private const MODERATE_CREATIVES = 2;

    private const HIGH_CREATIVES = 5;

    public function __construct(
        private readonly CreativeMetrics $metrics,
        private readonly ContentIntelligence $intelligence,
    ) {}

    /**
     * The canonical answer for one scope.
     *
     * @return array<string, mixed>
     */
    public function forScope(
        string $projectId,
        Carbon $from,
        Carbon $to,
        ?string $accountId = null,
        ?string $campaignId = null,
    ): array {
        $creatives = $this->creatives($projectId, $accountId, $campaignId);

        $ids = array_map(static fn (array $c): string => $c['id'], $creatives);
        $figures = $ids === [] ? [] : $this->metrics->forCreatives($ids, $from, $to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope' => [
                'project_id' => $projectId,
                'external_account_id' => $accountId,
                'campaign_id' => $campaignId,
            ],
            'accounts' => $this->accounts($creatives),
            'coverage' => $this->coverage($creatives, $figures),
            'spend_mix' => $this->spendMix($creatives, $figures),
            'objectives' => $this->byObjective($creatives, $figures),
        ];
    }

    /**
     * The creatives this scope may compare, with the two facts the comparison groups on.
     *
     * @return list<array{id: string, format: ?string, objective: ?string, account_id: ?string}>
     */
    private function creatives(string $projectId, ?string $accountId, ?string $campaignId): array
    {
        $rows = ExternalCreative::query()
            ->where('external_creatives.project_id', $projectId)
            ->tap(fn ($q) => BoundAccountVisibility::applyThroughCampaign(
                $q,
                'external_creatives.external_campaign_id',
                'external_creatives.project_id',
            ))
            ->when($accountId !== null, fn ($q) => $q->where('external_creatives.external_account_id', $accountId))
            ->when($campaignId !== null, fn ($q) => $q->where('external_creatives.campaign_id', $campaignId))
            ->get(['id', 'format', 'campaign_id', 'external_account_id']);

        $objectives = UnifiedCampaign::query()
            ->whereIn('id', $rows->pluck('campaign_id')->filter()->unique()->all())
            ->pluck('objective', 'id');

        return $rows->map(static fn ($c): array => [
            'id' => (string) $c->getKey(),
            'format' => $c->format,
            'objective' => $c->campaign_id === null ? null : ($objectives[$c->campaign_id] ?? null),
            'account_id' => $c->external_account_id === null ? null : (string) $c->external_account_id,
        ])->all();
    }

    /**
     * The ad accounts this answer is about — the axis a project rollup is drilled into.
     *
     * Read from the creatives that SURVIVED the visibility predicate, so a deselected account cannot
     * be named here any more than its figures can be counted.
     *
     * @param  list<array{account_id: ?string}>  $creatives
     * @return list<array{id: string, name: string, provider: string}>
     */
    private function accounts(array $creatives): array
    {
        $ids = array_values(array_unique(array_filter(array_column($creatives, 'account_id'))));

        if ($ids === []) {
            return [];
        }

        return ExternalAccount::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'provider'])
            ->map(static fn ($a): array => [
                'id' => (string) $a->getKey(),
                // An account the provider named with an empty string is still an account to choose.
                'name' => trim((string) $a->name) !== '' ? (string) $a->name : (string) $a->getKey(),
                'provider' => (string) $a->provider,
            ])
            ->all();
    }

    /**
     * How many creatives each format has, and how many of them reported anything at all.
     *
     * The requirement states the reason: without it «90% of spend on video» reads as «video is 90%
     * better». A format with thirty-four assets of which seven reported nothing is a different piece
     * of evidence from one with twenty-seven that all reported.
     *
     * @param  list<array{id: string, format: ?string}>  $creatives
     * @param  array<string, array<string, mixed>|null>  $figures
     * @return array<string, array{creatives: int, with_metrics: int, without_metrics: int}>
     */
    private function coverage(array $creatives, array $figures): array
    {
        $out = [];

        foreach ($creatives as $creative) {
            $format = $this->format($creative['format'] ?? null);
            $out[$format] ??= ['creatives' => 0, 'with_metrics' => 0, 'without_metrics' => 0];
            $out[$format]['creatives']++;

            is_array($figures[$creative['id']] ?? null)
                ? $out[$format]['with_metrics']++
                : $out[$format]['without_metrics']++;
        }

        ksort($out);

        return $out;
    }

    /**
     * Spend by format, across EVERY group — not only the two being ranked.
     *
     * Carousel, collection, catalog and unlabelled keep their share here even when the verdict is
     * image against video, because a reader deciding what to commission needs to see the money that
     * is already somewhere else.
     *
     * A share is null when any format withheld its spend or none reported it: a proportion over an
     * incomplete denominator overstates itself and looks like an answer. Never 0.
     *
     * @param  list<array{id: string, format: ?string}>  $creatives
     * @param  array<string, array<string, mixed>|null>  $figures
     * @return array{formats: list<array{format: string, spend: ?float, share: ?float}>, total: ?float, complete: bool}
     */
    private function spendMix(array $creatives, array $figures): array
    {
        $spend = [];
        $incomplete = [];

        foreach ($creatives as $creative) {
            $format = $this->format($creative['format'] ?? null);
            $row = $figures[$creative['id']] ?? null;
            $spend[$format] ??= null;
            $incomplete[$format] ??= false;

            if (! is_array($row)) {
                continue;
            }

            $value = $row['spend'] ?? null;

            if (is_numeric($value)) {
                $spend[$format] = (float) ($spend[$format] ?? 0.0) + (float) $value;

                continue;
            }

            /*
             * A withheld figure is not a zero — FX-001. A creative whose spend could not be converted
             * carries its original instead, and a sum that silently skipped it would state a share
             * the account never spent.
             */
            if (($row['spend_original'] ?? null) !== null) {
                $incomplete[$format] = true;
            }
        }

        ksort($spend);

        $complete = ! in_array(true, $incomplete, true) && array_filter($spend, static fn ($s): bool => $s !== null) !== [];
        $total = $complete ? array_sum(array_filter($spend, static fn ($s): bool => $s !== null)) : null;

        $formats = [];
        foreach ($spend as $format => $amount) {
            $formats[] = [
                'format' => (string) $format,
                'spend' => $incomplete[$format] ? null : $amount,
                'share' => $total !== null && $total > 0.0 && $amount !== null && ! $incomplete[$format]
                    ? $amount / $total
                    : null,
            ];
        }

        return ['formats' => $formats, 'total' => $total, 'complete' => $complete];
    }

    /**
     * One comparison per objective FAMILY — the apples-to-apples rule, enforced rather than advised.
     *
     * A scope holding a brand campaign and a sales campaign has two answers, and printing one would
     * rank a traffic image against a sales video on a metric neither was bought to move. Families
     * are the axis rather than raw objectives, for the reason {@see CreativeMetrics::headline()}
     * records: `Leads` and `AppInstalls` sit on one marketing path and are judged on different
     * figures, and a comparison that conflated them would headline a lead campaign with ROAS.
     *
     * The family's objective is passed back into `byFormat()` so the metric is chosen the same way
     * the cards choose theirs.
     *
     * @param  list<array{id: string, format: ?string, objective: ?string}>  $creatives
     * @param  array<string, array<string, mixed>|null>  $figures
     * @return list<array<string, mixed>>
     */
    private function byObjective(array $creatives, array $figures): array
    {
        $families = [];

        foreach ($creatives as $creative) {
            $family = $this->metrics->familyFor($creative['objective'] ?? null);
            $families[$family->value]['objective'] ??= $creative['objective'] ?? null;
            $families[$family->value]['creatives'][] = $creative;
        }

        $out = [];

        foreach ($families as $family => $set) {
            $comparison = $this->intelligence->byFormat($set['creatives'], $figures, $set['objective']);

            $out[] = [
                'family' => $family,
                'label' => ObjectiveFamily::from($family)->label(),
                'comparison' => $comparison,
                'evidence' => $this->evidence($comparison),
            ];
        }

        /*
         * Largest first — the objective most of this scope's creatives were bought for is the one a
         * reader came to ask about, and a four-creative traffic cut should not lead a sales account.
         */
        usort($out, static fn (array $a, array $b): int => count($b['comparison']['formats'] ?? []) <=> count($a['comparison']['formats'] ?? []));

        return $out;
    }

    /**
     * How much weight this comparison can carry — derived from counts, never asserted.
     *
     * `insufficient` is the only state that forbids a winner, and it is the one `byFormat()` already
     * decides by refusing. The other two describe the evidence base in the only terms this product
     * actually computes: how many creatives stood behind each ranked format.
     *
     * @param  array<string, mixed>  $comparison
     */
    private function evidence(array $comparison): string
    {
        if (($comparison['refusal'] ?? null) !== null) {
            return 'insufficient';
        }

        $counts = array_map(
            static fn (array $f): int => (int) ($f['creatives'] ?? 0),
            (array) ($comparison['formats'] ?? []),
        );

        if ($counts === [] || min($counts) < self::MODERATE_CREATIVES) {
            return 'insufficient';
        }

        return min($counts) >= self::HIGH_CREATIVES ? 'high' : 'moderate';
    }

    /** A creative with no format recorded is «unlabelled», never quietly filed under image. */
    private function format(?string $format): string
    {
        $value = strtolower(trim((string) $format));

        return $value === '' ? 'unlabelled' : $value;
    }
}
