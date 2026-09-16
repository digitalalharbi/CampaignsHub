<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativeRows;
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
        {--fetch : Also LOAD each promoted creative\'s preview asset on the server and report what came back — by id, never the url}';

    protected $description = 'Read-only: list the creatives whose preview, spend or objective metrics are missing, and the rung each breaks at.';

    /** States that draw nothing by design — truthful, but still a creative with no picture. */
    private const ABSENCE_STATES = ['withheld', 'expired', 'never_fetched', 'shape_not_fetched', 'unavailable'];

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
        $promoted = 0;
        $judgedPreview = 0;

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
                $findings['C'][$this->spendRung($id, $figures, $grains)][] = $tag;
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
                        $request = $pool->as((string) $i)->timeout(20)->withOptions(['allow_redirects' => true]);

                        $requests[] = $item['what'] === 'video'
                            ? $request->withHeaders(['Range' => 'bytes=0-1023'])->get($item['url'])
                            : $request->get($item['url']);
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
            return 'not an image (content type '.($type === '' ? 'none' : $type).')';
        }

        return @getimagesizefromstring($response->body()) === false ? 'an image that does not decode' : null;
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
