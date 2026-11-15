<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Mail;

use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\DeliveryLog;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\Recipient;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use App\Domains\Notifications\Engine\Mail\AlignEnvelopeSender;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use App\Domains\Notifications\Support\MailFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends one external delivery and its CC/BCC copies (spec §9 «Sender identity + CC/BCC», §8 identity).
 *
 * - One unified header: `To` = the subscriber, `Cc` = the visible copies; BCC addresses appear in NO header, ever.
 * - Each address gets its OWN message (separate SMTP envelope, `ExternalTransport`) composed for it: its own
 *   unsubscribe link, and the «نسخة للاطلاع» band on a copy. Duplicates are delivered once (the subscriber's address
 *   is never also a copy).
 * - Copies leave only after the primary was accepted, so a retried primary never re-sends copies; each copy is written
 *   to the immutable log as its own row (reference `<primary>-C<n>`), accepted or failed — a copy is never retried.
 * - A copy address outside the org domains goes only to a recipient row that confirmed (consent like any recipient).
 * - From = the verified platform address; display name «[المساحة] عبر CampaignsHub»; Reply-To = the workspace sender.
 * - Charts are inline CID parts; their number is added to the monthly `charts_generated` counter on acceptance.
 */
final class ExternalMailer
{
    public function __construct(
        private readonly MessageComposer $composer,
        private readonly ExternalTransport $transport,
        private readonly ExternalSettings $settings,
        private readonly DeliveryLog $log,
        private readonly UsageCounter $usage,
    ) {}

    public function send(Delivery $delivery): SendResult
    {
        $address = strtolower(trim((string) $delivery->recipient->address));
        $sub = $delivery->subscriptionId === null ? null
            : DB::table('notification_subscriptions')->where('tenant_id', $delivery->tenantId)->where('id', $delivery->subscriptionId)->first();
        $json = static fn ($v): array => $v === null ? [] : array_values(array_map(static fn ($a): string => strtolower(trim((string) $a)), (array) (is_string($v) ? json_decode($v, true) : $v)));
        $cc = array_values(array_diff($json($sub?->cc), [$address]));
        $bcc = array_values(array_diff($json($sub?->bcc), [$address], $cc));
        $copiesWanted = $this->wantsCopies($delivery);
        $visibleCc = $copiesWanted ? array_values(array_filter($cc, fn (string $a): bool => $this->copyAllowed($delivery->tenantId, $a))) : [];

        $message = $this->composer->compose($delivery);
        try {
            $id = $this->transport->send($this->email($message, $address, $visibleCc, $delivery->reference), $this->envelope($address));
        } catch (Throwable $e) {
            $failure = MailFailure::from($e);

            return SendResult::failed($failure['code'].': '.$failure['safe'], MailFailure::isPermanent($failure['code']));
        }
        $this->countCharts($delivery, $message);

        if ($copiesWanted) {
            $n = 0;
            foreach ([['cc', $visibleCc], ['bcc', $bcc]] as [$kind, $list]) {
                foreach ($list as $copy) {
                    if (! $this->copyAllowed($delivery->tenantId, $copy)) {
                        continue;
                    }
                    $this->sendCopy($delivery, $kind, $copy, $address, $visibleCc, ++$n);
                }
            }
        }

        return SendResult::accepted($id);
    }

    private function sendCopy(Delivery $primary, string $kind, string $copy, string $to, array $visibleCc, int $n): void
    {
        $copyRecipient = DB::table('notification_external_recipients')->where('tenant_id', $primary->tenantId)->where('email', $copy)->where('status', 'active')->value('id');
        $payload = $primary->payload + ['_copy' => $kind];
        if ($copyRecipient !== null) {
            $payload['_copy_recipient_id'] = (string) $copyRecipient;
        }
        $r = $primary->recipient;
        $copyDelivery = new Delivery(
            (string) Str::uuid(), mb_substr($primary->reference.'-C'.$n, 0, 32), $primary->tenantId, $primary->eventKey, $primary->scope, $primary->channel,
            $primary->priority, new Recipient($r->kind, null, $r->externalId, $copy, $r->locale, $r->roleKey),
            $primary->projectId, $primary->subscriptionId, $payload, $primary->contentVersion, 1, $primary->groupCount,
        );
        try {
            $message = $this->composer->compose($copyDelivery);
            $id = $this->transport->send($this->email($message, $to, $visibleCc, $copyDelivery->reference), $this->envelope($copy));
            $this->log->record($copyDelivery, DeliveryStatus::Sent, null, SendResult::accepted($id));
        } catch (Throwable $e) {
            $this->log->record($copyDelivery, DeliveryStatus::Failed, 'copy: '.MailFailure::from($e)['code']);
        }
    }

    /** @param list<string> $cc */
    private function email(ComposedMessage $m, string $to, array $cc, string $reference): Email
    {
        $email = (new Email)
            ->from(new Address((string) config('mail.from.address'), (string) ($m->senderName ?? config('mail.from.name'))))
            ->to(new Address($to))
            ->subject($m->subject)
            ->html($m->html)
            ->text($m->text);
        foreach ($cc as $address) {
            $email->addCc(new Address($address));
        }
        if ($m->replyTo !== null) {
            $email->replyTo(new Address($m->replyTo));
        }
        $sender = AlignEnvelopeSender::verifiedSender();
        if ($sender !== null && strcasecmp($sender, (string) config('mail.from.address')) !== 0) {
            $email->sender(new Address($sender));
        }
        foreach ($m->inline as $cid => $png) {
            $email->embed($png, $cid, 'image/png');
        }
        $headers = $email->getHeaders();
        if (preg_match('#https?://\S+/n/u/[A-Za-z0-9_\-]+\.[a-f0-9]{32}#', $m->html, $u) === 1) {
            $headers->addTextHeader('List-Unsubscribe', '<'.html_entity_decode($u[0]).'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $headers->addTextHeader('X-CH-Reference', $reference);

        return $email;
    }

    private function envelope(string $rcpt): Envelope
    {
        $from = AlignEnvelopeSender::verifiedSender() ?? (string) config('mail.from.address');

        return new Envelope(new Address($from), [new Address($rcpt)]);
    }

    /** Copy delivery for the sender: all messages / events only / digests only (workspace setting). */
    private function wantsCopies(Delivery $d): bool
    {
        $mode = (string) ($this->settings->for($d->tenantId)['external']['copy_delivery'] ?? 'all');
        $digest = str_starts_with($d->eventKey, 'digest.');

        return match ($mode) {
            'events' => ! $digest,
            'digest' => $digest,
            default => true,
        };
    }

    private function copyAllowed(string $tenantId, string $address): bool
    {
        if ($this->settings->workspace($tenantId)->isOrgAddress($address)) {
            return true;
        }

        return DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('email', $address)->where('status', 'active')->exists();
    }

    private function countCharts(Delivery $d, ComposedMessage $m): void
    {
        $charts = count(array_filter(array_keys($m->inline), static fn (string $cid): bool => ! str_ends_with($cid, '-dark') && $cid !== 'lock'));
        if ($charts > 0) {
            $this->usage->increment($d->tenantId, UsageCounter::CHARTS_GENERATED, $charts);
        }
    }
}
