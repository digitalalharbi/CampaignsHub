<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Services;

use App\Domains\ShortLinks\Models\ShortLink;
use Illuminate\Validation\ValidationException;

/**
 * SHORT-LINKS-001 — turning the ONE value somebody typed into a destination.
 *
 * ## Why this is a service and not a validation rule
 *
 * The owner's requirement is that the user never meets a technical decision: they choose WhatsApp or
 * Link, type one thing, and press create. Everything between that and a working hop — what a phone
 * number becomes, which URLs are safe to send a stranger to — is the system's job, and it belongs
 * somewhere it can be read and tested rather than spread across a form request and a controller.
 *
 * ## What «safe» refuses, and why each one
 *
 * A short link is an open redirect with a friendly name, so the refusals are the feature:
 *
 *   - anything but `https` — `http` is a downgrade a reader cannot see before clicking, and
 *     `javascript:` and `data:` are script execution wearing a URL's clothes;
 *   - credentials in the authority (`user:pass@host`) — the oldest way to make a hostile host read
 *     as a familiar one;
 *   - hosts nobody outside can reach — `localhost`, a bare IP, `.local`, `.internal` — which cannot
 *     be a destination for a link that exists to be sent to somebody else, and which turn the
 *     platform into a probe of its own network;
 *   - our own short-link path, because a link to a link is a loop the hop would follow.
 */
final class ShortLinkDestinations
{
    /** E.164 allows at most fifteen digits, and a country code means at least seven is realistic. */
    private const PHONE_MIN = 7;

    private const PHONE_MAX = 15;

    /**
     * The destination a chosen kind and a typed value resolve to.
     *
     * @return array{kind: string, destination: string, source_value: string}
     *
     * @throws ValidationException with the field the person actually typed into
     */
    public function resolve(string $kind, string $value): array
    {
        return match ($kind) {
            ShortLink::KIND_WHATSAPP => $this->whatsapp($value),
            ShortLink::KIND_LINK => $this->link($value),
            default => throw ValidationException::withMessages([
                'kind' => [__('validation.in', ['attribute' => 'kind'])],
            ]),
        };
    }

    /**
     * A phone number, and nothing else the person has to understand.
     *
     * Spaces, dashes, brackets and a leading `+` are what people actually type off a phone screen, so
     * they are accepted and removed rather than refused — a validation message about punctuation is
     * the sort of technical obstacle this feature exists not to have. What is REFUSED is a number
     * that cannot be a phone number at all, because the resulting `wa.me` link would fail silently in
     * the recipient's app rather than here.
     */
    private function whatsapp(string $value): array
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) < self::PHONE_MIN || strlen($digits) > self::PHONE_MAX) {
            throw ValidationException::withMessages([
                'value' => [__('Enter the phone number in full, including the country code.')],
            ]);
        }

        /*
         * A leading zero is a national trunk prefix, not part of the international number, and
         * `wa.me` reads what it is given literally. Left in, the link opens a chat with nobody.
         */
        $digits = ltrim($digits, '0');

        if (strlen($digits) < self::PHONE_MIN) {
            throw ValidationException::withMessages([
                'value' => [__('Enter the phone number in full, including the country code.')],
            ]);
        }

        return [
            'kind' => ShortLink::KIND_WHATSAPP,
            'destination' => 'https://wa.me/'.$digits,
            // Their input, kept so the management list can show what they typed rather than our derivation.
            'source_value' => $digits,
        ];
    }

    private function link(string $value): array
    {
        $url = trim($value);
        $parts = parse_url($url);

        $refuse = static function (string $message): never {
            throw ValidationException::withMessages(['value' => [$message]]);
        };

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $refuse(__('Paste the full address, starting with https://'));
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            $refuse(__('The address must start with https://'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $refuse(__('That address carries a username or password and cannot be shortened.'));
        }

        $host = strtolower((string) $parts['host']);

        if (! $this->reachableFromOutside($host)) {
            $refuse(__('That address is not reachable from outside this network.'));
        }

        if ($this->isOurOwnHop($host, (string) ($parts['path'] ?? ''))) {
            $refuse(__('That is already a short link.'));
        }

        return [
            'kind' => ShortLink::KIND_LINK,
            'destination' => $url,
            'source_value' => $url,
        ];
    }

    /**
     * A destination a stranger can actually open.
     *
     * A bare IP is refused along with the private ranges rather than only those: a public IP is a
     * destination nobody would type deliberately here, and allowing the form means carrying a
     * private-range check that has to stay right forever, including for IPv6.
     */
    private function reachableFromOutside(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (! str_contains($host, '.')) {
            return false; // `localhost`, and any other single-label name
        }

        foreach (['.local', '.internal', '.localhost', '.test', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /** A short link pointing at this platform's own hop is a loop with an extra request in it. */
    private function isOurOwnHop(string $host, string $path): bool
    {
        $ours = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $ours !== '' && $host === $ours && str_starts_with($path, '/l/');
    }
}
