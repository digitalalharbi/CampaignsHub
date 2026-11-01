<?php

declare(strict_types=1);

namespace App\Domains\Forms\Conversions;

use InvalidArgumentException;

/**
 * FORMS-DISPATCH-001 — the destinations a lead conversion can be reported to, and what each one needs.
 *
 * A kind is listed here only when its payload is BUILT, tested and (where a platform offers one) validated by that
 * platform's own checking endpoint. The platforms that have an official conversions interface but no builder yet are
 * named as PLANNED and cannot be created: a destination row that accepts a name and a secret and then does nothing is
 * the product telling a client their conversions are flowing when they are not.
 */
final class ConversionKinds
{
    public const GA4 = 'ga4';

    public const META = 'meta_capi';

    public const GTM_SERVER = 'gtm_server';

    /** Snapchat Conversions API v3 — built from Snap's own published reference (endpoint, required fields, `/validate`). */
    public const SNAPCHAT = 'snapchat_capi';

    /** TikTok Events API 2.0, `/open_api/v1.3/event/track/` — from TikTok's current reference (read 2026-11-01). */
    public const TIKTOK = 'tiktok_events';

    /** LinkedIn Conversions API, `/rest/conversionEvents`, version 202610 — from LinkedIn's current reference (read 2026-11-01). */
    public const LINKEDIN = 'linkedin_capi';

    /** The tag manager container the PUBLIC PAGE loads — no server delivery, only a script on the page once the team made it live. */
    public const GTM_WEB = 'gtm_web';

    /** @var list<string> */
    public const IMPLEMENTED = [self::GA4, self::META, self::GTM_SERVER, self::SNAPCHAT, self::TIKTOK, self::LINKEDIN, self::GTM_WEB];

    /** @var list<string> official conversions interfaces whose builders are not written yet */
    public const PLANNED = ['google_ads', 'x_conversions'];

    /** Whether a kind is a server-side delivery. `gtm_web` is configuration for the page and has nothing to deliver. */
    public static function deliversFromServer(string $kind): bool
    {
        return $kind !== self::GTM_WEB;
    }

    /** @var array<string,string> kind => the platform's own event name used when none is chosen */
    private const DEFAULT_EVENT = [self::GA4 => 'generate_lead', self::META => 'Lead', self::GTM_SERVER => 'generate_lead', self::SNAPCHAT => 'SIGN_UP', self::TIKTOK => 'Lead', self::LINKEDIN => '', self::GTM_WEB => ''];

    /** @var array<string,list<string>> the secret keys each kind may hold */
    private const SECRET_KEYS = [self::GA4 => ['api_secret'], self::META => ['access_token'], self::GTM_SERVER => ['api_key'], self::SNAPCHAT => ['access_token'], self::TIKTOK => ['access_token'], self::LINKEDIN => ['access_token'], self::GTM_WEB => []];

    /** @var array<string,list<string>> the secret keys a destination needs before it may go live */
    private const REQUIRED_FOR_LIVE = [self::GA4 => ['api_secret'], self::META => ['access_token'], self::GTM_SERVER => [], self::SNAPCHAT => ['access_token'], self::TIKTOK => ['access_token'], self::LINKEDIN => ['access_token'], self::GTM_WEB => []];

    /**
     * Normalise and validate a destination's non-secret settings.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,string>
     */
    public static function config(string $kind, array $raw): array
    {
        $event = isset($raw['event_name']) && is_string($raw['event_name']) && trim($raw['event_name']) !== '' ? trim($raw['event_name']) : (self::DEFAULT_EVENT[$kind] ?? '');

        switch ($kind) {
            case self::GA4:
                $id = strtoupper(trim((string) ($raw['measurement_id'] ?? '')));
                if (preg_match('/^G-[A-Z0-9]{6,12}$/', $id) !== 1) {
                    throw new InvalidArgumentException('A GA4 destination needs its measurement id (G-XXXXXXXXXX).');
                }
                if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $event) !== 1) {
                    throw new InvalidArgumentException('A GA4 event name is lowercase letters, digits and underscores.');
                }

                return ['measurement_id' => $id, 'event_name' => $event];

            case self::META:
                $pixel = trim((string) ($raw['pixel_id'] ?? ''));
                if (preg_match('/^\d{5,20}$/', $pixel) !== 1) {
                    throw new InvalidArgumentException('A Meta destination needs its dataset / pixel id (digits).');
                }
                if (! in_array($event, ['Lead', 'CompleteRegistration', 'Contact', 'SubmitApplication', 'Schedule'], true)) {
                    throw new InvalidArgumentException('Choose one of Meta\'s standard events: Lead, CompleteRegistration, Contact, SubmitApplication, Schedule.');
                }

                return ['pixel_id' => $pixel, 'event_name' => $event];

            case self::GTM_SERVER:
                $url = trim((string) ($raw['endpoint_url'] ?? ''));
                if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
                    throw new InvalidArgumentException('A server-side GTM destination needs the https:// address of the container\'s client.');
                }
                if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $event) !== 1) {
                    throw new InvalidArgumentException('An event name is lowercase letters, digits and underscores.');
                }

                return ['endpoint_url' => $url, 'event_name' => $event];

            case self::SNAPCHAT:
                $pixel = trim((string) ($raw['pixel_id'] ?? ''));
                if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $pixel) !== 1) {
                    throw new InvalidArgumentException('A Snapchat destination needs its Pixel id.');
                }
                // Snap's own standard list has no LEAD event; SIGN_UP is the closest, with five custom slots for anything else.
                if (! in_array($event, ['SIGN_UP', 'SUBSCRIBE', 'RESERVE', 'START_TRIAL', 'CUSTOM_EVENT_1', 'CUSTOM_EVENT_2', 'CUSTOM_EVENT_3', 'CUSTOM_EVENT_4', 'CUSTOM_EVENT_5'], true)) {
                    throw new InvalidArgumentException('Choose one of Snapchat\'s events: SIGN_UP, SUBSCRIBE, RESERVE, START_TRIAL or CUSTOM_EVENT_1…5.');
                }

                return ['pixel_id' => $pixel, 'event_name' => $event];

            case self::TIKTOK:
                $pixel = strtoupper(trim((string) ($raw['pixel_code'] ?? '')));
                if (preg_match('/^[A-Z0-9]{8,40}$/', $pixel) !== 1) {
                    throw new InvalidArgumentException('A TikTok destination needs its Pixel Code.');
                }
                // TikTok's standard events a lead form can honestly report; the names are case-sensitive in its reference.
                if (! in_array($event, ['Lead', 'Contact', 'SubmitApplication', 'CompleteRegistration', 'Schedule'], true)) {
                    throw new InvalidArgumentException('Choose one of TikTok\'s events: Lead, Contact, SubmitApplication, CompleteRegistration or Schedule.');
                }

                return ['pixel_code' => $pixel, 'event_name' => $event];

            case self::LINKEDIN:
                // The rule is created in the customer's Campaign Manager with method CONVERSIONS_API; its id is what they paste here.
                $rule = trim((string) ($raw['conversion_rule_id'] ?? ''));
                if (preg_match('/^[0-9]{1,20}$/', $rule) !== 1) {
                    throw new InvalidArgumentException('A LinkedIn destination needs the id of its conversion rule (digits).');
                }

                return ['conversion_rule_id' => $rule];

            case self::GTM_WEB:
                $container = strtoupper(trim((string) ($raw['container_id'] ?? '')));
                if (preg_match('/^GTM-[A-Z0-9]{4,10}$/', $container) !== 1) {
                    throw new InvalidArgumentException('A web GTM destination needs the container id (GTM-XXXXXX).');
                }

                return ['container_id' => $container];
        }

        throw new InvalidArgumentException(in_array($kind, self::PLANNED, true)
            ? 'This platform\'s conversions interface is not built yet, so a destination for it cannot be created.'
            : 'Unknown destination kind.');
    }

    /**
     * Keep only the secret keys the kind knows, trimmed and bounded.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,string>
     */
    public static function secrets(string $kind, array $raw): array
    {
        $out = [];
        foreach (self::SECRET_KEYS[$kind] ?? [] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $out[$key] = mb_substr(trim($raw[$key]), 0, 512);
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>|null  $secrets
     * @return list<string> the secret keys still missing before this destination may go live
     */
    public static function missingForLive(string $kind, ?array $secrets): array
    {
        return array_values(array_filter(self::REQUIRED_FOR_LIVE[$kind] ?? [], static fn (string $k): bool => ! isset($secrets[$k]) || $secrets[$k] === ''));
    }
}
