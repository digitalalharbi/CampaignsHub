<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * INTEG-RUNTIME §7 — asking the provider a question, and storing nothing.
 *
 * ## What this is for, and what `integrations:diagnose` cannot do
 *
 * The diagnosis reads what is already recorded. For the live Snapchat account it answered precisely:
 * the platform returned **0 rows**, every half hour, for the window 2026-08-11 → 2026-08-18. §7 says
 * a provider that really returned 0 is `NO_DATA` and not an error — but «really» is the whole
 * question. Zero rows for seven days across 89 discovered campaigns has two very different readings:
 *
 *   the account genuinely had no delivery in that window       → NO_DATA. Nothing to fix.
 *   the request cannot return rows for this account            → a defect, and a silent one.
 *
 * Nothing already stored separates them, because both look identical from the record. So this asks
 * the platform directly, over a window the caller chooses, and prints what comes back.
 *
 * ## Read-only, and read-only in a stronger sense than the diagnosis
 *
 * `integrations:diagnose` touches no network. This one does — it is the point — but it writes
 * NOTHING: no `MetricSyncRun`, no `DailyMetric`, no retained payload, no checkpoint on the account.
 * It uses the connection that already exists, so there is no re-authorisation and no token is
 * replaced. A probe that left rows behind would change the very state somebody is trying to read.
 */
final class ProbeInsightsCommand extends Command
{
    protected $signature = 'integrations:probe
        {account : The external account — ours, or the provider\'s own id}
        {--from= : Window start, YYYY-MM-DD (default: 30 days back)}
        {--to= : Window end, YYYY-MM-DD (default: yesterday)}
        {--rows=3 : How many returned rows to print}
        {--structure : Ask for the CAMPAIGN structure instead of insights — identity, counts and date range}
        {--media : FETCH the first page of creative assets and report status, content type and decoded size}';

    protected $description = 'Read-only: ask the provider for insights over a window and print what came back. Stores nothing.';

    public function handle(AdvertisingConnectorRegistry $registry): int
    {
        $reference = (string) $this->argument('account');

        /*
         * The signature invites either id — «ours, or the provider's own» — and comparing a provider
         * id against our uuid column made Postgres refuse the whole query:
         *
         *     SQLSTATE[22P02]: invalid input syntax for type uuid: "act_374140991630974"
         *
         * So the one form the help text puts first for a human («act_…», the id they can read off
         * the provider's own screen) was the form that crashed. `id` is only compared when the
         * reference is shaped like a uuid; otherwise the provider's id is the only column asked.
         */
        $account = ExternalAccount::withoutGlobalScopes()
            ->where(function ($q) use ($reference): void {
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) === 1) {
                    $q->where('id', $reference)->orWhere('external_id', $reference);

                    return;
                }

                $q->where('external_id', $reference);
            })
            ->where('account_type', 'ad_account')
            ->first();

        if ($account === null) {
            $this->error("No ad account matches '{$reference}'.");

            return self::FAILURE;
        }

        $from = $this->date('from', now()->subDays(30)->toDateString());
        $to = $this->date('to', now()->subDay()->toDateString());

        $connector = $registry->get($account->provider);

        if ($connector === null) {
            $this->error("No connector is registered for provider '{$account->provider}'.");

            return self::FAILURE;
        }

        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                $this->error('That account has no provider connection to ask through.');

                return self::FAILURE;
            }

            $connector = $connector->withConnection($connection);
        }

        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            $this->error($connector->label().' has no credentials on this install; nothing was asked.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  '.($this->option('structure') ? 'STRUCTURE' : 'INSIGHTS').' PROBE — nothing is stored by this command.');
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  %s  [%s]  provider id %s', $account->name ?: '(unnamed)', $account->provider, $account->external_id));
        $this->line(sprintf(
            '  account status %s   timezone %s   currency %s',
            $account->status ?? 'NOT CAPTURED',
            $account->timezone ?? 'NOT CAPTURED',
            $account->currency ?? 'NOT CAPTURED',
        ));

        /*
         * INTEG-ACCOUNT-CHOICE-001 — «does this ad account hold the business's campaigns?»
         *
         * The insights half of this command answers «what did it spend in a window», which is the
         * wrong question when choosing WHICH account to bind: an account can be silent for thirty
         * days and still hold four years of history, and an empty account and a quiet one look
         * identical through a window.
         *
         * The owner met exactly that. `act_1500383245036671` was bound to Project 1 and returned
         * nothing, while three other discovered Meta accounts were never asked — and the stored
         * diagnosis could not help, because an account that has never been bound has never been
         * synced, so the database holds no answer about it at all. Only the provider does.
         *
         * Read-only, like everything here: the campaigns come back, are counted, and are thrown away.
         * Nothing is imported and no binding is created — choosing an account is the owner's decision
         * and this exists to inform it, not to make it.
         */
        if ($this->option('media')) {
            return $this->reportMedia($account);
        }

        if ($this->option('structure')) {
            return $this->reportStructure($connector, $account);
        }

        $this->line(sprintf('  window %s → %s   account timezone %s', $from, $to, $account->timezone ?? 'NOT CAPTURED'));

        try {
            $result = $connector->syncInsights($account->external_id, $from, $to);
        } catch (Throwable $e) {
            $this->line('');
            $this->error('  The provider call threw: '.$e->getMessage());
            // The receipt is printed for a refusal too — it is the case that needs it most.
            $this->reportCalls($connector);

            return self::SUCCESS;
        }

        if (! $result->success) {
            $this->line('');
            $this->error('  The provider refused: '.($result->message ?? 'no message given'));
            $this->reportCalls($connector);

            return self::SUCCESS;
        }

        $rows = $result->records;

        // The bodies the connector was handed, drained so nothing carries into a later call.
        $bodies = $connector instanceof ApiAdvertisingConnector ? $connector->takeRawResponses() : [];
        $rawRows = $connector instanceof ApiAdvertisingConnector ? $connector->takeRawInsightRows() : count($rows);

        $known = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->pluck('external_id')
            ->flip();

        $mapped = 0;
        foreach ($rows as $row) {
            if ($known->has((string) ($row['campaign_id'] ?? ''))) {
                $mapped++;
            }
        }

        $this->reportCalls($connector);

        $this->line('');
        $this->line(sprintf('  provider_raw_rows    %d', $rawRows));
        $this->line(sprintf('  parsed_rows          %d', count($rows)));
        $this->line(sprintf('  mapped_campaign_rows %d   (of %d campaigns discovered)', $mapped, $known->count()));
        $this->line(sprintf('  responses received   %d', count($bodies)));

        foreach (array_slice($rows, 0, max(0, (int) $this->option('rows'))) as $index => $row) {
            $this->line(sprintf('  row[%d] %s', $index, json_encode($row, JSON_UNESCAPED_UNICODE) ?: ''));
        }

        if ($rows === []) {
            $this->line('');
            $this->line('  The provider returned no rows. Its own last body, truncated:');
            foreach (array_slice($bodies, -2) as $body) {
                $this->line('  '.mb_substr(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 1500));
            }
        }

        return self::SUCCESS;
    }

    /**
     * What was ASKED, and what the wire said — the half of «they returned 0» nobody wrote down.
     *
     * An empty body and a 200 are indistinguishable in the retained payload. The URL carries the
     * window, the breakdown, the granularity and the field list; the status and the platform's own
     * request id are what let somebody look the call up on the platform's side. No secret is in any
     * of it — every platform here authenticates in a header.
     */
    /**
     * AD-MEDIA-RECOVERY-001 — «a drawable URL exists» is not acceptance. This fetches it.
     *
     * The first-page census in `integrations:diagnose` reports what the PRESENTER would hand the
     * browser: on the live estate, 21 of 24 cards. The owner still sees blanks, so the remaining
     * candidates are all downstream of the payload — the request is refused, the bytes are not an
     * image, or the asset has gone stale — and none of them can be settled by reading a column.
     *
     * So this asks the same question a browser asks: GET the asset, and report the status, the
     * content type and the DECODED dimensions. `getimagesizefromstring` reads the real header, so a
     * 200 carrying an HTML error page reports «not an image» rather than «fine»; that is the failure
     * shape a CDN produces when a signature has expired, and it is invisible to a status check.
     *
     * The URL is never printed. It carries the signature that makes it work, and this output is read
     * in a CI log — the host and the path's last segment are enough to recognise an asset.
     */
    private function reportMedia(ExternalAccount $account): int
    {
        /*
         * Scoped by PROVIDER, not only by project — because two accounts can share one.
         *
         * The first cut of this filtered on «the project this binding points at» and nothing else.
         * Project 1 holds both the Meta account and the Snapchat one, so probing either returned the
         * SAME twelve rows: identical names, identical byte counts, identical object ids, in
         * identical order. It read like a finding about both providers and was one query answering
         * one question twice. `external_creatives` carries no account column, so the provider is
         * what separates them, inside the project the account is actually bound to.
         */
        $creatives = ExternalCreative::withoutGlobalScopes()
            ->where('provider', (string) $account->provider)
            ->where('project_id', function ($q) use ($account): void {
                $q->select('project_id')
                    ->from('project_integration_bindings')
                    ->where('external_account_id', $account->getKey())
                    ->where('is_active', true)
                    ->limit(1);
            })
            ->orderByRaw('last_active_at DESC NULLS LAST')
            ->orderByRaw('last_synced_at DESC NULLS LAST')
            ->orderBy('id')
            ->limit((int) max(1, (int) $this->option('rows')))
            ->get();

        $this->line('');
        $this->line(sprintf(
            '  %s — fetching the still %d first-page card(s) would draw',
            (string) $account->provider,
            $creatives->count(),
        ));
        $this->line('    state      = what the presenter says about the asset');
        $this->line('    card       = what the CARD does with it (adPreview.ts)');

        $ok = 0;
        $bad = 0;
        $blank = 0;

        foreach ($creatives as $creative) {
            $preview = app(CreativePresenter::class)->preview($creative);
            [$card, $url] = $this->cardStill($preview);

            $label = mb_substr((string) ($creative->name ?: '(unnamed)'), 0, 26);
            $state = (string) $preview['state'];

            if (! is_string($url) || $url === '') {
                /*
                 * «No still» is not «blank», and the first draft of this said it was.
                 *
                 * It printed «BLANK — available, yet the card selects no still» for every available
                 * row with no poster, and the live Snapchat account has three: two videos whose
                 * platform sent no cover, and a collection with no hero. None of them is a blank
                 * card. The library grid MOUNTS A PLAYER for a video with no poster — that is what
                 * `VideoPoster` is for — and every other surface draws `absenceLabel`, which has a
                 * written sentence for each of those shapes.
                 *
                 * An instrument that cries wolf on the healthy rows is worth less than no instrument,
                 * because the reader stops believing the row that matters. The alarming class here is
                 * UNUSABLE — fetched, and undrawable — which is where Meta's six actually sat.
                 */
                $film = $card === 'video/poster' && is_string($preview['video_url'] ?? null);

                $said = match (true) {
                    $state !== 'available' => 'nothing to fetch',
                    $card === 'catalog' => 'no single asset by design — the card says so',
                    $film => 'no cover — the surface plays the film instead',
                    default => 'no still — the card states why',
                };

                $blank += $state === 'available' && $card !== 'catalog' && ! $film ? 1 : 0;

                $this->line(sprintf('    · %-26s %-12s %-16s %s', $label, $state, $card, $said));

                continue;
            }

            $where = parse_url($url, PHP_URL_HOST).'/…/'.basename((string) parse_url($url, PHP_URL_PATH));

            try {
                $response = Http::withOptions(['allow_redirects' => true])->timeout(20)->get($url);
            } catch (Throwable $e) {
                $bad++;
                $this->line(sprintf('    · %-26s %-12s %-16s REQUEST FAILED %s — %s', $label, $state, $card, $where, $e->getMessage()));

                continue;
            }

            $type = (string) $response->header('Content-Type');
            $body = $response->body();
            $size = @getimagesizefromstring($body);

            /*
             * The still a card draws is always an IMAGE — `posterSource` never hands back a video
             * file. So «content type begins with video/» is no longer an acceptable answer here: it
             * would mean the card is about to put an mp4 in an `<img>`, which draws nothing.
             */
            $decoded = is_array($size) ? sprintf('%dx%d', $size[0], $size[1]) : 'did not decode';
            $usable = $response->successful() && str_starts_with($type, 'image/') && is_array($size);

            $usable ? $ok++ : $bad++;

            $this->line(sprintf(
                '    · %-26s %-12s %-16s %d  %-16s %9s bytes  %-14s %-8s %s',
                $label,
                $state,
                $card,
                $response->status(),
                mb_substr($type, 0, 16),
                number_format(strlen($body)),
                $decoded,
                $usable ? 'USABLE' : 'UNUSABLE',
                $where,
            ));
        }

        $this->line('');
        $this->line(sprintf('  usable stills    : %d', $ok));
        $this->line(sprintf('  unusable         : %d   (a 200 carrying an HTML error page counts here)', $bad));
        $this->line(sprintf('  no still         : %d   (available, not a film, not a catalog — the card draws its sentence)', $blank));

        return self::SUCCESS;
    }

    /**
     * The still the CARD would draw — `readPreview` then `posterSource`, in PHP.
     *
     * The probe used to fetch `image_url ?? thumbnail_url ?? video_url`, which is not a question any
     * surface asks. It made the probe generous in exactly the place the owner is being failed: a
     * creative whose kind is `image` while only `video_url` arrived has no still, so the card draws
     * an absence — and the old probe fetched the mp4, found 200 and `video/mp4`, and called it
     * usable. «Usable» then meant «some byte stream exists somewhere on this row», and the card the
     * owner is looking at was blank.
     *
     * Mirrors `frontend/src/features/content/adPreview.ts`. `MediaProbeMatchesTheCardTest` walks the
     * shapes and fails if the two ever disagree.
     *
     * @param  array<string, mixed>  $preview
     * @return array{0: string, 1: string|null}
     */
    private function cardStill(array $preview): array
    {
        $image = is_string($preview['image_url'] ?? null) ? $preview['image_url'] : null;
        $thumb = is_string($preview['thumbnail_url'] ?? null) ? $preview['thumbnail_url'] : null;
        $video = is_string($preview['video_url'] ?? null) ? $preview['video_url'] : null;
        $kind = (string) ($preview['kind'] ?? 'image');

        /*
         * Every non-available state draws the SENTENCE, not a still — `readPreview` returns `none`
         * and `posterSource` returns null. `expired` still carries a `thumbnail_url` in the payload
         * and the card deliberately ignores it: a stale poster over a dead asset is the fabricated
         * preview this module exists to prevent.
         */
        if ((string) $preview['state'] !== 'available') {
            return [(string) $preview['state'], null];
        }

        if ($kind === 'catalog') {
            return ['catalog', null];
        }

        if ($kind === 'collection') {
            return ['collection', $image ?? $thumb];
        }

        if ($kind === 'video' && $video !== null) {
            /* The poster, never the film: `AdPoster` renders an `<img>` and nothing else. */
            return ['video/poster', $thumb ?? $image];
        }

        return ['image', $image ?? $thumb];
    }

    /**
     * The campaign structure, counted and dated — and then discarded.
     *
     * What a person choosing an account actually needs: how many campaigns exist, how many ever ran,
     * and how far back the history goes. A name cannot answer it — «razzahavenu», «RazahAvanue» and
     * «RazzahAvenu» are three different accounts on this estate and only the provider knows which of
     * them the business used.
     *
     * Dates are read from the campaign's own `raw` body rather than from anything we mapped, so this
     * reports what the platform said rather than what our importer would have made of it.
     */
    private function reportStructure(object $connector, ExternalAccount $account): int
    {
        try {
            $result = $connector->syncCampaigns($account->external_id);
        } catch (Throwable $e) {
            $this->line('');
            $this->error('  The provider call threw: '.$e->getMessage());
            $this->reportCalls($connector);

            return self::SUCCESS;
        }

        if (! $result->success) {
            $this->line('');
            $this->error('  The provider refused: '.($result->message ?? 'no message given'));
            $this->reportCalls($connector);

            return self::SUCCESS;
        }

        $campaigns = $result->records;

        $this->line('');
        $this->line(sprintf('  campaigns returned : %d', count($campaigns)));

        if ($campaigns === []) {
            $this->line('  The provider answered and listed no campaigns. This account is empty —');
            $this->line('  which is a fact about the account, not a failure of the request.');
            $this->reportCalls($connector);

            return self::SUCCESS;
        }

        $statuses = [];
        $starts = [];
        $stops = [];

        foreach ($campaigns as $campaign) {
            $statuses[(string) ($campaign['status'] ?? 'unknown')] = ($statuses[(string) ($campaign['status'] ?? 'unknown')] ?? 0) + 1;

            $raw = (array) ($campaign['raw'] ?? []);

            foreach (['start_time', 'created_time'] as $key) {
                if (isset($raw[$key]) && is_string($raw[$key])) {
                    $starts[] = substr($raw[$key], 0, 10);
                    break;
                }
            }

            foreach (['stop_time', 'updated_time'] as $key) {
                if (isset($raw[$key]) && is_string($raw[$key])) {
                    $stops[] = substr($raw[$key], 0, 10);
                    break;
                }
            }
        }

        ksort($statuses);

        foreach ($statuses as $status => $count) {
            $this->line(sprintf('    %-24s %d', $status, $count));
        }

        sort($starts);
        sort($stops);

        $this->line(sprintf(
            '  earliest start     : %s',
            $starts === [] ? 'the platform stated none' : $starts[0],
        ));
        $this->line(sprintf(
            '  latest end/updated : %s',
            $stops === [] ? 'the platform stated none' : $stops[count($stops) - 1],
        ));

        /* A few names, so a human can recognise the business without reading five hundred rows. */
        $this->line('  first few campaigns:');

        foreach (array_slice($campaigns, 0, max(1, (int) $this->option('rows'))) as $campaign) {
            $this->line(sprintf(
                '    · %s  [%s]',
                (string) ($campaign['name'] ?? '(unnamed)'),
                (string) ($campaign['status'] ?? 'unknown'),
            ));
        }

        $this->reportCalls($connector);

        return self::SUCCESS;
    }

    private function reportCalls(object $connector): void
    {
        if (! $connector instanceof ApiAdvertisingConnector) {
            return;
        }

        $calls = $connector->takeCallLog();

        $this->line('');
        $this->line(sprintf('  calls made: %d', count($calls)));

        foreach ($calls as $index => $call) {
            $this->line(sprintf(
                '  [%d] HTTP %d  request_id=%s',
                $index,
                $call['status'],
                $call['request_id'] ?? '—',
            ));
            $this->line('      '.$call['url']);
            // The response SHAPE, not its contents: the top-level keys say whether the platform
            // answered with a payload, an error envelope or an empty success.
            $this->line('      response keys: '.(implode(', ', $call['keys']) ?: '(none)'));
        }
    }

    private function date(string $option, string $fallback): string
    {
        $value = $this->option($option);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
