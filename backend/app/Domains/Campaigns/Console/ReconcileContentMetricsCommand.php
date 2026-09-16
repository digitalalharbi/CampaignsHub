<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner defect 95 — the truth chain, walked on ONE creative, and the divergences named.
 *
 * ## Why a command and not a test
 *
 * The owner's report is that Content metrics are «fundamentally inconsistent» on Production —
 * sometimes the KPIs appear and Spend is missing, sometimes Spend appears and the KPIs disappear —
 * and the register records that the chain «has never been reconciled end to end on one creative».
 * A fixture test proves a rule; it cannot tell anybody which rung a REAL creative falls off, and
 * the account where it falls off is one no test on this machine can reach.
 *
 * So the walk is a command: read-only, provider-free, and pointable at Production through the
 * existing diagnostics workflow. It calls the product's own services rather than re-deriving
 * anything — a diagnosis that runs its own arithmetic answers a question nobody asked.
 *
 * ## What it compares, and why each pair can disagree
 *
 * Each rung is a real consumer, not a layer of theory:
 *
 *   RAW/creative   `creative_daily_metrics` — the only table the library read before the ad-grain
 *                  fallback existed.
 *   RAW/ad         `entity_daily_metrics` through `external_ads.creative_id` — where five of six
 *                  providers actually put a creative's money.
 *   FIGURES        `CreativeMetrics::forCreatives()` — the card, the popup, the trend, the report
 *                  roster and the fatigue verdict all read this.
 *   TOTALS         `CreativeMetrics::totalsFor()` — the library's own headline strip, over the same
 *                  scope. A strip that consults fewer sources than the cards beneath it is the
 *                  owner's sentence exactly: the figures appear on the cards and vanish above them.
 *   AGGREGATE      `CreativeMetrics::aggregate()` — the group total, the format comparison and
 *                  Content Analytics. Over ONE creative it must equal that creative's own figures,
 *                  and any key it drops is a key those surfaces cannot show.
 *   CARD           `CreativeRows::present()` — `headline_metrics` plus the row.
 *   ROSTER         `CreativeRows::lean()` — the report's own row for the same creative.
 *   HEADLINE       `headline($objective, $figures)` against `headline($objective)`. The card passes
 *                  the figures and the client report does not, so the same creative is promised two
 *                  different metric sets one surface apart.
 *
 * ## The one thing it must never do
 *
 * Write. No row is inserted, no job queued, no provider called — which is what makes it safe to
 * point at a live account while a customer is looking at the same screen.
 */
final class ReconcileContentMetricsCommand extends Command
{
    protected $signature = 'content:reconcile
        {creative? : A creative — ours or the provider\'s own external id. Omitted: the busiest one}
        {--from= : Window start (YYYY-MM-DD). Default: 29 days before --to}
        {--to= : Window end (YYYY-MM-DD). Default: today}
        {--project= : Narrow the automatic subject to one project}
        {--strict : Exit non-zero when a divergence is found}';

    protected $description = 'Read-only: walk one creative from the provider rows to every Content surface and name the divergences.';

    /** @var list<string> */
    private array $divergences = [];

    public function handle(): int
    {
        [$from, $to] = $this->window();

        $creative = $this->subject();

        if ($creative === null) {
            $this->warn('No creative found. Nothing to reconcile.');

            return self::SUCCESS;
        }

        /*
         * The contexts the services legitimately expect, set from the ROW rather than from a flag.
         *
         * `CreativeDemoPolicy` asks the project whether it holds any live row, and without a project
         * in context it answers «do not filter» — which would make this walk disagree with every
         * request the product serves, in the direction that hides the defect.
         */
        app(TenantContext::class)->setTenantId((string) $creative->tenant_id);
        app(ProjectContext::class)->setProjectId((string) $creative->project_id);

        $id = (string) $creative->getKey();
        $campaign = $creative->campaign_id === null
            ? null
            : UnifiedCampaign::withoutGlobalScopes()->find($creative->campaign_id, ['id', 'name', 'objective']);
        $objective = $campaign?->objective;

        $this->line('');
        $this->line('CONTENT TRUTH CHAIN — one creative, one window, every surface');
        $this->line('  creative  : '.$id);
        $this->line('  name      : '.((string) ($creative->client_display_name ?: $creative->name) ?: '(unnamed)'));
        $this->line('  provider  : '.(string) $creative->provider.'   format: '.(string) ($creative->format ?? 'not stated'));
        $this->line('  external  : '.(string) $creative->external_creative_id);
        $this->line('  project   : '.(string) $creative->project_id);
        $this->line('  objective : '.($objective ?? 'none — judged on the universal set'));
        $this->line('  window    : '.$from->toDateString().' → '.$to->toDateString());

        $rawCreative = $this->rawCreativeGrain($id, $from, $to);
        $rawAd = $this->rawAdGrain($id, $from, $to);

        $this->rung('RUNG 1 — creative_daily_metrics (the platform reporting this creative directly)', $rawCreative);
        $this->rung('RUNG 2 — entity_daily_metrics via external_ads.creative_id (the ads that ran it)', $rawAd);

        $metrics = app(CreativeMetrics::class);

        $figures = $metrics->forCreatives([$id], $from, $to)[$id] ?? null;
        $totals = $metrics->totalsFor([$id], $from, $to);
        $aggregate = $figures === null ? null : $metrics->aggregate([$figures]);

        $this->line('');
        $this->line('RUNG 3 — CreativeMetrics::forCreatives()  (card · popup · trend · roster · fatigue)');
        if ($figures === null) {
            $this->line('    no figures at all');
        } else {
            $this->line('    grain      : '.(string) ($figures['grain'] ?? 'unstated'));
            $this->line('    answered   : '.$this->answered($figures));
            $this->line('    spend      : '.$this->money($figures, 'spend'));
            $this->line('    revenue    : '.$this->money($figures, 'revenue'));
        }

        $this->line('');
        $this->line('RUNG 4 — CreativeMetrics::totalsFor()  (the library\'s headline strip)');
        $this->line($totals === null
            ? '    null — the strip says «nothing reported» for this scope'
            : '    answered   : '.$this->answered($totals));

        $this->line('');
        $this->line('RUNG 5 — CreativeMetrics::aggregate()  (group total · format comparison · Content Analytics)');
        $this->line($aggregate === null
            ? '    null'
            : '    answered   : '.$this->answered($aggregate));

        $rows = app(CreativeRows::class);

        /*
         * An ELOQUENT collection, because `present()` and `lean()` call `modelKeys()` on it.
         *
         * `new Collection([$model])` is a support collection and has no such method — the walk died
         * at the card rung with a `BadMethodCallException`, which is a diagnosis that cannot reach
         * the surface it exists to describe.
         */
        $collection = ExternalCreative::withoutGlobalScopes()->whereKey($id)->get();

        $card = $rows->present($collection, $from, $to, withFatigue: false)[0] ?? [];
        $roster = $rows->lean($collection, $from, $to)[0] ?? [];

        $withFigures = $metrics->headline($objective, $figures);
        $withoutFigures = $metrics->headline($objective);

        $this->line('');
        $this->line('RUNG 6 — the CARD  (CreativeRows::present)');
        $this->line('    headline_metrics : '.implode(', ', (array) ($card['headline_metrics'] ?? [])));
        $this->line('    beside the fixed Spend cell it renders: '.$this->besideSpend($card));

        $this->line('');
        $this->line('RUNG 7 — the REPORT ROSTER  (CreativeRows::lean)');
        $this->line('    answered   : '.(is_array($roster['metrics'] ?? null) ? $this->answered($roster['metrics']) : 'no figures'));

        $this->line('');
        $this->line('RUNG 8 — headline(), asked the two ways the product asks it');
        $this->line('    with this row\'s figures (content surfaces) : '.implode(', ', $withFigures));
        $this->line('    without them (client report detail)         : '.implode(', ', $withoutFigures));

        $preview = app(CreativePresenter::class)->preview($creative);
        $this->line('');
        $this->line('RUNG 9 — the PREVIEW envelope');
        $this->line('    state      : '.(string) $preview['state']);
        $this->line('    image      : '.(($preview['image_url'] ?? null) !== null ? 'yes' : 'no')
            .'   thumbnail: '.(($preview['thumbnail_url'] ?? null) !== null ? 'yes' : 'no')
            .'   video: '.(($preview['video_url'] ?? null) !== null ? 'yes' : 'no')
            .'   cards: '.(is_array($preview['cards'] ?? null) ? (string) count((array) $preview['cards']) : 'not fetched'));

        $this->judge($figures, $totals, $aggregate, $card, $roster, $withFigures, $withoutFigures);

        $this->line('');

        if ($this->divergences === []) {
            $this->info('RECONCILED — every rung agrees for this creative and window.');

            return self::SUCCESS;
        }

        $this->error('DIVERGENCES — '.count($this->divergences).':');
        foreach ($this->divergences as $i => $divergence) {
            $this->line('  '.($i + 1).'. '.$divergence);
        }

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Where the rungs disagree — stated as what a reader would SEE, not as a field name.
     *
     * @param  array<string, mixed>|null  $figures
     * @param  array<string, mixed>|null  $totals
     * @param  array<string, mixed>|null  $aggregate
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $roster
     * @param  list<string>  $withFigures
     * @param  list<string>  $withoutFigures
     */
    private function judge(
        ?array $figures,
        ?array $totals,
        ?array $aggregate,
        array $card,
        array $roster,
        array $withFigures,
        array $withoutFigures,
    ): void {
        if ($figures !== null && $totals === null) {
            $this->divergences[] = 'The cards carry figures and the headline strip above them says nothing '
                .'was reported (RUNG 3 answered, RUNG 4 null).';
        }

        if ($figures !== null && $totals !== null) {
            foreach ($this->answeredKeys($figures) as $key) {
                if (! in_array($key, $this->answeredKeys($totals), true)) {
                    $this->divergences[] = "The card answers «{$key}» and the headline strip over the same "
                        .'scope does not (RUNG 3 vs RUNG 4).';
                }
            }
        }

        if ($figures !== null && $aggregate !== null) {
            foreach ($this->answeredKeys($figures) as $key) {
                if (! in_array($key, $this->answeredKeys($aggregate), true)) {
                    $this->divergences[] = "A group, a format comparison or Content Analytics loses «{$key}» "
                        .'that the card for the same creative answers (RUNG 3 vs RUNG 5).';
                }
            }
        }

        if (is_array($roster['metrics'] ?? null) && $figures !== null) {
            foreach ($this->answeredKeys($figures) as $key) {
                if (! in_array($key, $this->answeredKeys($roster['metrics']), true)) {
                    $this->divergences[] = "The report roster loses «{$key}» that the card answers (RUNG 3 vs RUNG 7).";
                }
            }
        }

        if ($withFigures !== $withoutFigures) {
            $this->divergences[] = 'The same creative is judged on ['.implode(', ', $withFigures).'] by the '
                .'content surfaces and on ['.implode(', ', $withoutFigures).'] by the client report (RUNG 8).';
        }

        $headline = array_values(array_filter(
            (array) ($card['headline_metrics'] ?? []),
            static fn (mixed $k): bool => $k !== 'spend',
        ));

        if (($card['metrics'] ?? null) !== null && $headline === [] && ($card['headline_metrics'] ?? []) !== []) {
            $this->divergences[] = 'The card renders its Spend cell and NOTHING beside it, with no sentence '
                .'saying why — `headline_metrics` holds only `spend`, so the «no displayable metrics» panel '
                .'never fires (RUNG 6). This is the owner\'s «Spend appears and the other KPIs disappear».';
        }
    }

    /**
     * The window, inclusive, defaulting to the library's own last thirty days.
     *
     * @return array{Carbon, Carbon}
     */
    private function window(): array
    {
        $to = $this->option('to') === null
            ? Carbon::now()->endOfDay()
            : Carbon::parse((string) $this->option('to'))->endOfDay();

        $from = $this->option('from') === null
            ? $to->copy()->subDays(29)->startOfDay()
            : Carbon::parse((string) $this->option('from'))->startOfDay();

        return [$from, $to];
    }

    /**
     * The creative to walk — named, or the one with the most figures to say something about.
     *
     * Chosen by row count rather than by spend: the subject of this walk is a creative whose rungs
     * can actually be compared, and the busiest one is the likeliest to answer at more than one.
     */
    private function subject(): ?ExternalCreative
    {
        $reference = $this->argument('creative');

        if (is_string($reference) && $reference !== '') {
            $query = ExternalCreative::withoutGlobalScopes();

            /* A uuid is ours; anything else is the provider's own id, which is what a human reads. */
            return preg_match('/^[0-9a-f-]{36}$/i', $reference) === 1
                ? $query->find($reference)
                : $query->where('external_creative_id', $reference)->first();
        }

        $busiest = DB::table('creative_daily_metrics')
            ->when(
                is_string($this->option('project')) && $this->option('project') !== '',
                fn ($q) => $q->where('project_id', (string) $this->option('project')),
            )
            ->select('creative_id')
            ->groupBy('creative_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(1)
            ->value('creative_id');

        return $busiest === null
            ? ExternalCreative::withoutGlobalScopes()->first()
            : ExternalCreative::withoutGlobalScopes()->find($busiest);
    }

    /**
     * One grain's raw sums, said as «what the platform actually put here».
     *
     * @return array<string, float|int|null>
     */
    private function rawCreativeGrain(string $id, Carbon $from, Carbon $to): array
    {
        $row = DB::table('creative_daily_metrics')
            ->where('creative_id', $id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS rows_found, COUNT(*) FILTER (WHERE is_demo) AS demo_rows, '
                .'SUM(spend) AS spend, SUM(spend_original) AS spend_original, SUM(impressions) AS impressions, '
                .'SUM(clicks) AS clicks, SUM(conversions) AS conversions, SUM(revenue) AS revenue, '
                .'SUM(reach) AS reach, SUM(video_views) AS video_views')
            ->first();

        return $row === null ? [] : array_map(
            static fn (mixed $v): float|int|null => $v === null ? null : (is_int($v) ? $v : (float) $v),
            (array) $row,
        );
    }

    /**
     * @return array<string, float|int|null>
     */
    private function rawAdGrain(string $id, Carbon $from, Carbon $to): array
    {
        $row = DB::table('entity_daily_metrics')
            ->join('external_ads', 'external_ads.id', '=', 'entity_daily_metrics.entity_id')
            ->where('entity_daily_metrics.entity_type', 'ad')
            ->where('external_ads.creative_id', $id)
            ->whereBetween('entity_daily_metrics.metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS rows_found, COUNT(*) FILTER (WHERE entity_daily_metrics.is_demo) AS demo_rows, '
                .'COUNT(DISTINCT external_ads.id) AS ads, '
                .'SUM(entity_daily_metrics.spend) AS spend, SUM(entity_daily_metrics.impressions) AS impressions, '
                .'SUM(entity_daily_metrics.clicks) AS clicks, SUM(entity_daily_metrics.conversions) AS conversions, '
                .'SUM(entity_daily_metrics.revenue) AS revenue, SUM(entity_daily_metrics.leads) AS leads, '
                .'SUM(entity_daily_metrics.installs) AS installs, SUM(entity_daily_metrics.page_views) AS page_views')
            ->first();

        return $row === null ? [] : array_map(
            static fn (mixed $v): float|int|null => $v === null ? null : (is_int($v) ? $v : (float) $v),
            (array) $row,
        );
    }

    /** @param array<string, float|int|null> $row */
    private function rung(string $title, array $row): void
    {
        $this->line('');
        $this->line($title);

        if ($row === [] || (int) ($row['rows_found'] ?? 0) === 0) {
            $this->line('    no rows in this window');

            return;
        }

        foreach ($row as $key => $value) {
            $this->line(sprintf('    %-16s %s', $key, $value === null ? 'not reported' : (string) $value));
        }
    }

    /**
     * Which metrics a shaped row can actually answer — the question every rung is compared on.
     *
     * Read from `reported` plus the derived values that are non-null, because that is exactly what a
     * surface can render: a key in `reported` as false is «the platform does not send this», and a
     * derived null is «there was nothing to divide».
     *
     * @param  array<string, mixed>  $figures
     * @return list<string>
     */
    private function answeredKeys(array $figures): array
    {
        $keys = [];

        foreach ($figures as $key => $value) {
            if (in_array($key, ['reported', 'grain', 'active_days', 'creatives'], true)) {
                continue;
            }

            if (str_ends_with($key, '_withheld_rows') || str_ends_with($key, '_original')
                || str_starts_with($key, 'money_original')) {
                continue;
            }

            if ($value !== null) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    /** @param array<string, mixed> $figures */
    private function answered(array $figures): string
    {
        $keys = $this->answeredKeys($figures);

        return $keys === [] ? 'nothing' : implode(', ', $keys);
    }

    /**
     * A money figure the way the reader sees it — converted, withheld in its own currency, or absent.
     *
     * @param  array<string, mixed>  $figures
     */
    private function money(array $figures, string $key): string
    {
        if (($figures[$key] ?? null) !== null) {
            return (string) $figures[$key].' (converted)';
        }

        $withheld = (int) ($figures[$key.'_withheld_rows'] ?? 0);
        $original = $figures[$key.'_original'] ?? null;

        if ($withheld > 0 && is_numeric($original)) {
            return (string) $original.' '.(string) ($figures['money_original_currency'] ?? '?')
                ." (withheld — {$withheld} row(s) had no rate)";
        }

        return 'not reported';
    }

    /** @param array<string, mixed> $card */
    private function besideSpend(array $card): string
    {
        $beside = array_values(array_filter(
            (array) ($card['headline_metrics'] ?? []),
            static fn (mixed $k): bool => $k !== 'spend',
        ));

        return $beside === [] ? 'NOTHING' : implode(', ', array_slice($beside, 0, 3));
    }
}
