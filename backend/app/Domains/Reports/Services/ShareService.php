<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
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
        return rtrim((string) config('brand.frontend_url'), '/').self::pathFor($rawToken);
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

    /** Removes figures the share hides so the client payload never contains them. */
    public function sanitize(array $data, ReportShare $share): array
    {
        $stripMoney = function (array &$row) use ($share): void {
            if ($share->hide_spend) {
                foreach (['spend', 'cpa', 'cpc', 'cpm'] as $k) {
                    if (array_key_exists($k, $row)) {
                        $row[$k] = null;
                    }
                }
            }
            if ($share->hide_revenue) {
                foreach (['revenue', 'roas'] as $k) {
                    if (array_key_exists($k, $row)) {
                        $row[$k] = null;
                    }
                }
            }
        };

        if (isset($data['kpis']) && is_array($data['kpis'])) {
            $stripMoney($data['kpis']);
        }
        foreach (['platforms', 'campaigns', 'top_creatives', 'timeseries', 'budget'] as $section) {
            if (! empty($data[$section]) && is_array($data[$section])) {
                foreach ($data[$section] as &$row) {
                    if (is_array($row)) {
                        $stripMoney($row);
                        if ($share->hide_campaign_names && array_key_exists('campaign_name', $row)) {
                            $row['campaign_name'] = 'حملة';
                        }
                    }
                }
                unset($row);
            }
        }
        if ($share->hide_spend || $share->hide_revenue) {
            unset($data['summary']); // summary embeds spend/revenue figures
        }

        return $data;
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

        $money = array_merge(
            $share->hide_spend ? ['spend', 'cpa', 'cpc', 'cpm', 'cpl', 'cpi', 'cpe'] : [],
            $share->hide_revenue ? ['revenue', 'roas', 'aov'] : [],
        );

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

        if (isset($payload['totals']) && is_array($payload['totals'])) {
            $payload['totals'] = $strip($payload['totals']);
            $payload['deltas'] = $strip((array) ($payload['deltas'] ?? []));
        }

        foreach (['timeseries', 'platforms', 'campaigns'] as $section) {
            if (! empty($payload[$section]) && is_array($payload[$section])) {
                $payload[$section] = array_map(
                    fn ($row) => is_array($row) ? $strip($row) : $row,
                    $payload[$section],
                );
            }
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

        return $payload;
    }
}
