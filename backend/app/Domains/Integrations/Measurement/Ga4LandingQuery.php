<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Commerce\ValueObjects\Attribution;

/**
 * GA4-ATTRIBUTION-MATCH-001 — a landing page's query string, reduced to what the match needs.
 *
 * GA4's Data API exposes no click-id dimension; the only place a gclid / fbclid / ttclid / ScCid
 * appears is the landing URL's query (`landingPagePlusQueryString`). That string is unique per click,
 * so storing it would store one row per visitor. It is folded to a SIGNATURE instead: the click-id
 * FAMILY present (`meta`, `google`, ...) and the UTM values, never the click id's own value.
 *
 * Signature: `family=meta;utm_campaign=ramadan;utm_source=facebook`, keys sorted, absent keys omitted.
 * Only parameters in this map are read, so nothing else in a URL (an e-mail, a token) is kept.
 */
final class Ga4LandingQuery
{
    private const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_content', 'utm_term'];

    /** @return array{family: ?string, utm: array<string, string>} */
    public function parse(string $landing): array
    {
        $query = (string) parse_url($landing, PHP_URL_QUERY);
        if ($query === '' && str_contains($landing, '=')) {
            $query = ltrim(substr($landing, (int) strpos($landing, '?') + 1), '?');
        }
        parse_str($query, $params);
        $params = array_change_key_case(array_map(static fn ($v) => is_array($v) ? '' : (string) $v, $params), CASE_LOWER);

        $family = null;
        foreach (Attribution::clickIdFamilies() as $param => $platform) {
            $value = $params[strtolower($param)] ?? '';
            // A macro the platform never filled in is not a click: `{oppref}`, `{{click_id}}`, `__CLICKID__`.
            if ($value !== '' && preg_match('/^[\{_]/', $value) !== 1) {
                $family = $platform;
                break;
            }
        }

        $utm = [];
        foreach (self::UTM as $key) {
            $v = trim($params[$key] ?? '');
            if ($v !== '' && preg_match('/^[\{_]/', $v) !== 1) {
                $utm[$key] = mb_substr($v, 0, 190);
            }
        }

        return ['family' => $family, 'utm' => $utm];
    }

    public function signature(string $landing): string
    {
        $p = $this->parse($landing);
        $parts = $p['family'] === null ? [] : ['family='.$p['family']];
        $utm = $p['utm'];
        ksort($utm);
        foreach ($utm as $k => $v) {
            $parts[] = $k.'='.str_replace(';', ',', $v);
        }

        return implode(';', $parts);
    }

    /** @return array{family: ?string, utm: array<string, string>} */
    public function fromSignature(string $signature): array
    {
        $family = null;
        $utm = [];
        foreach ($signature === '' ? [] : explode(';', $signature) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            if ($k === 'family') {
                $family = $v;
            } elseif ($k !== '') {
                $utm[$k] = $v;
            }
        }

        return ['family' => $family, 'utm' => $utm];
    }
}
