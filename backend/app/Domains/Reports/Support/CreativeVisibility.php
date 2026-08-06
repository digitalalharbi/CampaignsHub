<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * §15.12 — what a shared link is allowed to show about the content, decided once and read everywhere.
 *
 * ## Fail-closed, and closed by default
 *
 * Every flag starts FALSE. A link that says nothing about creatives shows no creatives — including
 * every link created before this feature existed, which is the correct direction to be wrong in.
 * The alternative, defaulting to visible, would have published the ad copy, the destination URLs and
 * the asset files of every previously-shared report the moment this shipped, and nobody would have
 * been asked.
 *
 * ## Some flags cannot be set independently, because arithmetic exists
 *
 * ROAS is revenue over spend. A link that hides spend and shows both revenue and ROAS has published
 * the spend — one division away, from two figures printed beside each other. The same is true of CPA
 * against spend, and AOV against revenue. So the constructor CLOSES those combinations rather than
 * trusting the operator to notice: `roas` requires both `spend` and `revenue`, and `cpa` requires
 * `spend`. An operator who wants ROAS shown must show what it is made of.
 *
 * This is why the flags are resolved in one place instead of being read as raw booleans at each call
 * site. A redaction rule spread across four surfaces is a redaction rule that will be complete on
 * three of them.
 *
 * ## What this cannot do, stated rather than implied
 *
 * `download` removes the download control and the asset URL from the payload that feeds it. It does
 * not, and cannot, stop a reader from saving an image their browser has rendered — that is true of
 * every image on every website. The containment that actually works is EXCLUDING the creative, which
 * is why exclusion is part of the ceiling and not part of this object. Treating «zoom off» or
 * «download off» as secrecy would be the more comfortable reading and the wrong one.
 */
final class CreativeVisibility
{
    /**
     * The flags an operator sets, in the order the link builder shows them.
     *
     * `creatives` is the master: with it off, nothing else is consulted.
     */
    public const FLAGS = [
        'creatives',
        'video',
        'image_zoom',
        'download',
        'ad_copy',
        'headline',
        'cta',
        'destination_url',
        'comparison',
        'spend',
        'revenue',
        'cpa',
        'roas',
        'insights',
        'recommendations',
    ];

    /** Cost metrics that a hidden spend must take with it — each is spend over something. */
    public const COST_METRICS = ['cpc', 'cpm', 'cpa', 'cost_per_view', 'cost_per_lpv', 'spend'];

    /** Revenue-derived metrics that a hidden revenue must take with it. */
    public const REVENUE_METRICS = ['revenue', 'aov', 'roas'];

    private function __construct(
        public readonly bool $creatives,
        public readonly bool $video,
        public readonly bool $imageZoom,
        public readonly bool $download,
        public readonly bool $adCopy,
        public readonly bool $headline,
        public readonly bool $cta,
        public readonly bool $destinationUrl,
        public readonly bool $comparison,
        public readonly bool $spend,
        public readonly bool $revenue,
        public readonly bool $cpa,
        public readonly bool $roas,
        public readonly bool $insights,
        public readonly bool $recommendations,
    ) {}

    /** Nothing shown. The default for any link that has not said otherwise. */
    public static function none(): self
    {
        return self::fromArray([]);
    }

    /**
     * Read the operator's choices, resolve what depends on what, and never widen.
     *
     * @param  array<string, mixed>|null  $input
     */
    public static function fromArray(?array $input): self
    {
        $input ??= [];
        $on = static fn (string $key): bool => filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOL);

        $creatives = $on('creatives');

        // With the section off, every dependent flag is off too — so a caller that forgets to check
        // the master cannot accidentally honour a sub-flag that was left ticked from an earlier edit.
        $and = static fn (string $key): bool => $creatives && $on($key);

        $spend = $and('spend');
        $revenue = $and('revenue');

        return new self(
            creatives: $creatives,
            video: $and('video'),
            imageZoom: $and('image_zoom'),
            download: $and('download'),
            adCopy: $and('ad_copy'),
            headline: $and('headline'),
            cta: $and('cta'),
            destinationUrl: $and('destination_url'),
            comparison: $and('comparison'),
            spend: $spend,
            revenue: $revenue,
            // Cost per order is spend over orders; showing it while hiding spend publishes spend.
            cpa: $and('cpa') && $spend,
            // Return on spend is revenue over spend, so it needs BOTH sides visible.
            roas: $and('roas') && $spend && $revenue,
            insights: $and('insights'),
            // A recommendation is the action attached to an insight; without the insight there is
            // nothing for it to be attached to.
            recommendations: $and('recommendations') && $and('insights'),
        );
    }

    /**
     * The flags as stored and as reported to the client's page.
     *
     * The RESOLVED values, not the raw input: the page renders what it is told, and telling it
     * «roas: true» while the sanitiser strips every ROAS figure would produce a column of blanks
     * where the reader can see that something is being withheld from them by name.
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'creatives' => $this->creatives,
            'video' => $this->video,
            'image_zoom' => $this->imageZoom,
            'download' => $this->download,
            'ad_copy' => $this->adCopy,
            'headline' => $this->headline,
            'cta' => $this->cta,
            'destination_url' => $this->destinationUrl,
            'comparison' => $this->comparison,
            'spend' => $this->spend,
            'revenue' => $this->revenue,
            'cpa' => $this->cpa,
            'roas' => $this->roas,
            'insights' => $this->insights,
            'recommendations' => $this->recommendations,
        ];
    }

    /**
     * The metric keys this link must never emit.
     *
     * Assembled here rather than at each call site so that a surface added later cannot forget one.
     * `frequency`, `impressions`, `clicks`, `ctr` and the video rates are absent deliberately: they
     * are delivery, not money, and hiding a client's own click-through rate serves nobody.
     *
     * @return list<string>
     */
    public function hiddenMetrics(): array
    {
        $hidden = [];

        if (! $this->spend) {
            $hidden = array_merge($hidden, self::COST_METRICS);
        }
        if (! $this->revenue) {
            $hidden = array_merge($hidden, self::REVENUE_METRICS);
        }
        if (! $this->cpa) {
            $hidden[] = 'cpa';
        }
        if (! $this->roas) {
            $hidden[] = 'roas';
        }

        return array_values(array_unique($hidden));
    }

    public function hides(string $metric): bool
    {
        return in_array($metric, $this->hiddenMetrics(), true);
    }
}
