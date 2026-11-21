<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * NOTIF-PUSH-001 — Web Push through `minishlink/web-push` (MIT, free): RFC 8291 encryption and RFC 8292 VAPID, over plain HTTPS.
 *
 * The HTTP client never follows a redirect and answers 4xx / 5xx as values, so a hostile endpoint cannot bounce this server to
 * another address and a push service's refusal is read here instead of thrown.
 */
final class MinishlinkTransport implements PushTransport
{
    /** @param  HandlerStack|null  $handler  a Guzzle handler, for tests (a mock push service) */
    public function __construct(private readonly ?HandlerStack $handler = null) {}

    public function configured(): bool
    {
        return (string) config('webpush.vapid.public_key') !== '' && (string) config('webpush.vapid.private_key') !== '';
    }

    public function send(PushSubscription $subscription, string $payload, array $options = []): PushResult
    {
        if (! $this->configured()) {
            return new PushResult(false, false, null, 'vapid_not_configured');
        }

        try {
            $client = new Client(array_filter([
                'handler' => $this->handler,
                'timeout' => (int) config('webpush.timeout', 10),
                'connect_timeout' => 5,
                'allow_redirects' => false,
                'http_errors' => false,
            ], static fn ($v): bool => $v !== null));

            $push = new WebPush(['VAPID' => [
                'subject' => (string) config('webpush.vapid.subject'),
                'publicKey' => (string) config('webpush.vapid.public_key'),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ]], [], $client);

            $report = $push->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth,
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                $payload,
                ['TTL' => (int) ($options['ttl'] ?? config('webpush.ttl', 14400)), 'urgency' => $options['urgency'] ?? 'normal'] + (isset($options['topic']) ? ['topic' => $options['topic']] : []),
            );

            $status = $report->getResponse()?->getStatusCode();

            // The library calls anything below 400 a success; a push service accepts with a 2xx, and a redirect is a refusal here.
            return new PushResult($report->isSuccess() && $status !== null && $status >= 200 && $status < 300, $report->isSubscriptionExpired(), $status, $report->getReason());
        } catch (Throwable $e) {
            // A malformed key, a timeout, a refused connection: this device failed, the others and the bell are untouched.
            return new PushResult(false, false, null, $e::class);
        }
    }
}
