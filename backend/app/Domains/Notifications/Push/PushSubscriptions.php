<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * NOTIF-PUSH-001 — registering and revoking a device, and refusing an endpoint that is not a push service.
 *
 * The server POSTs to the endpoint a browser hands it, so the endpoint is checked before it is stored: https only, port 443,
 * no credentials in the URL, no IP literal, and a host on the push-service allow-list (`config/webpush.php`). Redirects are never
 * followed when sending, so an allowed host cannot bounce the request elsewhere either.
 */
final class PushSubscriptions
{
    public const MAX_DEVICES_PER_USER = 10;

    /** @param array{endpoint: string, keys: array{p256dh: string, auth: string}, label?: string|null, content_encoding?: string|null} $data */
    public function register(User $user, array $data, ?string $userAgent = null): PushSubscription
    {
        $endpoint = $this->vetted($data['endpoint']);
        $hash = PushSubscription::hashOf($endpoint);

        $existing = PushSubscription::query()->where('endpoint_hash', $hash)->first();
        // The same browser signing in as somebody else takes the device over: it is one browser, and it belongs to who is signed in now.
        if ($existing === null && PushSubscription::query()->where('user_id', $user->getKey())->count() >= self::MAX_DEVICES_PER_USER) {
            throw ValidationException::withMessages(['endpoint' => ['وصلت للحد الأقصى من الأجهزة. احذف جهازًا قديمًا أولًا.']]);
        }

        $fields = [
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'content_encoding' => in_array($data['content_encoding'] ?? 'aes128gcm', ['aes128gcm', 'aesgcm'], true) ? ($data['content_encoding'] ?? 'aes128gcm') : 'aes128gcm',
            'label' => $this->label($data['label'] ?? null, $userAgent),
            'failure_count' => 0,
            'last_failure_at' => null,
        ];

        if ($existing !== null) {
            $existing->forceFill($fields)->save();

            return $existing;
        }

        return PushSubscription::create($fields + ['endpoint_hash' => $hash]);
    }

    public function vetted(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $bad = $parts === false || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false || ! $this->allowed($host);

        if ($bad) {
            throw ValidationException::withMessages(['endpoint' => ['هذا المتصفح غير مدعوم للإشعارات.']]);
        }

        return $endpoint;
    }

    private function allowed(string $host): bool
    {
        foreach ((array) config('webpush.allowed_hosts', []) as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** A name the person recognises — «Chrome · Android» — from the browser's own words, never the raw user-agent. */
    private function label(?string $given, ?string $userAgent): string
    {
        $given = trim((string) $given);
        if ($given !== '' && mb_strlen($given) <= 80) {
            return preg_replace('/[^\p{L}\p{N} ·\-_.]/u', '', $given) ?: $this->fromAgent($userAgent);
        }

        return $this->fromAgent($userAgent);
    }

    private function fromAgent(?string $ua): string
    {
        $ua = (string) $ua;
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'جهاز',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/'), str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'متصفح',
        };

        return $browser.' · '.$os;
    }
}
