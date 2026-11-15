<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Push\PushAudience;
use App\Domains\Notifications\Push\PushDispatcher;
use App\Domains\Notifications\Push\PushSubscription;
use App\Domains\Notifications\Push\PushSubscriptions;
use App\Domains\Notifications\Push\PushTransport;
use App\Domains\Notifications\Push\PushTypes;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOTIF-PUSH-001 — a person's own mobile devices.
 *
 * Everything here is about the signed-in person's OWN subscriptions: another person's device id answers 404, never 403, so the
 * existence of a device is not disclosed. Permission is never requested by a page load: the browser's permission prompt is shown
 * only after the person presses «فعّل إشعارات الجوال» in the preferences page.
 */
final class PushController extends Controller
{
    public function __construct(
        private readonly PushTransport $transport,
        private readonly PushSubscriptions $subscriptions,
        private readonly PushDispatcher $push,
        private readonly PushAudience $audience,
        private readonly TenantContext $tenant,
    ) {}

    /** The public key the browser subscribes with, whether the server can send, and this person's devices. */
    public function show(Request $request): JsonResponse
    {
        $configured = $this->transport->configured();

        return ApiResponse::success([
            'configured' => $configured,
            // The PUBLIC half only. The private key never leaves the server's environment.
            'public_key' => $configured ? (string) config('webpush.vapid.public_key') : null,
            'devices' => PushSubscription::query()->where('user_id', $request->user()->id)->orderByDesc('created_at')->get()
                ->map(static fn (PushSubscription $d): array => [
                    'id' => (string) $d->id,
                    'label' => (string) ($d->label ?? ''),
                    'created_at' => $d->created_at?->toIso8601String(),
                    'last_used_at' => $d->last_used_at?->toIso8601String(),
                    'failing' => (int) $d->failure_count >= 3,
                ])->all(),
            'types' => PushTypes::ALL,
        ], 'Push settings retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->transport->configured(), 503, 'Mobile notifications are not set up on this server.');

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['sometimes', 'nullable', 'string', 'in:aes128gcm,aesgcm'],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $device = $this->subscriptions->register($request->user(), $data, $request->userAgent());

        return ApiResponse::success(['id' => (string) $device->id, 'label' => (string) $device->label], 'Device registered.', status: 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        PushSubscription::query()->where('user_id', $request->user()->id)->whereKey($id)->firstOrFail()->delete();

        return ApiResponse::success(null, 'Device removed.');
    }

    /** Sign-out on a device: forget THIS browser's subscription, whichever row it is. */
    public function forget(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        PushSubscription::query()->where('user_id', $request->user()->id)->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))->delete();

        return ApiResponse::success(null, 'Device removed.');
    }

    /** A harmless test message to one of the person's devices. */
    public function test(Request $request, string $id): JsonResponse
    {
        $device = PushSubscription::query()->where('user_id', $request->user()->id)->whereKey($id)->firstOrFail();
        $user = $request->user();
        $prefix = $this->audience->prefixFor($user, (string) $this->tenant->tenantId());

        $result = $this->push->test($device, ($user->locale ?? 'ar') !== 'en', $prefix);

        return ApiResponse::success(['delivered' => $result->ok, 'removed' => $result->gone], $result->ok ? 'Test sent.' : 'The push service did not accept the test.');
    }
}
