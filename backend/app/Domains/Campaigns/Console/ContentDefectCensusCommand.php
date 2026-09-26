<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\Campaigns\Support\DrawableImage;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Content Production Recovery — WHICH creatives are broken on Production, and where.
 *
 * ## Why this exists beside `content:reconcile`
 *
 * The walk answers «which rung does THIS creative fall off» and «how short is the strip». The Owner's
 * observation is a different sentence: on the real library some items show no preview, some show
 * Spend and no indicators, some show indicators and no Spend, and the metrics a creative carries are
 * inconsistent from one card to the next. That is a question about a SET of creatives, and a
 * diagnosis that picks «the busiest one» cannot answer it. Guessing which creatives are affected
 * from a laptop is exactly what the lane is forbidden to do, so this command LISTS them.
 *
 * ## The four categories, each decided by the product's own services
 *
 *   A  PREVIEW   `CreativePresenter::preview()` — the envelope every surface draws from. A creative
 *               lands here when the envelope draws nothing: an absence state (withheld, expired,
 *               never_fetched, shape_not_fetched, unavailable), or — the unexplained case — the
 *               state says `available` and there is nothing to draw.
 *   B  SPEND, NO INDICATORS  the card's fixed Spend cell is statable and `CreativeRows::present()`
 *               gives it nothing to render beside it.
 *   C  INDICATORS, NO SPEND  the card renders figures beside Spend and Spend itself is not statable.
 *   D  OBJECTIVE METRIC MISSING THOUGH AVAILABLE  a metric the creative's objective family is JUDGED
 *               by is absent from the card while the SAME creative answers it at the grain the card
 *               did not read — asked of both grains through `CreativeMetrics::byGrain()`, the same
 *               two queries `forCreatives()` runs.
 *
 * Every rung calls the method the product calls. An instrument that re-derives a step of the
 * pipeline manufactures defects the product does not have, and this repository has paid for that.
 *
 * ## What it prints, and what it never prints
 *
 * The internal creative id, the provider, the stated format, the resolved kind, the objective, and
 * the rung. Never a name, a URL, a signed link, a token or an account — a workflow log is readable by
 * everybody with repository access, which is not the audience of the ad account.
 *
 * ## The one thing it must never do
 *
 * Write. No row inserted, updated or deleted, no job queued, no provider called. Guarded by the
 * every-column digest in `ContentDefectCensusTest`.
 */
final class ContentDefectCensusCommand extends Command
{
    protected $signature = 'content:census
        {--from= : Window start (YYYY-MM-DD). Default: 29 days before --to}
        {--to= : Window end (YYYY-MM-DD). Default: today}
        {--project= : One project. Omitted: every project that holds a creative}
        {--list=40 : How many creative ids to print per finding before summarising the rest}
        {--raw : For creatives whose spend is refused as a zero original, read the RETAINED provider bodies and say how spend arrived — absent, null, zero or positive — never the amount}
        {--fetch : Also LOAD each promoted creative\'s preview asset on the server and report what came back — by id, never the url}';

    protected $description = 'Read-only: list the creatives whose preview, spend or objective metrics are missing, and the rung each breaks at.';

    /** Retained bodies decoded per query — a Snapchat insights body can be large, and the command runs at 128MB. */
    private const RAW_CHUNK = 5;

    /** A hard ceiling on bodies read in one run, whatever the stored rows point at. */
    private const RAW_MAX_BODIES = 5000;

    /** States that draw nothing by design — truthful, but still a creative with no picture. */
    private const ABSENCE_STATES = ['withheld', 'expired', 'never_fetched', 'shape_not_fetched', 'unavailable'];

    /** How much of an asset is read to judge it: enough for a still's header, never the file. */
    private const PREFIX_BYTES = 262_144;

    /** @var list<string> stills that load in a browser although their declared type is wrong */
    private array $mislabelled = [];

    public function handle(): int
    {
        [$from, $to] = $this->window();
        $limit = max(1, (int) ($this->option('list') ?: 40));

        $projectOption = is_string($this->option('project')) ? trim($this->option('project')) : '';

        $projects = DB::table('external_creatives')
            ->when($projectOption !== '', fn ($q) => $q->where('project_id', $projectOption))
            ->select('project_id', 'tenant_id', DB::raw('COUNT(*) AS creatives'))
            ->groupBy('project_id', 'tenant_id')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        $this->line('');
        $this->line('CONTENT DEFECT CENSUS — every creative, the product\'s own services, no guesses');
        $this->line('  window    : '.$from->toDateString().' → '.$to->toDateString());
        $this->line('  projects  : '.$projects->count());
        $this->line('  prints    : internal creative id · provider · format · kind · objective · rung. Never a name, url or account.');

        if ($projects->isEmpty()) {
            $this->warn('No project holds any creative. Nothing to census.');

            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            $this->censusProject((string) $project->project_id, (string) $project->tenant_id, $from, $to, $limit);
        }

        return self::SUCCESS;
    }

    private function censusProject(string $projectId, string $tenantId, Carbon $from, Carbon $to, int $limit): void
    {
        /* The contexts every request carries — the demo policy reads the project from here. */
        app(TenantContext::class)->setTenantId($tenantId);
        app(ProjectContext::class)->setProjectId($projectId);

        $creatives = ExternalCreative::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->get();

        $ids = array_map('strval', $creatives->modelKeys());

        $metrics = app(CreativeMetrics::class);
        $presenter = app(CreativePresenter::class);

        // The card exactly as the library builds it — objective, headline_metrics and figures included.
        $cards = [];
        foreach (app(CreativeRows::class)->present($creatives, $from, $to, withFatigue: false) as $row) {
            $cards[(string) ($row['id'] ?? '')] = $row;
        }

        $grains = $metrics->byGrain($ids, $from, $to);

        /** @var array<string, array<string, list<string>>> $findings category => rung => ids */
        $findings = ['A' => [], 'B' => [], 'C' => [], 'D' => [], 'E' => []];
        /** @var list<array{tag: string, what: string, url: string}> $toFetch */
        $toFetch = [];
        /** @var list<string> $zeroOriginal */
        $zeroOriginal = [];
        /** @var array<string, array<string, true>> $overZero ratio => creative ids whose own grain reported its denominator as zero */
        $overZero = [];
        $promoted = 0;
        $judgedPreview = 0;
        /**
         * CONTENT-COLLECTION-TILES-001 — every shape, and how many of them actually DRAW.
         *
         * This command lists DEFECTS, which means a healthy shape is invisible in it. That sounds
         * like a small omission and it cost three separate Production runs to work around: asked
         * «does this account hold a STATIC collection, and does it draw», the defect list can only
         * answer «none of the broken ones is static», which is not the same sentence and is not
         * evidence of anything. The hierarchy command's own shape table answers it in SQL — and that
         * table is the one this row proved untrustworthy, because a `cards` column full of tiles
         * carrying no media reads there as «carries an asset link».
         *
         * So the inventory is taken HERE, where the judgement is the presenter's own `preview()` —
         * the same call the library makes — and «draws» means the envelope offered a url a browser
         * could load, not that a column was non-null.
         *
         * Counts and the platform's own format word. No ids, no names, no urls: the same bar as
         * every other line this command prints.
         *
         * ## One example id per shape, because a count cannot be opened
         *
         * «439 collections and every one of them draws» is a number somebody still has to take on
         * trust. The acceptance for CONTENT-COLLECTION-TILES-001 is a real Collection cover seen on
         * `/app/content`, and that needs an ADDRESS — which of the 439 to open. So each shape carries
         * the first id that draws and the first that does not, which is the smallest thing that turns
         * this from a claim into somewhere to go.
         *
         * Ids only, which this command already prints for every defect it lists. No name, no url.
         *
         * @var array<string, array{total: int, draws: int, drew: ?string, blank: ?string}> $inventory
         */
        $inventory = [];

        foreach ($creatives as $creative) {
            $id = (string) $creative->getKey();
            $card = $cards[$id] ?? [];
            $figures = is_array($card['metrics'] ?? null) ? $card['metrics'] : null;
            $objective = is_string($card['objective'] ?? null) ? $card['objective'] : null;
            $headline = array_values((array) ($card['headline_metrics'] ?? []));
            $delivered = $figures !== null || (bool) ($card['ad_delivered'] ?? false);

            if ($delivered) {
                $promoted++;
            }

            $tag = $id.'  '.(string) $creative->provider
                .'  format='.(string) ($creative->format ?? 'none')
                .'  objective='.($objective ?? 'none')
                .($delivered ? '  promoted' : '  no-delivery-in-window');

            // ── A — the preview envelope ──────────────────────────────────────────────────────
            $preview = $presenter->preview($creative);
            $judgedPreview++;
            $state = (string) ($preview['state'] ?? 'unknown');
            $kind = (string) ($preview['kind'] ?? 'unknown');
            $drawable = ($preview['image_url'] ?? null) !== null
                || ($preview['video_url'] ?? null) !== null
                || ($preview['thumbnail_url'] ?? null) !== null;

            /*
             * «Draws» means what the SURFACE paints, and the surface changed — so this follows it.
             *
             * One commit ago this asked stills only for a collection, because `adPreview.ts` resolved
             * a collection to `image_url ?? thumbnail_url` and ignored its film. That rule was right
             * about the code as it stood, and it is what surfaced the defect: 162 of 457 static
             * collections in Production counted as drawing nothing.
             *
             * A collection carrying a film now resolves to a FILM, and the card falls back to a
             * `<video>` that paints its first frame — the path `content-grid-video.spec.ts` already
             * proves for films. So a collection with a video paints again, and the special case is
             * gone rather than inverted: an instrument that kept it would now under-report exactly
             * the ads this change repairs.
             *
             * Kept as one line with its history, because the next person to widen a preview reading
             * has to know this count follows the reader and not the columns.
             */
            $shape = (string) ($creative->format ?? 'none');
            $inventory[$shape] ??= ['total' => 0, 'draws' => 0, 'drew' => null, 'blank' => null];
            $inventory[$shape]['total']++;
            if ($drawable) {
                $inventory[$shape]['draws']++;
                $inventory[$shape]['drew'] ??= $id;
            } else {
                $inventory[$shape]['blank'] ??= $id;
            }

            if (in_array($state, self::ABSENCE_STATES, true)) {
                $rung = $state === 'unavailable'
                    ? ($creative->cards === null ? 'unavailable — fetched, platform exposed no asset' : 'unavailable — cards fetched, none usable')
                    : $state;
                $findings['A']["kind={$kind}  state={$rung}  source=".(string) $creative->source_type][] = $tag;
            } elseif ($state === 'available' && ! $drawable && $kind !== 'catalog') {
                $findings['A']["kind={$kind}  state=available BUT NOTHING TO DRAW (unexplained blank)"][] = $tag;
            } elseif (! in_array($state, ['available'], true)) {
                $findings['A']["kind={$kind}  state={$state} (unrecognised)"][] = $tag;
            }

            if ((bool) $this->option('fetch') && $delivered && $state === 'available') {
                foreach ($this->assetsTheCardLoads($preview) as $what => $url) {
                    $toFetch[] = ['tag' => $tag, 'what' => $what, 'url' => $url];
                }
            }

            if ($figures === null) {
                continue;
            }

            $spendStatable = $metrics->statable($figures, 'spend');
            $beside = array_values(array_filter($headline, static fn (mixed $k): bool => $k !== 'spend'));

            // ── B — Spend with nothing beside it ──────────────────────────────────────────────
            if ($spendStatable && $beside === []) {
                $findings['B']['grain='.(string) ($figures['grain'] ?? '?').'  card headline holds only spend'
                    .$this->otherGrainNote($id, $figures, $grains, $metrics)][] = $tag;
            }

            // ── C — indicators with no Spend ──────────────────────────────────────────────────
            if (! $spendStatable && $beside !== []) {
                $rung = $this->spendRung($id, $figures, $grains);
                $findings['C'][$rung][] = $tag;

                if (str_contains($rung, 'original of ZERO')) {
                    $zeroOriginal[] = $id;
                }
            }

            // ── D — the objective's own verdict, missing here and answered at the other grain ─
            if ($objective !== null) {
                $family = $metrics->familyFor($objective)->headlineMetrics();
                $grain = (string) ($figures['grain'] ?? '');
                $other = $grain === 'creative' ? ($grains['ad'][$id] ?? null) : ($grains['creative'][$id] ?? null);

                if ($other !== null) {
                    $lost = [];
                    foreach ($family as $key) {
                        if ($key === 'spend' || in_array($key, $headline, true)) {
                            continue;
                        }

                        /*
                         * A ratio over a denominator this card's grain REPORTED as zero is cost per
                         * nothing — «—» is its truthful reading, not a metric the other grain restores.
                         * The Production run after #456 counted 46 sales creatives here whose own
                         * rows state zero orders beside converted spend.
                         */
                        if ($metrics->undefinedOverAReportedZero($figures, $key)) {
                            $overZero[$key][$id] = true;

                            continue;
                        }

                        if (! $metrics->statable($figures, $key) && $metrics->statable($other, $key)) {
                            $lost[] = $key;
                        }
                    }

                    if ($lost !== []) {
                        $findings['D']['family='.$metrics->familyFor($objective)->value.'  card read grain='.$grain
                            .'  answered only at the other grain: '.implode(', ', $lost)
                            .$this->whyNotFilled($figures, $other)][] = $tag;
                    }
                }
            }
        }

        $this->line('');
        $this->line(str_repeat('═', 96));
        $this->line('PROJECT '.$projectId);
        $this->line('  creatives : '.count($ids).'   promoted in window (figures or ad delivery): '.$promoted);
        $this->line('  figures   : '.count(array_filter($cards, static fn (array $c): bool => is_array($c['metrics'] ?? null)))
            .'   at creative grain: '.count($grains['creative'])
            .'   at ad grain: '.count($grains['ad'])
            .'   BOTH grains: '.count(array_intersect_key($grains['creative'], $grains['ad'])));

        if ($inventory !== []) {
            uasort($inventory, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            $this->line('  LIBRARY INVENTORY — every shape the platform named, and how many DRAW (presenter\'s own envelope)');

            foreach ($inventory as $shape => $counts) {
                $blank = $counts['total'] - $counts['draws'];

                $this->line(sprintf(
                    '    %-22s: %5d   draws %d · draws nothing %d%s',
                    $shape,
                    $counts['total'],
                    $counts['draws'],
                    $blank,
                    // Named rather than left to arithmetic: «0» in a column of numbers is easy to read past,
                    // and «every one of these draws» is the sentence somebody is actually looking for.
                    $blank === 0 ? '   ← every one of these draws' : '',
                ));

                $this->line(sprintf(
                    '      %-20s  open one that draws: %s%s',
                    '',
                    $counts['drew'] ?? '— none of them draws',
                    $counts['blank'] === null ? '' : '   · one that does not: '.$counts['blank'],
                ));
            }
        }

        $loaded = 0;
        foreach ($this->fetch($toFetch) as [$item, $verdict]) {
            if ($verdict === null) {
                $loaded++;

                continue;
            }

            $findings['E'][$item['what'].'  '.$verdict][] = $item['tag'];
        }

        $titles = [
            'A' => 'A — NO PREVIEW / MEDIA (envelope draws nothing)',
            'B' => 'B — SPEND SHOWN, NO PERFORMANCE INDICATORS BESIDE IT',
            'C' => 'C — PERFORMANCE INDICATORS SHOWN, NO SPEND',
            'D' => 'D — OBJECTIVE METRIC MISSING ON THE CARD THOUGH THE CREATIVE ANSWERS IT',
        ];

        if ((bool) $this->option('fetch')) {
            $titles['E'] = 'E — PREVIEW ASSET DOES NOT LOAD (fetched on the server: '.count($toFetch).' asset(s), '.$loaded.' loaded)';
        }

        foreach ($titles as $category => $title) {
            $total = array_sum(array_map('count', $findings[$category]));
            $this->line('');
            $this->line($title.' — '.$total.($category === 'A' ? ' of '.$judgedPreview : ''));

            if ($total === 0) {
                $this->line('    none');

                continue;
            }

            ksort($findings[$category]);

            foreach ($findings[$category] as $rung => $tags) {
                $promotedHere = count(array_filter($tags, static fn (string $t): bool => str_ends_with($t, '  promoted')));
                $this->line('  ▸ '.$rung.'  — '.count($tags).' ('.$promotedHere.' promoted)');

                // Promoted creatives first: those are the ones a reader is actually looking at.
                usort($tags, static fn (string $a, string $b): int => (int) str_ends_with($b, '  promoted') <=> (int) str_ends_with($a, '  promoted'));

                foreach (array_slice($tags, 0, $limit) as $t) {
                    $this->line('      '.$t);
                }

                if (count($tags) > $limit) {
                    $this->line('      … and '.(count($tags) - $limit).' more');
                }
            }
        }

        if ($this->mislabelled !== []) {
            $this->line('');
            $this->line('NOT A DEFECT — a still that LOADS although its declared content type is wrong (browsers draw by bytes) — '.count($this->mislabelled));

            foreach ($this->mislabelled as $line) {
                $this->line('      '.$line);
            }
        }

        $this->mislabelled = [];

        if ($overZero !== []) {
            $this->line('');
            $this->line('NOT A DEFECT — a ratio over a denominator the card\'s own grain REPORTED as zero (cost per nothing; «—» is truthful)');

            foreach ($overZero as $ratio => $creatives) {
                $this->line('  ▸ '.$ratio.'  — '.count($creatives).' creative(s)');
            }
        }

        if ((bool) $this->option('raw')) {
            $this->line('');
            $this->line('C EVIDENCE — how the provider sent spend for the zero-original creatives (retained bodies, window only)');

            /*
             * Read AFTER every section is printed: on Production the previous lookup timed out and took
             * the E list down with it. A failed read is said in one line — never the SQL, which carries
             * ids into a public log.
             */
            try {
                $evidence = $zeroOriginal === [] ? [] : $this->rawSpendEvidence($zeroOriginal, $from, $to);
            } catch (Throwable $e) {
                $evidence = [];
                $this->line('    the lookup failed ('.class_basename($e).' '.$e->getCode().') — no evidence read');
            }

            /*
             * «none to read» had two readings, and Production hit the one nobody wanted (run
             * 35478164776): C was EMPTY, and the line read as «the bodies could not explain C».
             *
             * «Nothing to explain» and «we cannot tell» are different answers, and the second is the
             * one a decision about releasing a refused zero as a reported 0 would rest on. So the line
             * says which silence it is, counted from what the census itself just listed.
             */
            if ($evidence === []) {
                $inC = array_sum(array_map('count', $findings['C']));

                $this->line('    '.match (true) {
                    $inC === 0 => 'no creative is in C for this window — nothing to explain',
                    default => 'C holds '.$inC.' creative(s), none of them refused as a zero original — '
                        .'their spend is absent for the reason each row states above, which these bodies cannot add to',
                });
            }

            foreach ($evidence as $creativeId => $line) {
                $this->line('      '.$creativeId.'  '.$line);
            }
        }
    }

    /**
     * What the provider's OWN body said about spend for the stored zero rows — the question a stored
     * zero cannot answer by itself.
     *
     * `spend_original = 0` is either the platform reporting zero or ingestion turning a JSON null into
     * 0 (`(float) null`). Each stored ad-grain row names the sync run that last wrote it, and that
     * run's bodies are retained under the same id — so the reader follows the row to the exact body
     * that produced it, by two indexed columns, and reads nothing else. (Found on Production: a text
     * match over every retained insights body in the window hit the 60s statement timeout.) Counts
     * only — never an amount.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, string>
     */
    private function rawSpendEvidence(array $creativeIds, Carbon $from, Carbon $to): array
    {
        $ads = DB::table('external_ads')
            ->whereIn('creative_id', $creativeIds)
            ->get(['creative_id', 'external_id']);

        /** @var array<string, string> $creativeByAd */
        $creativeByAd = [];
        foreach ($ads as $ad) {
            $creativeByAd[(string) $ad->external_id] = (string) $ad->creative_id;
        }

        if ($creativeByAd === []) {
            return array_fill_keys($creativeIds, 'no ads recorded for this creative');
        }

        /** @var array<string, array<string, list<string>>> $wanted run => ad => dates */
        $wanted = [];
        /** @var array<string, array{rows: int, no_run: int}> $stored */
        $stored = [];

        foreach (DB::table('entity_daily_metrics')
            ->where('project_id', (string) app(ProjectContext::class)->projectId())
            ->where('entity_type', 'ad')
            ->whereIn('external_entity_id', array_keys($creativeByAd))
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('spend')
            ->whereNotNull('spend_original')
            ->get(['external_entity_id', 'metric_date', 'sync_run_id']) as $row) {
            $adId = (string) $row->external_entity_id;
            $creativeId = $creativeByAd[$adId];
            $stored[$creativeId] ??= ['rows' => 0, 'no_run' => 0];
            $stored[$creativeId]['rows']++;

            if ($row->sync_run_id === null) {
                $stored[$creativeId]['no_run']++;

                continue;
            }

            $wanted[(string) $row->sync_run_id][$adId][] = substr((string) $row->metric_date, 0, 10);
        }

        /** @var array<string, array{absent: int, null: int, zero: int, positive: int, delivered: int, missing: int, unread: int}> $tally */
        $tally = [];
        $blank = ['absent' => 0, 'null' => 0, 'zero' => 0, 'positive' => 0, 'delivered' => 0, 'missing' => 0, 'unread' => 0];
        $bodiesRead = 0;

        DB::statement("SET statement_timeout = '20s'");

        try {
            foreach ($wanted as $runId => $datesByAd) {
                $runAds = array_intersect_key($creativeByAd, $datesByAd);
                /** @var array<string, array<string, array{spend: string, delivered: bool}>> $points */
                $points = [];
                $readable = true;

                try {
                    $lastId = '00000000-0000-0000-0000-000000000000';
                    do {
                        $chunk = DB::table('integration_raw_payloads')
                            ->where('sync_run_id', $runId)
                            ->where('resource', 'insights')
                            ->where('id', '>', $lastId)
                            ->orderBy('id')
                            ->limit(self::RAW_CHUNK)
                            ->get(['id', 'payload']);

                        foreach ($chunk as $body) {
                            $lastId = (string) $body->id;
                            $bodiesRead++;
                            $decoded = json_decode((string) $body->payload, true);

                            if (is_array($decoded)) {
                                $this->walkForAds($decoded, $runAds, $points, $from, $to);
                            }
                        }
                    } while ($chunk->count() === self::RAW_CHUNK && $bodiesRead < self::RAW_MAX_BODIES);
                } catch (Throwable) {
                    $readable = false;
                }

                foreach ($datesByAd as $adId => $dates) {
                    $t = &$tally[$creativeByAd[$adId]];
                    $t ??= $blank;

                    foreach ($dates as $date) {
                        $point = $points[$adId][$date] ?? null;

                        if (! $readable) {
                            $t['unread']++;
                        } elseif ($point === null) {
                            $t['missing']++;
                        } else {
                            $t[$point['spend']]++;
                            $t['delivered'] += $point['delivered'] ? 1 : 0;
                        }
                    }

                    unset($t);
                }
            }
        } finally {
            DB::statement('RESET statement_timeout');
        }

        $out = [];
        foreach ($creativeIds as $creativeId) {
            $s = $stored[$creativeId] ?? null;

            if ($s === null) {
                $out[$creativeId] = 'no stored ad-grain row withholds spend in the window';

                continue;
            }

            $t = $tally[$creativeId] ?? $blank;
            $out[$creativeId] = sprintf(
                'stored withheld rows %d — in the body of the run that wrote them: spend key absent %d, JSON null %d, zero %d, positive %d; delivered impressions on %d; not in that run\'s bodies %d; run unrecorded %d; bodies unreadable %d',
                $s['rows'], $t['absent'], $t['null'], $t['zero'], $t['positive'], $t['delivered'], $t['missing'], $s['no_run'], $t['unread'],
            );
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, string>  $creativeByAd
     * @param  array<string, array<string, array{spend: string, delivered: bool}>>  $points
     */
    private function walkForAds(array $node, array $creativeByAd, array &$points, Carbon $from, Carbon $to): void
    {
        $id = $node['id'] ?? null;

        if (is_string($id) && isset($creativeByAd[$id]) && is_array($node['timeseries'] ?? null)) {
            foreach ($node['timeseries'] as $point) {
                if (! is_array($point)) {
                    continue;
                }

                $date = substr((string) ($point['start_time'] ?? ''), 0, 10);

                if ($date === '' || $date < $from->toDateString() || $date > $to->toDateString() || isset($points[$id][$date])) {
                    continue;
                }

                $stats = is_array($point['stats'] ?? null) ? $point['stats'] : [];

                $points[$id][$date] = [
                    'spend' => match (true) {
                        ! array_key_exists('spend', $stats) => 'absent',
                        $stats['spend'] === null => 'null',
                        (float) $stats['spend'] === 0.0 => 'zero',
                        default => 'positive',
                    },
                    'delivered' => is_numeric($stats['impressions'] ?? null) && (float) $stats['impressions'] > 0,
                ];
            }

            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walkForAds($child, $creativeByAd, $points, $from, $to);
            }
        }
    }

    /**
     * Why `forCreatives()` did not take a metric the other grain answers — so the next fix is chosen by
     * the data, not guessed. Days covered by each grain (the coverage rule), whether the card's own
     * spend is converted, withheld or absent (a cost-per needs converted spend), and whether its own
     * conversions were reported. States only, never amounts.
     *
     * @param  array<string, mixed>  $figures
     * @param  array<string, mixed>  $other
     */
    private function whyNotFilled(array $figures, array $other): string
    {
        $mine = (int) ($figures['active_days'] ?? 0);
        $theirs = (int) ($other['active_days'] ?? 0);

        $spend = match (true) {
            ($figures['spend'] ?? null) !== null => 'converted',
            (int) ($figures['spend_withheld_rows'] ?? 0) > 0 => 'withheld',
            default => 'absent',
        };

        return '  [days: card '.$mine.', other '.$theirs.($theirs < $mine ? ' — fewer, so not filled' : '')
            .'; card spend '.$spend
            .'; card conversions '.(($figures['conversions'] ?? null) !== null ? 'reported' : 'absent').']';
    }

    /**
     * The assets a card LOADS for an available preview, keyed by what they are for.
     *
     * Mirrors `adPreview.ts`: a still (the image, else the thumbnail) drawn as an `<img>`, and for a
     * film the video the player streams — a film with no still is played rather than blanked, so the
     * video itself is what must load. A catalog ad has no fixed asset by design and loads nothing.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, string>
     */
    private function assetsTheCardLoads(array $preview): array
    {
        if ((string) ($preview['kind'] ?? '') === 'catalog') {
            return [];
        }

        $out = [];
        $still = $preview['image_url'] ?? $preview['thumbnail_url'] ?? null;

        if (is_string($still) && $still !== '') {
            $out['still'] = $still;
        }

        if (is_string($preview['video_url'] ?? null) && $preview['video_url'] !== '') {
            $out['video'] = (string) $preview['video_url'];
        }

        return $out;
    }

    /**
     * Load every asset, a few at a time, and say what is wrong with each one that does not load.
     *
     * A verdict of null is «loaded and usable». Anything else names the failure — the HTTP status,
     * a content type that is not the medium the card asked for, or a still the browser could not
     * decode — and never the address, which carries the signature that makes it work.
     *
     * @param  list<array{tag: string, what: string, url: string}>  $items
     * @return list<array{0: array{tag: string, what: string, url: string}, 1: string|null}>
     */
    private function fetch(array $items): array
    {
        $out = [];

        foreach (array_chunk($items, 8) as $chunk) {
            $remote = [];

            foreach ($chunk as $i => $item) {
                if (str_starts_with($item['url'], 'data:')) {
                    $out[] = [$item, $this->judgeInline($item)];

                    continue;
                }

                $remote[$i] = $item;
            }

            if ($remote === []) {
                continue;
            }

            try {
                $responses = Http::pool(function (Pool $pool) use ($remote): array {
                    $requests = [];

                    foreach ($remote as $i => $item) {
                        /*
                         * STREAMED, and only the first bytes asked for — found on Production.
                         *
                         * The first `--fetch` run died on the VPS at 128 MB: every response body was
                         * buffered whole, and a still can be megabytes and a film far more (a server
                         * free to ignore `Range` sends the whole file). What is being judged — the
                         * status, the content type, and whether a still's header decodes — lives in
                         * the first few kilobytes, so that is all that is read.
                         */
                        $requests[] = $pool->as((string) $i)
                            ->timeout(20)
                            ->withOptions(['allow_redirects' => true, 'stream' => true])
                            ->withHeaders(['Range' => 'bytes=0-'.(self::PREFIX_BYTES - 1)])
                            ->get($item['url']);
                    }

                    return $requests;
                });
            } catch (Throwable) {
                foreach ($remote as $item) {
                    $out[] = [$item, 'request failed'];
                }

                continue;
            }

            foreach ($remote as $i => $item) {
                $response = $responses[(string) $i] ?? null;
                $out[] = [$item, $response instanceof Response ? $this->judge($item, $response) : 'request failed (no response)'];
            }
        }

        return $out;
    }

    /**
     * At most PREFIX_BYTES of the body, read from the stream and then released — never the whole file.
     */
    private function prefix(Response $response): string
    {
        $body = $response->toPsrResponse()->getBody();
        $read = '';

        while (! $body->eof() && strlen($read) < self::PREFIX_BYTES) {
            $chunk = $body->read(self::PREFIX_BYTES - strlen($read));

            if ($chunk === '') {
                break;
            }

            $read .= $chunk;
        }

        $body->close();

        return $read;
    }

    /**
     * What a body IS, from its leading bytes — never its content. A multipart envelope is described by
     * the content types its parts declare within the prefix, each re-sniffed.
     */
    private function signature(string $bytes): string
    {
        $sniff = DrawableImage::sniff(...);

        if ($bytes === '') {
            return 'empty';
        }

        if (($kind = $sniff($bytes)) !== null) {
            return $kind.(@getimagesizefromstring($bytes) === false ? ' signature, does not decode' : ' image that decodes');
        }

        if (preg_match('/^\s*--([^\r\n]{1,200})\r?\n/', $bytes, $m) === 1) {
            $parts = [];
            foreach (explode('--'.$m[1], $bytes) as $part) {
                if (preg_match('/^\r?\n(.*?)\r?\n\r?\n(.*)$/s', $part, $p) !== 1) {
                    continue;
                }
                preg_match('/content-type:\s*([^\r\n;]+)/i', $p[1], $ct);
                $parts[] = strtolower(trim($ct[1] ?? 'no type')).'='.($sniff($p[2]) ?? 'not an image');
            }

            return 'multipart envelope, parts in prefix: '.($parts === [] ? 'none' : implode(', ', $parts));
        }

        return 'unrecognised, first bytes '.bin2hex(substr($bytes, 0, 4));
    }

    /** @param array{tag: string, what: string, url: string} $item */
    private function judge(array $item, Response $response): ?string
    {
        if (! $response->successful()) {
            return 'http '.$response->status();
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if ($item['what'] === 'video') {
            return str_starts_with($type, 'video/') || $type === 'application/octet-stream' || $type === 'binary/octet-stream'
                ? null
                : 'not a video (content type '.($type === '' ? 'none' : $type).')';
        }

        if (! str_starts_with($type, 'image/')) {
            $bytes = $this->prefix($response);

            /*
             * Browsers draw an <img> by its bytes, not its declared type — measured on chromium, firefox
             * and webkit, with and without `nosniff`. An allow-listed image that decodes therefore LOADS
             * on the card; it is recorded as mislabelled, not as a blank. Anything else is still a blank.
             */
            if (DrawableImage::draws($bytes) && ($kind = DrawableImage::sniff($bytes)) !== null) {
                $this->mislabelled[] = $item['tag'].'  declared '.($type === '' ? 'none' : $type).', bytes '.$kind;

                return null;
            }

            // The declared type is not the evidence — the bytes are. Say what they actually are.
            return 'not an image (content type '.($type === '' ? 'none' : $type).'; bytes: '.$this->signature($bytes).')';
        }

        return @getimagesizefromstring($this->prefix($response)) === false ? 'an image that does not decode' : null;
    }

    /** @param array{tag: string, what: string, url: string} $item */
    private function judgeInline(array $item): ?string
    {
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', $item['url'], $m) !== 1) {
            return 'inline data that is not a base64 image';
        }

        $bytes = base64_decode($m[2], true);

        return $bytes === false || @getimagesizefromstring($bytes) === false ? 'an inline image that does not decode' : null;
    }

    /**
     * Why a card's Spend is not statable — which grain was read, and what the other grain holds.
     *
     * @param  array<string, mixed>  $figures
     * @param  array{creative: array<string, array<string, mixed>>, ad: array<string, array<string, mixed>>}  $grains
     */
    private function spendRung(string $id, array $figures, array $grains): string
    {
        $grain = (string) ($figures['grain'] ?? '?');
        $withheld = (int) ($figures['spend_withheld_rows'] ?? 0);

        /*
         * WHICH clause of the money contract refuses it — never the amount, only its sign and the
         * number of currencies. «Several currencies» and «a summed original of zero» are different
         * defects with different honest outcomes, and the first census run could not tell them apart.
         */
        $original = $figures['spend_original'] ?? null;
        $currencies = (int) ($figures['money_original_currencies'] ?? 0);

        $state = match (true) {
            $withheld > 0 && $currencies > 1 => 'withheld rows='.$withheld.' in '.$currencies.' original currencies',
            $withheld > 0 && is_numeric($original) && (float) $original === 0.0 => 'withheld rows='.$withheld.' summing to an original of ZERO',
            $withheld > 0 && is_numeric($original) && (float) $original < 0.0 => 'withheld rows='.$withheld.' summing to a NEGATIVE original',
            $withheld > 0 => 'withheld rows='.$withheld.' with no usable original currency',
            default => 'the platform sent no spend at this grain',
        };

        $otherKey = $grain === 'creative' ? 'ad' : 'creative';
        $other = $grains[$otherKey][$id] ?? null;
        $otherNote = $other === null
            ? '  other grain: no rows'
            : '  other grain ('.$otherKey.'): spend '.(app(CreativeMetrics::class)->statable($other, 'spend')
                ? ((int) ($other['active_days'] ?? 0) < (int) ($figures['active_days'] ?? 0)
                    ? 'statable but over fewer days, so it is not used'
                    : 'STATABLE — the card read the wrong grain')
                : 'absent too');

        return 'card read grain='.$grain.'  '.$state.$otherNote;
    }

    /**
     * For a Spend-only card, whether the other grain would have given it indicators.
     *
     * @param  array<string, mixed>  $figures
     * @param  array{creative: array<string, array<string, mixed>>, ad: array<string, array<string, mixed>>}  $grains
     */
    private function otherGrainNote(string $id, array $figures, array $grains, CreativeMetrics $metrics): string
    {
        $otherKey = ($figures['grain'] ?? null) === 'creative' ? 'ad' : 'creative';
        $other = $grains[$otherKey][$id] ?? null;

        if ($other === null) {
            return '  other grain: no rows';
        }

        $answered = [];
        foreach (['impressions', 'clicks', 'reach', 'conversions', 'leads', 'installs', 'video_views', 'revenue'] as $key) {
            if ($metrics->statable($other, $key)) {
                $answered[] = $key;
            }
        }

        return '  other grain ('.$otherKey.') answers: '.($answered === [] ? 'nothing either' : implode(', ', $answered));
    }

    /**
     * The window, inclusive. An EMPTY option is absent — the workflow passes every value unconditionally,
     * and `Carbon::parse('')` is today, which quietly turned a thirty-day question into a one-day one on
     * the first production run of `content:reconcile`.
     *
     * @return array{Carbon, Carbon}
     */
    private function window(): array
    {
        $option = static function (mixed $value): ?string {
            $value = is_string($value) ? trim($value) : null;

            return ($value ?? '') === '' ? null : $value;
        };

        $toOption = $option($this->option('to'));
        $fromOption = $option($this->option('from'));

        $to = $toOption === null ? Carbon::now()->endOfDay() : Carbon::parse($toOption)->endOfDay();
        $from = $fromOption === null ? $to->copy()->subDays(29)->startOfDay() : Carbon::parse($fromOption)->startOfDay();

        return [$from, $to];
    }
}
