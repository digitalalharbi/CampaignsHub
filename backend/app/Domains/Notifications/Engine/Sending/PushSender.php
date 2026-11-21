<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Sending;

use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\Sender;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Push\PushAudience;
use App\Domains\Notifications\Push\PushDispatcher;
use App\Domains\Notifications\Push\PushTransport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mobile / desktop Web Push through builder L's stack (VAPID transport, device list, 404/410 cleanup, SSRF checks) —
 * reused, not copied: this only builds the payload and calls `PushDispatcher::deliver`.
 *
 * Payload (spec §5): title = project (or workspace), body = one line, url = a path in the person's own portal. No amounts.
 * No VAPID pair → skipped (`push_not_configured`); no device → skipped (`no_device`); at least one device took it → accepted.
 */
final class PushSender implements Sender
{
    public function __construct(
        private readonly PushDispatcher $push,
        private readonly PushTransport $transport,
        private readonly PushAudience $audience,
        private readonly MessageComposer $composer,
    ) {}

    public function channel(): Channel
    {
        return Channel::Push;
    }

    public function send(Delivery $delivery): SendResult
    {
        if (! $this->transport->configured()) {
            return SendResult::skipped('push_not_configured');
        }
        $user = $delivery->recipient->userId === null ? null : User::query()->find($delivery->recipient->userId);
        if ($user === null) {
            return SendResult::skipped('no_user');
        }
        if (DB::table('push_subscriptions')->where('user_id', $user->getKey())->doesntExist()) {
            return SendResult::skipped('no_device');
        }

        $message = $this->composer->compose($delivery);
        $object = (array) (($delivery->payload['_objects'] ?? [])[0] ?? []);
        $prefix = $this->audience->prefixFor($user, $delivery->tenantId);
        $url = (string) ($delivery->payload['url'] ?? $prefix.'/dashboard');

        $result = $this->push->deliver($user, [
            'title' => (string) ($delivery->payload['project_name'] ?? $delivery->payload['workspace_name'] ?? 'CampaignsHub'),
            'body' => mb_substr($message->title, 0, 120),
            'url' => str_starts_with($url, '/') ? $url : $prefix.'/dashboard',
            'tag' => mb_substr('ch-'.$delivery->eventKey.'-'.($object['id'] ?? $delivery->id), 0, 64),
            'kind' => $delivery->eventKey,
            'notification' => $delivery->reference,
        ], $delivery->priority === Priority::Critical ? 'high' : 'normal');

        return $result['sent'] > 0 ? SendResult::accepted() : SendResult::failed('push_not_accepted');
    }
}
