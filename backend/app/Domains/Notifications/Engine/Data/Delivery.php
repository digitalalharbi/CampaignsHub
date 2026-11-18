<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\RecipientKind;
use App\Domains\Notifications\Engine\Enums\Scope;

/** One delivery = one outbox row = one recipient on one channel (spec §3, §4). */
final class Delivery
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly string $tenantId,
        public readonly string $eventKey,
        public readonly Scope $scope,
        public readonly Channel $channel,
        public readonly Priority $priority,
        public readonly Recipient $recipient,
        public readonly ?string $projectId,
        public readonly ?string $subscriptionId,
        public readonly array $payload,
        public readonly int $contentVersion,
        public readonly int $attempts,
        public readonly int $groupCount = 1,
    ) {}

    /** The same delivery read in another language (the in-app row stores both). */
    public function withLocale(string $locale): self
    {
        $r = $this->recipient;

        return new self($this->id, $this->reference, $this->tenantId, $this->eventKey, $this->scope, $this->channel, $this->priority,
            new Recipient($r->kind, $r->userId, $r->externalId, $r->address, $locale, $r->roleKey),
            $this->projectId, $this->subscriptionId, $this->payload, $this->contentVersion, $this->attempts, $this->groupCount);
    }

    public static function fromRow(object $row): self
    {
        $kind = RecipientKind::from((string) $row->recipient_kind);
        $payload = is_string($row->payload) ? (array) json_decode($row->payload, true) : (array) $row->payload;

        return new self(
            id: (string) $row->id,
            reference: (string) $row->reference,
            tenantId: (string) $row->tenant_id,
            eventKey: (string) $row->event_key,
            scope: Scope::from((string) $row->scope),
            channel: Channel::from((string) $row->channel),
            priority: Priority::from((string) $row->priority),
            recipient: new Recipient(
                $kind,
                $row->recipient_user_id === null ? null : (int) $row->recipient_user_id,
                $row->recipient_external_id === null ? null : (string) $row->recipient_external_id,
                $row->recipient_address === null ? null : (string) $row->recipient_address,
                (string) ($payload['_locale'] ?? 'ar'),
                (string) ($payload['_role'] ?? 'other'),
            ),
            projectId: $row->project_id === null ? null : (string) $row->project_id,
            subscriptionId: $row->subscription_id === null ? null : (string) $row->subscription_id,
            payload: $payload,
            contentVersion: (int) $row->content_version,
            attempts: (int) $row->attempts,
            groupCount: (int) ($row->group_count ?? 1),
        );
    }
}
