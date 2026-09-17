<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CardPopupParity;
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
        {--scope : Reconcile a whole project\'s library instead of one creative}
        {--strict : Exit non-zero when a divergence is found}';

    protected $description = 'Read-only: walk one creative from the provider rows to every Content surface and name the divergences.';

    /** @var list<string> */
    private array $divergences = [];

    public function handle(): int
    {
        [$from, $to] = $this->window();

        if ($this->option('scope')) {
            return $this->reconcileScope($from, $to);
        }

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

        $this->cardAgainstPopup($card + ['preview' => $preview]);

        if (($preview['image_url'] ?? null) === null && ($preview['video_url'] ?? null) === null
            && ($preview['thumbnail_url'] ?? null) === null && ! is_array($preview['cards'] ?? null)) {
            $this->mediaProvenance($creative);
        }

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

    /** Retained structure bodies decoded per query: a creatives page can be large and the command runs at 128MB. */
    private const STRUCTURE_CHUNK = 5;

    /** A hard ceiling on bodies read for one sweep. */
    private const STRUCTURE_MAX_BODIES = 400;

    /**
     * RUNG 11 — where the platform's media stopped, read from the latest sweep's OWN bodies.
     *
     * Census A found four Snapchat collections with nothing to draw while hundreds on the same account
     * draw. The row can only say «nothing arrived»; the structure sweep retained what the platform
     * actually sent, so this follows the creative through it: was it in the creatives edge, did it name
     * a top snap, did the media lookup answer for that snap, and in what state.
     *
     * Read-only, bounded (one sweep, found by account; bodies keyset by id, a few per query, a statement
     * timeout) and printing key names and platform enums only — never an id, a name, a copy or a link.
     */
    private function mediaProvenance(ExternalCreative $creative): void
    {
        $this->line('');
        $this->line('RUNG 11 — where the media stopped (the latest retained structure sweep; key names and platform states only)');
        $this->line('    row last synced       : '.($creative->last_synced_at?->toDateTimeString() ?? 'never'));

        if ((string) $creative->provider !== 'snapchat') {
            $this->line('    not walked — only Snapchat structure bodies are read here');

            return;
        }

        $accountId = $creative->external_campaign_id === null
            ? null
            : DB::table('external_campaigns')->where('id', (string) $creative->external_campaign_id)->value('external_account_id');

        if ($accountId === null) {
            $this->line('    no campaign on the row, so no ad account whose sweep could be read');

            return;
        }

        $externalId = (string) $creative->external_creative_id;

        DB::statement("SET statement_timeout = '20s'");

        try {
            $sweep = DB::table('integration_raw_payloads')
                ->where('external_account_id', (string) $accountId)
                ->where('resource', 'structure')
                ->whereNotNull('sync_run_id')
                ->orderByDesc('fetched_at')
                ->limit(1)
                ->first(['sync_run_id', 'fetched_at']);

            if ($sweep === null) {
                $this->line('    no retained structure body for this account');

                return;
            }

            $found = null;
            $mediaId = null;
            /** @var array<string, int> $adStatuses */
            $adStatuses = [];
            $mediaBodies = 0;
            $mediaEntry = null;
            $bodies = 0;
            $lastId = '00000000-0000-0000-0000-000000000000';

            do {
                $chunk = DB::table('integration_raw_payloads')
                    ->where('sync_run_id', (string) $sweep->sync_run_id)
                    ->where('resource', 'structure')
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit(self::STRUCTURE_CHUNK)
                    ->get(['id', 'payload']);

                foreach ($chunk as $row) {
                    $lastId = (string) $row->id;
                    $bodies++;
                    $body = json_decode((string) $row->payload, true);

                    if (! is_array($body)) {
                        continue;
                    }

                    foreach ((array) ($body['creatives'] ?? []) as $wrapper) {
                        $c = (array) (((array) $wrapper)['creative'] ?? []);

                        if ((string) ($c['id'] ?? '') === $externalId) {
                            $found = $c;
                            $mediaId = is_string($c['top_snap_media_id'] ?? null) ? $c['top_snap_media_id'] : null;
                        }
                    }

                    foreach ((array) ($body['ads'] ?? []) as $wrapper) {
                        $a = (array) (((array) $wrapper)['ad'] ?? []);

                        if ((string) ($a['creative_id'] ?? '') === $externalId) {
                            $status = self::enum($a['status'] ?? null);
                            $adStatuses[$status] = ($adStatuses[$status] ?? 0) + 1;
                        }
                    }

                    if (array_key_exists('media', $body)) {
                        $mediaBodies++;

                        foreach ((array) $body['media'] as $wrapper) {
                            $wrapper = (array) $wrapper;
                            $m = (array) ($wrapper['media'] ?? []);
                            $mediaEntry[(string) ($m['id'] ?? '')] = [
                                'type' => self::enum($m['type'] ?? null),
                                'media_status' => self::enum($m['media_status'] ?? null),
                                'sub_request_status' => self::enum($wrapper['sub_request_status'] ?? null),
                                'link' => is_string($m['download_link'] ?? null) && $m['download_link'] !== '' ? 'present' : 'absent',
                            ];
                        }
                    }
                }
            } while ($chunk->count() === self::STRUCTURE_CHUNK && $bodies < self::STRUCTURE_MAX_BODIES);
        } catch (\Throwable $e) {
            // Never the SQL: it carries ids into a public log.
            $this->line('    the read failed ('.class_basename($e).' '.$e->getCode().')');

            return;
        } finally {
            DB::statement('RESET statement_timeout');
        }

        $this->line('    sweep read            : '.(string) $sweep->fetched_at.'  ('.$bodies.' bodies'.($bodies >= self::STRUCTURE_MAX_BODIES ? ', ceiling reached' : '').')');

        if ($found === null) {
            $this->line('    in the creatives edge : no — the latest sweep did not return this creative, so the row keeps what an earlier sweep wrote');

            return;
        }

        $keys = [];
        foreach ($found as $key => $value) {
            $keys[] = (string) $key;

            if (is_array($value)) {
                foreach (array_keys($value) as $inner) {
                    if (! is_int($inner)) {
                        $keys[] = $key.'.'.$inner;
                    }
                }
            }
        }
        sort($keys);

        ksort($adStatuses);
        $this->line('    in the creatives edge : yes — type '.self::enum($found['type'] ?? null));
        $this->line('    body keys             : '.implode(', ', $keys));
        $this->line('    top_snap_media_id     : '.($mediaId === null ? 'absent' : 'present'));
        $this->line('    ads naming it         : '.array_sum($adStatuses).($adStatuses === [] ? '' : ' ('.implode(', ', array_map(
            static fn (string $s, int $n): string => $s.' '.$n, array_keys($adStatuses), $adStatuses,
        )).')'));

        if ($mediaId === null) {
            $this->line('    media lookup          : not asked — the body names no top snap');

            return;
        }

        $entry = $mediaEntry[$mediaId] ?? null;

        $this->line('    media lookup          : '.match (true) {
            $mediaBodies === 0 => 'no media body in the sweep — the lookup failed or was never made',
            $entry === null => 'this snap is in no media body of the sweep ('.$mediaBodies.' media bodies)',
            default => sprintf(
                'answered for this snap — type %s, media_status %s, sub_request_status %s, download_link %s',
                $entry['type'], $entry['media_status'], $entry['sub_request_status'], $entry['link'],
            ),
        });
    }

    /** A platform enum as printed: upper-case words only, anything else is not echoed. */
    private static function enum(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'unstated';
        }

        return preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $value) === 1 ? $value : 'other';
    }

    /**
     * RUNG 10 — what the CARD and the POPUP each resolve to, from this one payload.
     *
     * OWNER CONTENT P0: the owner read a card showing Spend and three figures beside a popup showing
     * five more, and a card drawing a picture whose creative said it had no cover. #505 closed both
     * and guarded them in a browser — which is exactly what cannot be pointed at Production here, so
     * this asks the same question on the server, of the payload the two surfaces actually receive.
     *
     * `CardPopupParity` mirrors the browser's own rules and is held to them by its own test. The
     * output is metric KEYS with their states, the preview DECISION each surface reads, and one
     * verdict. Never a value, a name, an account or a url.
     *
     * @param  array<string, mixed>  $card  the library row, as `CreativeRows::present()` returns it
     */
    private function cardAgainstPopup(array $card): void
    {
        $parity = app(CardPopupParity::class);

        $cardFigures = $parity->card($card);
        $popupFigures = $parity->popup($card);
        $preview = $parity->preview($card);

        $say = static fn (array $figures): string => $figures === []
            ? 'none'
            : implode(', ', array_map(
                static fn (string $key, string $state): string => $key.'='.$state,
                array_keys($figures),
                $figures,
            ));

        $shape = static fn (string $draws): string => sprintf(
            'kind=%s  state=%s  hero=%s  tiles=%s  draws=%s',
            $preview['kind'], $preview['state'], $preview['hero'], $preview['tiles'], $draws,
        );

        $differences = $parity->differences($cardFigures, $popupFigures, $preview);

        $this->line('');
        $this->line('RUNG 10 — CARD ↔ POPUP  (what each surface resolves to from this payload; keys and states, never values)');
        $this->line('    card  figures : '.$say($cardFigures));
        $this->line('    popup figures : '.$say($popupFigures));
        $this->line('    card  preview : '.$shape($preview['card_draws']));
        $this->line('    popup preview : '.$shape($preview['popup_draws']));
        $this->line('    PARITY        : '.($differences === [] ? 'MATCH' : 'DIFFERS'));

        foreach ($differences as $difference) {
            $this->line('                  · '.$difference);
            $this->divergences[] = 'card ↔ popup: '.$difference;
        }
    }

    /**
     * The window, inclusive, defaulting to the library's own last thirty days.
     *
     * @return array{Carbon, Carbon}
     */
    private function window(): array
    {
        /*
         * An EMPTY option is absent — found by running this on production.
         *
         * `production-diagnostics.yml` passes every value unconditionally, because a shell that
         * assembles flags conditionally is a shell that eventually assembles a command. So a caller
         * who names no window sends `--from="" --to=""`, and these read `=== null`, which an empty
         * string is not: `Carbon::parse('')` is TODAY, so the thirty-day default became a single day.
         *
         * The consequence was not an error. The first production reading came back «window 2026-09-16
         * → 2026-09-16 … 39 with figures … THE STRIP WAS SHORT BY 0.00» — a real answer about a
         * one-day window, indistinguishable from the thirty-day answer it was asked for, and it
         * understated the finding it was built to measure. An instrument that quietly answers a
         * different question than the one asked is worse than one that fails.
         */
        $option = static function (mixed $value): ?string {
            $value = is_string($value) ? trim($value) : null;

            return ($value ?? '') === '' ? null : $value;
        };

        $toOption = $option($this->option('to'));
        $fromOption = $option($this->option('from'));

        $to = $toOption === null
            ? Carbon::now()->endOfDay()
            : Carbon::parse($toOption)->endOfDay();

        $from = $fromOption === null
            ? $to->copy()->subDays(29)->startOfDay()
            : Carbon::parse($fromOption)->startOfDay();

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
        $reference = is_string($reference) ? trim($reference) : null;

        if ($reference !== null && $reference !== '') {
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
            if (in_array($key, ['reported', 'grain', 'active_days', 'creatives', 'from_ads', 'ratio_inputs'], true)) {
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

    /**
     * The same question asked of a whole LIBRARY — what the headline strip states against what the
     * cards beneath it state.
     *
     * ## Why a scope mode as well as a creative one
     *
     * The per-creative walk answers «which rung does this subject fall off». It cannot answer «how
     * much is the strip short by», and that is the number the owner's screen actually shows: the
     * library's headline is one figure over hundreds of cards, and a strip that consults fewer
     * sources than they do is wrong by whatever the creatives it cannot see spent.
     *
     * The split is read from the rows rather than assumed: a creative whose figures come from the AD
     * grain is one `creative_daily_metrics` never held, and on five of six providers that is all of
     * them. Reporting the two pools separately is what makes «the strip was short» a measured
     * quantity instead of an argument about a code path.
     *
     * Still read-only, and still the product's own services — the creative-grain-only pool is built
     * by filtering `forCreatives()`'s answer on the `grain` it already states, never by a second
     * query that could disagree with it.
     */
    private function reconcileScope(Carbon $from, Carbon $to): int
    {
        $projectId = is_string($this->option('project')) && $this->option('project') !== ''
            ? (string) $this->option('project')
            : (string) DB::table('external_creatives')
                ->select('project_id')
                ->groupBy('project_id')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(1)
                ->value('project_id');

        if ($projectId === '') {
            $this->warn('No project holds any creative. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $tenantId = (string) DB::table('external_creatives')->where('project_id', $projectId)->value('tenant_id');

        app(TenantContext::class)->setTenantId($tenantId);
        app(ProjectContext::class)->setProjectId($projectId);

        $ids = DB::table('external_creatives')
            ->where('project_id', $projectId)
            ->pluck('id')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        $metrics = app(CreativeMetrics::class);
        $figures = $metrics->forCreatives($ids, $from, $to);

        $native = array_filter($figures, static fn (array $f): bool => ($f['grain'] ?? null) === 'creative');
        $fromAds = array_filter($figures, static fn (array $f): bool => ($f['grain'] ?? null) === 'ad');

        $strip = $metrics->totalsFor($ids, $from, $to);
        $cards = $metrics->aggregate(array_values($figures));
        $nativeOnly = $native === [] ? null : $metrics->aggregate(array_values($native));

        $this->line('');
        $this->line('CONTENT TRUTH CHAIN — a whole library, strip against cards');
        $this->line('  project   : '.$projectId);
        $this->line('  window    : '.$from->toDateString().' → '.$to->toDateString());
        $this->line('  creatives : '.count($ids).' in the project, '.count($figures).' with figures in this window');
        $this->line('    of those, reported at CREATIVE grain : '.count($native));
        $this->line('    of those, summed from their ADS      : '.count($fromAds).'  ← the rows a creative-grain-only reader cannot see');

        $this->line('');
        $this->line('THE HEADLINE STRIP  (CreativeMetrics::totalsFor)');
        $this->line($strip === null ? '    null — «nothing reported»' : '    spend '.$this->money($strip, 'spend').'   answered: '.$this->answered($strip));

        $this->line('');
        $this->line('THE CARDS, POOLED  (aggregate over every card\'s own figures)');
        $this->line($cards === null ? '    null' : '    spend '.$this->money($cards, 'spend').'   answered: '.$this->answered($cards));

        $this->line('');
        $this->line('WHAT A CREATIVE-GRAIN-ONLY STRIP WOULD HAVE STATED  (the behaviour before this fix)');
        $this->line($nativeOnly === null
            ? '    null — it would have reported NOTHING for this library'
            : '    spend '.$this->money($nativeOnly, 'spend').'   answered: '.$this->answered($nativeOnly));

        /*
         * The gap, in money, and only where both figures are statable.
         *
         * A withheld total cannot be subtracted from a converted one — that is the money contract's
         * whole point — so the difference is reported only when the two are comparable, and named as
         * unstatable otherwise rather than printed as a number nobody can stand behind.
         */
        $now = $strip === null ? null : ($strip['spend'] ?? null);
        $before = $nativeOnly === null ? null : ($nativeOnly['spend'] ?? null);

        $this->line('');

        if (is_numeric($now) && is_numeric($before)) {
            $short = (float) $now - (float) $before;
            $this->line(sprintf(
                '  THE STRIP WAS SHORT BY %s — %s%% of what the library actually spent.',
                number_format($short, 2),
                (float) $now === 0.0 ? '0' : number_format($short / (float) $now * 100, 1),
            ));
        } elseif (is_numeric($now) && $before === null) {
            $this->line('  THE STRIP STATED NOTHING AT ALL, over a library whose cards state '
                .number_format((float) $now, 2).'.');
        } else {
            $this->line('  The two are not both statable in one currency, so no difference is claimed.');
        }

        if (count($fromAds) > 0) {
            $this->line('  '.count($fromAds).' creative(s) carry figures ONLY at the ad grain. A strip that read '
                .'`creative_daily_metrics` alone could not see any of them.');
        }

        /*
         * The gap in METRICS, not only in money — and on production it was the larger half.
         *
         * The first real reading came back «SHORT BY 0.00» over a library where the old strip could
         * state sixteen figures and the new one states thirty-five. The money genuinely was not short
         * in that window: the ad-grain rows for those creatives carry the RESULT columns and no spend.
         * What was short was the ANSWER — `leads`, `installs`, `sign_ups`, `app_opens`, `page_views`,
         * `reach`, `purchases`, `add_to_cart`, `checkout` and the figures derived from them were absent
         * from the headline strip entirely.
         *
         * That is «the other KPIs disappear» exactly, and it was visible only by diffing two long
         * printed lists by eye. A diagnostic that makes its reader do that has buried its own finding,
         * so the difference is named.
         */
        if ($nativeOnly !== null && $strip !== null) {
            $gained = array_values(array_diff($this->answeredKeys($strip), $this->answeredKeys($nativeOnly)));

            if ($gained !== []) {
                $this->line('  THE STRIP COULD NOT STATE '.count($gained).' FIGURE(S) IT NOW STATES — '
                    .implode(', ', $gained).'.');
            }
        }

        if ($strip !== null && $cards !== null) {
            $missing = array_values(array_diff($this->answeredKeys($cards), $this->answeredKeys($strip)));

            if ($missing !== []) {
                $this->divergences[] = 'The strip cannot answer, for this whole library, figures the cards '
                    .'answer: '.implode(', ', $missing).'.';
            }
        }

        if ($strip === null && $cards !== null) {
            $this->divergences[] = 'The strip states nothing for a library whose cards state figures.';
        }

        $this->line('');

        if ($this->divergences === []) {
            $this->info('RECONCILED — the strip and the cards answer the same set for this library.');

            return self::SUCCESS;
        }

        $this->error('DIVERGENCES — '.count($this->divergences).':');
        foreach ($this->divergences as $i => $divergence) {
            $this->line('  '.($i + 1).'. '.$divergence);
        }

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
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
