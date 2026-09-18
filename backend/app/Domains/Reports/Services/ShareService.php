<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Analytics\ObjectiveReportAnalytics;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Reports\Support\HiddenMoney;
use App\Support\Frontend;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues and validates secure client report links. The raw token is returned ONCE at creation and
 * never stored (only its sha256 hash is), and the sanitizer strips hidden figures before a client
 * ever sees the payload. Every access is logged.
 */
final class ShareService
{
    /**
     * SHARE-SHORT-001 — how long a client link's secret is, and why this number.
     *
     * The brief asks for a SHORT link, and the old one was not: a 48-character token behind
     * `/reports/share/` produced a URL nobody could read out, and one that WhatsApp and Outlook both
     * wrap across lines — which is how a client ends up pasting half a link and reporting that the
     * report is broken.
     *
     * It cannot simply be shortened, because the token IS the credential: anyone holding it opens the
     * report without signing in. 22 characters of `Str::random` (base62) is ~131 bits — more than an
     * AES-128 key, and far past anything the endpoint's rate limiter would let somebody search. So the
     * link becomes readable without becoming guessable.
     *
     * Existing 48-character links keep working untouched: lookup hashes whatever is presented, so the
     * length was never part of the contract.
     */
    private const TOKEN_LENGTH = 22;

    public function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** The short public path for a raw token. One construction, so every caller agrees. */
    public static function pathFor(string $rawToken): string
    {
        return "/r/{$rawToken}";
    }

    /**
     * The canonical, absolute link an operator copies and sends — `https://campaignshub.io/r/…`.
     *
     * Stated by the SERVER rather than assembled in the browser from `window.location.origin`, which
     * is what the reports page did. That worked on production and quietly produced a link nobody
     * outside could open anywhere else: an operator reviewing on staging, on a preview deployment or
     * on `localhost` copied a host only they could reach and sent it to a client. The failure is
     * silent on the sending side and total on the receiving one.
     *
     * The host comes from `brand.frontend_url`, the same setting the rest of the product's outbound
     * links use, so there is one place to change it and no second opinion.
     */
    public static function urlFor(string $rawToken): string
    {
        return Frontend::origin().self::pathFor($rawToken);
    }

    /** @return array{0: ReportShare, 1: string} the share + the raw token (show once) */
    public function create(Report $report, array $opts, ?int $userId): array
    {
        $raw = Str::random(self::TOKEN_LENGTH);
        $share = ReportShare::create([
            'tenant_id' => $report->tenant_id,
            'report_id' => $report->id,
            'token_hash' => $this->hashToken($raw),
            'password_hash' => ! empty($opts['password']) ? Hash::make($opts['password']) : null,
            'allow_download' => $opts['allow_download'] ?? true,
            'hide_spend' => $opts['hide_spend'] ?? false,
            'hide_revenue' => $opts['hide_revenue'] ?? false,
            'hide_campaign_names' => $opts['hide_campaign_names'] ?? false,
            'watermark' => $opts['watermark'] ?? false,
            /*
             * A link is live only when it was given a ceiling to stay inside (LIVEREP-001).
             *
             * `mode` used to be derived from the presence of a scope alone, which was fine while a
             * scope existed for one reason. It no longer is: a scope now also carries which creatives
             * a link may show, so choosing four creatives for a SNAPSHOT link would have silently
             * turned it live and started recomputing figures the operator had deliberately frozen.
             * The mode is therefore stated (§15.12), and the scope is only what bounds it.
             *
             * The invalid pair stays unwritable in the other direction too: `live` without a scope is
             * a link with no bound, so it is stored as a snapshot rather than as a promise
             * {@see ReportShare::isLive()} would then refuse to keep.
             */
            'mode' => $this->modeFor($opts),
            /*
             * The FORM — how much of the report this link is — independent of the mode above.
             *
             * Null means «whatever the report itself is», which is what every link created before
             * this existed means and what an operator who skipped the choice means.
             */
            'form' => in_array($opts['form'] ?? null, ['executive_summary', 'detailed'], true)
                ? $opts['form']
                : null,
            'settings' => $opts['settings'] ?? null,
            'scope' => $opts['scope'] ?? null,
            'expires_at' => $opts['expires_at'] ?? null,
            'created_by' => $userId,
            'is_demo' => (bool) ($report->is_demo ?? false),
        ]);

        return [$share, $raw];
    }

    /**
     * Live or snapshot — asked for, not inferred, with the old inference as the fallback.
     *
     * The mode used to be «a scope exists», which was fine while a scope existed for exactly one
     * reason. It no longer is: a scope now also carries which creatives a link may show, so choosing
     * four creatives for a frozen report would have silently started recomputing its figures.
     *
     * A caller that says nothing keeps the old behaviour, because every existing caller means what
     * it always meant. The controller says it explicitly, so the new choice cannot be made by
     * accident there.
     *
     * The invalid pair stays unwritable in the other direction too: `live` with no ceiling is a link
     * to everything, so it is stored as a snapshot rather than as a promise
     * {@see ReportShare::isLive()} would then refuse to keep.
     *
     * @param  array<string, mixed>  $opts
     */
    private function modeFor(array $opts): string
    {
        if (empty($opts['scope'])) {
            return 'snapshot';
        }

        $asked = match (true) {
            isset($opts['mode']) => $opts['mode'] === 'live',
            array_key_exists('live', $opts) => (bool) $opts['live'],
            default => true,     // said nothing, and has a scope — the pre-§15.12 reading.
        };

        return $asked ? 'live' : 'snapshot';
    }

    public function resolveActive(string $rawToken): ?ReportShare
    {
        $share = ReportShare::withoutGlobalScopes()->where('token_hash', $this->hashToken($rawToken))->first();

        return $share && $share->isActive() ? $share : null;
    }

    public function log(ReportShare $share, string $action, Request $request, ?string $detail = null): void
    {
        $share->logs()->create([
            'action' => $action,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    /**
     * The creative-bearing sections of a report payload, snapshot and live alike.
     *
     * CLIENT-REPORT-MONEY-REDACTION-001 — the enumeration below was one section long.
     *
     * `ReportCreativeMedia` walks `ads_roster`, `worst_creatives`, `top_creatives` and every `ads`
     * list at any depth — it named `ads_groups[].ads` by hand until `ads_platform_groups` arrived
     * and was not named, and it now looks for the key instead of for the section. The snapshot
     * sanitizer here named `top_creatives` and the live sanitizer named none of them, so an operator
     * who hid spend found it again on the ads gallery — the most-read part of a client report — and
     * on the roster beneath it. This is the failure the live
     * sanitizer's own comment predicted: «a section added to the payload and not to this list is a
     * section that ignores the link's hide flags».
     *
     * @var list<string>
     */
    private const CREATIVE_SECTIONS = ['ads', 'ads_roster', 'ads_weakest', 'worst_creatives', 'top_creatives'];

    /** Removes figures the share hides so the client payload never contains them. */
    /**
     * The RENDER flags a share implies, applied to the report config the exporter reads.
     *
     * `sanitize()` above answers the same question for DATA — what this link may say. This answers
     * it for the page — how the file it produces must be drawn. They are separated because they act
     * on different things and fail differently: a data flag that goes missing publishes a figure,
     * and a render flag that goes missing publishes a document that LOOKS like one it is not.
     *
     * It exists because `watermark` was the one per-share flag that reached the screen and never the
     * file. `allow_download` was enforced, and spend, revenue and campaign names were all applied
     * through `sanitize()` — the watermark was drawn by `PublicReport`, announced to the client in
     * their own portal as «يحمل علامة مائية», and absent from the PDF. That is the artefact a
     * watermark is FOR: the copy that is downloaded, kept and forwarded.
     *
     * Merged onto the config rather than passed as an argument because `config` is already the
     * channel `ReportExporter::pdf()` reads its render decisions from (`pdf_type`), and it is applied
     * to a per-request replica — so the same stored report still exports clean for a link that asked
     * for no watermark.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function renderConfig(array $config, ReportShare $share): array
    {
        return array_merge($config, ['watermark' => (bool) $share->watermark]);
    }

    /**
     * The document a shared link's FILES are made from — SHARED-PDF-HIDE-FLAGS-001.
     *
     * Media resolved now (REPORT-CREATIVE-MEDIA-001), then this link's hide flags, then the creative
     * rows the link may show, already redacted by `SharedCreativeView`. The download controller and the
     * print route both call this, so the spreadsheet and the PDF of one link cannot say different things.
     *
     * @return array<string, mixed>
     */
    public function downloadDocument(Report $report, ReportShare $share): array
    {
        $data = $this->sanitize(app(ReportCreativeMedia::class)->refresh($report->data ?? []), $share);

        // §15.12 — the creative rows reach the file only if the link may show them.
        if ($share->creativeVisibility()->creatives) {
            $data['creatives'] = app(SharedCreativeView::class)->library($share, ['per_page' => 48])['creatives'];
        }

        return $data;
    }

    public function sanitize(array $data, ReportShare $share): array
    {
        /*
         * CLIENT-REPORT-MONEY-REDACTION-001 — both paths read ONE list, and its companions.
         *
         * This named four keys while `sanitizeLive()` named seven and
         * `CreativeVisibility::COST_METRICS` named six, two of which were in neither — so a snapshot
         * link hiding spend published `cpl`, which is spend divided by a lead count printed beside
         * it. The list now lives in one place; see the constant for what it covers and why.
         *
         * The `_original` companions matter more than the converted columns on production: FX-001
         * preserves an unconvertible amount there beside a null conversion, so nulling `spend` and
         * leaving `spend_original` shipped the withheld figure exactly.
         */
        $stripMoney = function (array &$row) use ($share): void {
            $keys = HiddenMoney::keys((bool) $share->hide_spend, (bool) $share->hide_revenue);

            foreach ($keys as $k) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = null;
                }
            }
        };

        /*
         * Both names for the scalar totals, in both paths — hardening, not an observed leak.
         *
         * The snapshot writes `kpis` (`ReportGenerator`) and the live payload writes `totals`
         * (`LiveReportService`), so each sanitizer covered its own and skipped the other's. Nothing
         * leaks today because neither payload carries the other's key. But that is exactly the shape
         * of the `ads` gap this row already fixed — a section present in a payload and absent from a
         * list — and the split into two names is what would make it silent. Covering both costs a
         * line and removes the class.
         */
        foreach (['kpis', 'totals'] as $scalars) {
            if (isset($data[$scalars]) && is_array($data[$scalars])) {
                $stripMoney($data[$scalars]);
            }
        }
        foreach (['platforms', 'campaigns', 'timeseries', 'budget', ...self::CREATIVE_SECTIONS] as $section) {
            if (! empty($data[$section]) && is_array($data[$section])) {
                foreach ($data[$section] as &$row) {
                    if (is_array($row)) {
                        $stripMoney($row);
                        /*
                         * The roster keeps its money under `metrics`, one rung below a row's own
                         * keys — see the note on the live path, which had the identical gap. Both
                         * sanitisers reach it, because a client holding a snapshot and a client
                         * holding a live link must not be told different things by one share.
                         */
                        if (! empty($row['metrics']) && is_array($row['metrics'])) {
                            $stripMoney($row['metrics']);
                        }
                        /* And a platform's movement, for the reason the live path states. */
                        if (! empty($row['movement']) && is_array($row['movement'])) {
                            $stripMoney($row['movement']);
                        }
                        if ($share->hide_campaign_names && array_key_exists('campaign_name', $row)) {
                            $row['campaign_name'] = 'حملة';
                        }
                    }
                }
                unset($row);
            }
        }

        /* REPORT-OBJECTIVE-ANALYTICS-001 — its figures are a list of keyed KPIs, so it is redacted by key. */
        if (is_array($data['objective_analytics'] ?? null)) {
            $data['objective_analytics'] = ObjectiveReportAnalytics::redact($data['objective_analytics'], array_merge(
                $share->hide_spend ? CreativeVisibility::COST_METRICS : [],
                $share->hide_revenue ? CreativeVisibility::REVENUE_METRICS : [],
            ));
        }

        /* The wrapper-shaped section that was on no list — see the live path's note. */
        /*
         * SHARED-PDF-HIDE-FLAGS-001 — the per-platform series the deck's platform charts draw from.
         *
         * Keyed by provider rather than a list, so the section loop above never reached it: a link
         * hiding spend still published every platform's daily spend under `platform_series`.
         */
        if (! empty($data['platform_series']) && is_array($data['platform_series'])) {
            foreach ($data['platform_series'] as &$series) {
                if (is_array($series)) {
                    foreach ($series as &$point) {
                        if (is_array($point)) {
                            $stripMoney($point);
                        }
                    }
                    unset($point);
                }
            }
            unset($series);
        }

        if (! empty($data['objective_performance']) && is_array($data['objective_performance'])) {
            if (! empty($data['objective_performance']['paths']) && is_array($data['objective_performance']['paths'])) {
                foreach ($data['objective_performance']['paths'] as &$path) {
                    if (is_array($path)) {
                        $stripMoney($path);
                    }
                }
                unset($path);
            }
            foreach (['direct', 'blended'] as $block) {
                if (! empty($data['objective_performance'][$block]) && is_array($data['objective_performance'][$block])) {
                    $stripMoney($data['objective_performance'][$block]);
                }
            }
        }

        /*
         * `ads_groups[].ads` is a rung DOWN, and a rung down is where this kind of gap lives.
         *
         * The loop above walks top-level lists; the grouped gallery nests its ads one level inside,
         * so every one of them sailed past both sanitizers. Nesting is handled here rather than by
         * making the walk recursive, for the same reason the section list is enumerated: a blind walk
         * would also rewrite keys nobody has thought about.
         */
        $stripGroup = function ($group) use ($stripMoney, $share) {
            if (! is_array($group)) {
                return $group;
            }
            if (! empty($group['ads']) && is_array($group['ads'])) {
                foreach ($group['ads'] as &$ad) {
                    if (is_array($ad)) {
                        $stripMoney($ad);
                        if ($share->hide_campaign_names && array_key_exists('campaign_name', $ad)) {
                            $ad['campaign_name'] = 'حملة';
                        }
                    }
                }
                unset($ad);
            }
            $stripMoney($group);

            return $group;
        };

        if (! empty($data['ads_groups']) && is_array($data['ads_groups'])) {
            $data['ads_groups'] = array_map($stripGroup, $data['ads_groups']);
        }

        /* Platform → objective → ad, through the same walk. See the live path's note for why. */
        if (! empty($data['ads_platform_groups']) && is_array($data['ads_platform_groups'])) {
            $data['ads_platform_groups'] = array_map(function ($platform) use ($stripGroup) {
                if (! is_array($platform) || empty($platform['groups']) || ! is_array($platform['groups'])) {
                    return $platform;
                }
                $platform['groups'] = array_map($stripGroup, $platform['groups']);

                return $platform;
            }, $data['ads_platform_groups']);
        }

        if ($share->hide_spend || $share->hide_revenue) {
            unset($data['summary']); // summary embeds spend/revenue figures
        }

        /*
         * A note that states a hidden figure in PROSE is not covered by column redaction.
         *
         * «صُرف 27,745.88 SAR من أصل 16,666.67 SAR» publishes the spend as surely as the column
         * does, and no amount of nulling table cells reaches it. Each detector declares what it
         * reveals (see {@see ReportObservations}); a note whose declaration intersects what this
         * link hides is dropped whole rather than reworded into something true but useless.
         *
         * Notes with no declaration are kept — every one of them is a rate, a count or a data-quality
         * statement — and a note carrying an unknown key is dropped, because the safe direction for
         * a redaction that has not been thought about is out.
         */
        $hidden = array_merge(
            $share->hide_spend ? ['spend'] : [],
            $share->hide_revenue ? ['revenue'] : [],
        );
        if ($hidden !== [] && ! empty($data['observations'])) {
            $data['observations'] = array_values(array_filter(
                $data['observations'],
                fn ($note) => array_intersect((array) ($note['reveals'] ?? []), $hidden) === [],
            ));
        }

        if ($share->hide_campaign_names && ! empty($data['observations'])) {
            $data['observations'] = array_values(array_filter(
                $data['observations'],
                // A note ABOUT one campaign is nothing once the campaign cannot be named.
                fn ($note) => ($note['scope']['type'] ?? '') !== 'campaign',
            ));
        }

        // SHARED-PDF-HIDE-FLAGS-001 — the one definition, at any depth, and the prose that states it.
        return HiddenMoney::redact($data, (bool) $share->hide_spend, (bool) $share->hide_revenue);
    }

    /**
     * The attribution payload, under the same hide flags as every other client surface.
     *
     * CLIENT-REPORT-MONEY-REDACTION-001 — this endpoint sanitized NOTHING.
     * `PublicReportController::attribution()` returned `AttributionTransparency::build(...)`
     * verbatim, and that payload carries revenue under its own names. The section is gated on
     * `sectionVisibility()->attribution`, which is a DIFFERENT flag from `hide_revenue`, so an
     * operator who turned the reconciliation on and hid revenue published revenue — measured at
     * 18,000 on exactly such a link before this existed.
     *
     * It was missed because the money rules were reached through `sanitize()` and `sanitizeLive()`,
     * and this route called neither — which is why the guard over it walks the registered routes
     * instead of a list of the ones we thought of.
     *
     * The orders, the difference and the ratio survive: they are the section's subject, they are not
     * money, and a spend-hiding link has no claim on them. `cost_per_order` and friends are caught
     * by the shared cost list, which this walks too.
     */
    public function sanitizeAttribution(array $payload, ReportShare $share): array
    {
        if (! $share->hide_spend && ! $share->hide_revenue) {
            return $payload;
        }

        $keys = array_merge(
            $share->hide_spend ? array_merge(CreativeVisibility::COST_METRICS, CreativeVisibility::MONEY_COMPANIONS['spend']) : [],
            $share->hide_revenue ? array_merge(
                CreativeVisibility::REVENUE_METRICS,
                CreativeVisibility::ATTRIBUTION_REVENUE_KEYS,
                CreativeVisibility::MONEY_COMPANIONS['revenue'],
            ) : [],
            $share->hide_spend && $share->hide_revenue ? CreativeVisibility::MONEY_CURRENCY_KEYS : [],
        );

        return $this->nullKeysDeeply($payload, array_values(array_unique($keys)));
    }

    /**
     * Null every listed key wherever it appears, at any depth.
     *
     * A recursive walk is right HERE and wrong for the report payloads: this one is a fixed analytical
     * shape built by one service, where the report payloads carry operator prose, slide configuration
     * and client-supplied names that a blind walk would reach. The report sanitizers enumerate their
     * sections for that reason; this one cannot, because the nesting is the analysis.
     *
     * @param  list<string>  $keys
     */
    private function nullKeysDeeply(array $payload, array $keys): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, $keys, true)) {
                $payload[$key] = null;

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->nullKeysDeeply($value, $keys);
            }
        }

        return $payload;
    }

    /**
     * The same hide-flags, applied to a LIVE payload (LIVEREP-001).
     *
     * A separate method rather than a reuse of `sanitize()` because the two payloads have different
     * shapes — the live one has `totals`/`deltas`/`funnel` where the snapshot has `kpis`/`summary` — and
     * a single function pretending to handle both would silently skip the sections it did not recognise.
     * Silently skipping is precisely how a hidden figure gets published.
     *
     * The flags themselves are identical, deliberately: an operator who ticked «hide spend» ticked it
     * about this client, not about one rendering path. A live link that showed what its snapshot
     * equivalent hides would be the same disclosure arriving by a newer route.
     */
    public function sanitizeLive(array $payload, ReportShare $share): array
    {
        if (! $share->hide_spend && ! $share->hide_revenue && ! $share->hide_campaign_names) {
            return $payload;
        }

        /* The same one list the snapshot path reads — see `sanitize()` and the constant itself. */
        $money = HiddenMoney::keys((bool) $share->hide_spend, (bool) $share->hide_revenue);

        $strip = function (array $row) use ($money, $share): array {
            foreach ($money as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = null;
                }
            }
            if ($share->hide_campaign_names && array_key_exists('campaign_name', $row)) {
                $row['campaign_name'] = 'حملة';
            }

            return $row;
        };

        /* Both names, for the reason given in `sanitize()`. `deltas` rides with the totals. */
        foreach (['totals', 'kpis'] as $scalars) {
            if (isset($payload[$scalars]) && is_array($payload[$scalars])) {
                $payload[$scalars] = $strip($payload[$scalars]);
            }
        }
        if (isset($payload['deltas']) && is_array($payload['deltas'])) {
            $payload['deltas'] = $strip($payload['deltas']);
        }

        /*
         * REPORT-DETAIL-PARITY-001 — `ad_sets` is sanitised with everything else, and so is `budget`.
         *
         * The budget block is built only when `hide_spend` is false, so its money is already the
         * money the link allows — but it is listed here anyway, because `hide_campaign_names` applies
         * to it too and a pacing table naming every campaign would undo the renaming done below. A
         * section is on this list or it is outside the contract; there is no third state.
         *
         * A section added to the payload and not to this list is a section that ignores the link's
         * hide flags: an operator who hid spend would find it again one rung down. The list is
         * enumerated rather than walked precisely so that adding a section is a decision somebody
         * makes here, and this is that decision.
         */
        foreach (['timeseries', 'platforms', 'campaigns', 'ad_sets', 'budget', ...self::CREATIVE_SECTIONS] as $section) {
            if (! empty($payload[$section]) && is_array($payload[$section])) {
                $payload[$section] = array_map(
                    function ($row) use ($strip) {
                        if (! is_array($row)) {
                            return $row;
                        }

                        /*
                         * A platform's `movement` is one rung down too, and its keys are the money's own names.
                         *
                         * The values are RATIOS — 0.26 for +26% — not amounts, so nothing here states a figure. But
                         * «hiding spend takes the ratio that would give it back» is already this file's rule, and a
                         * per-platform spend movement is that ratio one axis over: a reader with last month's link
                         * and this month's growth has the amount. It also keeps the money-leak sweep meaningful,
                         * which cannot tell a ratio under the key `spend` from a figure under it and should not have
                         * to guess.
                         */
                        if (! empty($row['movement']) && is_array($row['movement'])) {
                            $row['movement'] = $strip($row['movement']);
                        }

                        return $strip($row);
                    },
                    $payload[$section],
                );
            }
        }

        /*
         * The roster keeps its money one rung DOWN, under `metrics`, and `$strip` only reaches a
         * row's own keys.
         *
         * `ads_roster` has been on the list above since the list existed, which is exactly why this
         * survived: the section was enumerated, the decision had been made, and the sanitiser still
         * returned every figure untouched because the shape it strips is not the shape the roster
         * has. Measured on a `hide_spend` link: sixty rows carrying `metrics.spend`, `metrics.cpc`,
         * `metrics.cpm` and thirty carrying `metrics.cost_per_view` — the section the owner named
         * FIRST when this contract was written.
         *
         * One named level, not a recursive walk: the enumeration above exists so that reaching a new
         * shape is a decision somebody makes here, and a blind descent would also reach the preview
         * envelope and the operator prose that sit beside these figures.
         */
        foreach (self::CREATIVE_SECTIONS as $section) {
            if (! empty($payload[$section]) && is_array($payload[$section])) {
                $payload[$section] = array_map(function ($row) use ($strip) {
                    if (is_array($row) && ! empty($row['metrics']) && is_array($row['metrics'])) {
                        $row['metrics'] = $strip($row['metrics']);
                    }

                    return $row;
                }, $payload[$section]);
            }
        }

        /*
         * `objective_performance` was on NO list at all — the failure its own neighbours warn about.
         *
         * It was added to the live payload after this method was written, and a section added to the
         * payload and not to the list is a section that ignores the link's hide flags. Measured on a
         * `hide_spend` link: `paths[].spend`, `direct.spend` and `blended.spend` all present. Its
         * shape is a WRAPPER rather than a list of rows — `paths`, `direct`, `blended` — so it cannot
         * ride the loop above, which is why it was easy to miss and why it is stated explicitly here.
         */
        if (! empty($payload['objective_performance']) && is_array($payload['objective_performance'])) {
            $objective = $payload['objective_performance'];

            if (! empty($objective['paths']) && is_array($objective['paths'])) {
                $objective['paths'] = array_map(fn ($p) => is_array($p) ? $strip($p) : $p, $objective['paths']);
            }
            foreach (['direct', 'blended'] as $block) {
                if (! empty($objective[$block]) && is_array($objective[$block])) {
                    $objective[$block] = $strip($objective[$block]);
                }
            }

            $payload['objective_performance'] = $objective;
        }

        if (is_array($payload['objective_analytics'] ?? null)) {
            $payload['objective_analytics'] = ObjectiveReportAnalytics::redact($payload['objective_analytics'], $money);
        }

        /* The same rung down the snapshot path walks — see the note there. */
        $stripGroup = function ($group) use ($strip) {
            if (! is_array($group)) {
                return $group;
            }
            if (! empty($group['ads']) && is_array($group['ads'])) {
                $group['ads'] = array_map(fn ($ad) => is_array($ad) ? $strip($ad) : $ad, $group['ads']);
            }

            return $strip($group);
        };

        if (! empty($payload['ads_groups']) && is_array($payload['ads_groups'])) {
            $payload['ads_groups'] = array_map($stripGroup, $payload['ads_groups']);
        }

        /*
         * The per-platform gallery nests the SAME group one rung further down, and that is the whole
         * reason the walk above became a named function.
         *
         * `ads_platform_groups[].groups[].ads` is platform → objective → ad. Writing a third copy of
         * the group walk for it is how the last gap happened: the snapshot sanitizer named four
         * sections, the live one named seven, and an ad nested where neither looked shipped its
         * spend to a link that hides spend. Reusing the walk is not the blind recursion this file
         * rejected — the shape is still enumerated, it is simply enumerated once.
         */
        if (! empty($payload['ads_platform_groups']) && is_array($payload['ads_platform_groups'])) {
            $payload['ads_platform_groups'] = array_map(function ($platform) use ($stripGroup) {
                if (! is_array($platform) || empty($platform['groups']) || ! is_array($platform['groups'])) {
                    return $platform;
                }
                $platform['groups'] = array_map($stripGroup, $platform['groups']);

                return $platform;
            }, $payload['ads_platform_groups']);
        }

        /*
         * The campaign PICKER is renamed too, not just the rows.
         *
         * Hiding names in the table while the filter above it still lists them by name hides nothing —
         * the reader simply looks up instead of down. This is the kind of gap a per-section sanitizer
         * leaves behind, and the reason this method enumerates its sections rather than walking blind.
         */
        if ($share->hide_campaign_names && ! empty($payload['available']['campaigns'])) {
            $payload['available']['campaigns'] = array_values(array_map(
                fn (array $c, int $i): array => ['id' => $c['id'], 'name' => 'حملة '.($i + 1)],
                $payload['available']['campaigns'],
                array_keys($payload['available']['campaigns']),
            ));
        }

        // SHARED-PDF-HIDE-FLAGS-001 — the one definition, at any depth, and the prose that states it.
        return HiddenMoney::redact($payload, (bool) $share->hide_spend, (bool) $share->hide_revenue);
    }
}
