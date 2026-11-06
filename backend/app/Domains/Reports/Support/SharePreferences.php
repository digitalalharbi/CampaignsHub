<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;

/**
 * REPORT-DUPLICATE-001 (Owner correction 2026-11-04 §8.3) — what a copy remembers of its original's link: preferences, and only those.
 *
 * A duplicate has no link by design, so the hide switches an operator had set on the original's link were lost with it, and
 * the link made for the copy afterwards started from the share defaults: less restricted than the one the client already had.
 * This is the answer that keeps the first rule intact. The switches travel with the copy as a note in its `config`; the share
 * dialog opens filled from them; and a link exists only when someone who may share presses «إنشاء رابط آمن».
 *
 * What is a preference: the download and watermark switches, the three hide switches, the generic name kinds, the form, live
 * or fixed, the section switches and the creative visibility switches. What is NOT, and never travels: the token, the
 * password (only the fact that one was asked for), the expiry, the pause, the grants, the access history, and every id of the
 * link's scope, because the copy's own sources decide what its link may cover.
 *
 * Read through {@see normalise()} wherever it is read, so a hand-written `config` cannot put anything else under the key.
 */
final class SharePreferences
{
    public const KEY = 'share_preferences';

    private const SWITCHES = ['allow_download', 'hide_spend', 'hide_revenue', 'hide_campaign_names', 'watermark'];

    /**
     * The preferences a copy of this report starts with: its newest link that was not revoked for good, else the
     * preferences it was itself copied with, else none.
     *
     * @return array<string, mixed>|null
     */
    public static function forCopyOf(Report $source): ?array
    {
        $link = ReportShare::query()->where('report_id', $source->getKey())->whereNull('revoked_at')->latest('created_at')->latest('id')->first();

        return $link !== null ? self::fromShare($link) : self::normalise(((array) ($source->config ?? []))[self::KEY] ?? null);
    }

    /** @return array<string, mixed> */
    public static function fromShare(ReportShare $share): array
    {
        $settings = (array) ($share->settings ?? []);

        return (array) self::normalise([
            'allow_download' => (bool) $share->allow_download,
            'hide_spend' => (bool) $share->hide_spend,
            'hide_revenue' => (bool) $share->hide_revenue,
            'hide_campaign_names' => (bool) $share->hide_campaign_names,
            'watermark' => (bool) $share->watermark,
            'mode' => $share->isLive() ? 'live' : 'snapshot',
            'form' => $share->form,
            'generic_names' => $settings['generic_names'] ?? [],
            'sections' => $settings['sections'] ?? [],
            'creatives' => $settings['creatives'] ?? [],
            'was_locked' => $share->password_hash !== null,
        ]);
    }

    /**
     * The known keys, each cut to its type; null when nothing usable is left.
     *
     * @return array<string, mixed>|null
     */
    public static function normalise(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        $out = [];
        foreach (self::SWITCHES as $key) {
            if (is_bool($raw[$key] ?? null)) {
                $out[$key] = $raw[$key];
            }
        }
        if ($out === []) {
            return null;
        }
        $bools = static fn (mixed $v): array => array_filter(is_array($v) ? $v : [], static fn ($flag, $key): bool => is_bool($flag) && is_string($key), ARRAY_FILTER_USE_BOTH);

        return $out + [
            'mode' => ($raw['mode'] ?? null) === 'live' ? 'live' : 'snapshot',
            'form' => in_array($raw['form'] ?? null, ['executive_summary', 'detailed'], true) ? $raw['form'] : null,
            'generic_names' => GenericNames::normalise($raw['generic_names'] ?? null),
            'sections' => array_intersect_key($bools($raw['sections'] ?? null), array_flip(ShareSections::FLAGS)),
            'creatives' => $bools($raw['creatives'] ?? null),
            'was_locked' => ($raw['was_locked'] ?? false) === true,
        ];
    }
}
