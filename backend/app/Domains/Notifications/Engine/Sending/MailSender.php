<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Sending;

use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\Sender;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\External\Mail\ExternalMailer;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailFailure;
use App\Domains\Notifications\Support\MailShell;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email through the configured provider (spec §4). `accepted` only when the transport returned without an error — the
 * moment SMTP said «I took it». A transport error is classified by the existing `MailFailure`: permanent refusals stop at
 * once, anything else is retried by the pipeline. With no provider configured nothing is pretended: `failed` with the reason.
 *
 * External: the sender display name is «[المساحة] عبر CampaignsHub» (spec §8) and Reply-To the workspace's sender email;
 * the From address stays the verified platform address (see `EngineMail`).
 */
final class MailSender implements Sender
{
    public function __construct(
        private readonly MessageComposer $composer,
        private readonly ProviderRegistry $providers,
        private readonly WorkspaceSettingsRepository $settings,
    ) {}

    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function send(Delivery $delivery): SendResult
    {
        $address = trim((string) $delivery->recipient->address);
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return SendResult::skipped('no_address');
        }
        if (! $this->providers->isConfigured('email')) {
            return SendResult::failed('provider_not_configured', permanent: true);
        }

        // External (spec §8–§13): the full service-report message with its own envelope per address and CC/BCC copies.
        if ($delivery->scope === Scope::External && app()->bound(ExternalMailer::class)) {
            return app(ExternalMailer::class)->send($delivery);
        }

        $message = $this->composer->compose($delivery);
        if ($delivery->scope === Scope::External) {
            $ws = $this->settings->for($delivery->tenantId);
            $message = new ComposedMessage(
                $message->subject, $message->title, $message->body, $message->html, $message->text, $message->url, $message->externalReady,
                $message->senderName ?? self::externalSenderName($ws->workspaceName, $delivery->recipient->locale),
                $message->replyTo ?? (isset($ws->settings['sender']['reply_to']) ? (string) $ws->settings['sender']['reply_to'] : null),
            );
        }

        try {
            $sent = Mail::to($address)->send(new EngineMail($message, $delivery->reference, $delivery->recipient->locale));
        } catch (Throwable $e) {
            $failure = MailFailure::from($e);

            return SendResult::failed($failure['code'].': '.$failure['safe'], MailFailure::isPermanent($failure['code']));
        }

        return SendResult::accepted(TransactionalMailer::messageId($sent));
    }

    /** «[اسم المساحة] عبر كامبينز هَب» / «[workspace] via CampaignsHub» — mandatory, not removable by the workspace (spec §8, §12; brand per BRAND-LANG-001). */
    public static function externalSenderName(string $workspace, string $locale): string
    {
        $workspace = trim($workspace);
        if ($workspace === '') {
            return 'CampaignsHub';
        }

        // BRAND-LANG-001 — an Arabic line names the product in Arabic (`MailShell::wordmark`), an English one in Latin.
        return $locale === 'en' ? "{$workspace} via ".MailShell::wordmark(false) : "{$workspace} عبر ".MailShell::wordmark(true);
    }
}
