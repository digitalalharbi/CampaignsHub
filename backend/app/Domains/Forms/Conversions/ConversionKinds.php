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

    /** The tag manager container the PUBLIC PAGE loads — no server delivery, only a script on the page once the team made it live. */
    public const GTM_WEB = 'gtm_web';

    /** @var list<string> */
    public const IMPLEMENTED = [self::GA4, self::META, self::GTM_SERVER, self::GTM_WEB];

    /** @var list<string> official conversions interfaces whose builders are not written yet */
    public const PLANNED = ['tiktok_events', 'snapchat_capi', 'google_ads', 'linkedin_capi', 'x_conversions'];

    /** Whether a kind is a server-side delivery. `gtm_web` is configuration for the page and has nothing to deliver. */
    public static function deliversFromServer(string $kind): bool
    {
        return $kind !== self::GTM_WEB;
    }

    /** @var array<string,string> kind => the platform's own event name used when none is chosen */
    private const DEFAULT_EVENT = [self::GA4 => 'generate_lead', self::META => 'Lead', self::GTM_SERVER => 'generate_lead', self::GTM_WEB => ''];

    /** @var array<string,list<string>> the secret keys each kind may hold */
    private const SECRET_KEYS = [self::GA4 => ['api_secret'], self::META => ['access_token'], self::GTM_SERVER => ['api_key'], self::GTM_WEB => []];

    /** @var array<string,list<string>> the secret keys a destination needs before it may go live */
    private const REQUIRED_FOR_LIVE = [self::GA4 => ['api_secret'], self::META => ['access_token'], self::GTM_SERVER => [], self::GTM_WEB => []];

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
