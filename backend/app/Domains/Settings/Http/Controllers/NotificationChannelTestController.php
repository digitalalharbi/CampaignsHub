<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Services\WhatsAppChannel;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Notifications\Support\TestSendMark;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /settings/notifications/test — one test notification, to the signed-in person, on one channel.
 *
 * - `in_app` — a real bell item through the dispatcher, so the person's own switches decide whether it
 *   appears (a person who switched the bell off is told that, not shown a lie).
 * - `email` — the mail test's own rules: the transport's answer is the answer, the address must have been
 *   PROVED (`email_verified_at`), the message carries the test mark and offers no way out, and it goes
 *   through the one mailer's ledger as `TEST_KIND`. Throttled by the named `mail-test-send` limiter on the
 *   route. It can only ever go to the person who asked.
 * - `whatsapp` — refused until a provider reports configured AND this number has an opt-in. Today that is
 *   always the refusal, with its reason, and nothing is sent.
 */
final class NotificationChannelTestController extends Controller
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly TransactionalMailer $mailer,
        private readonly ProviderRegistry $providers,
        private readonly WhatsAppChannel $whatsapp,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:in_app,email,whatsapp']]);
        /** @var User $user */
        $user = $request->user();
        $ar = ($user->locale ?? 'ar') !== 'en';
        $channel = (string) $data['channel'];

        return match ($channel) {
            'in_app' => $this->inApp($user, $ar),
            'email' => $this->email($user, $ar),
            default => $this->whatsapp($user, $ar),
        };
    }

    private function inApp(User $user, bool $ar): JsonResponse
    {
        $tenantId = (string) $this->tenants->tenantId();
        $n = $this->dispatcher->dispatch([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'type' => 'test_notification', 'severity' => 'info',
            'title' => $ar ? 'إشعار تجريبي' : 'Test notification',
            'message' => $ar ? 'هكذا يظهر لك إشعار داخل النظام.' : 'This is how a notification looks inside the product.',
            'source' => 'settings', 'entity_type' => 'test', 'entity_id' => (string) Str::uuid(), 'action_url' => '/settings',
        ]);

        return ApiResponse::success([
            'channel' => 'in_app',
            'state' => $n === null ? 'suppressed_by_preference' : 'sent',
        ], 'Test recorded.');
    }

    private function email(User $user, bool $ar): JsonResponse
    {
        $lang = $ar ? 'ar' : 'en';

        if ($user->email_verified_at === null) {
            $this->audit->log(action: 'mail.test_send.refused', after: ['template' => 'notification.test', 'recipient' => MailAddress::mask((string) $user->email)], reason: 'address_not_proven');

            return ApiResponse::error('Your address has not been verified, so no test can be sent to it.', ['code' => 'address_not_proven'], status: 422);
        }

        $mail = TestSendMark::apply(new OperationalMail(
            kind: 'test_notification', severity: 'info',
            title: $ar ? 'إشعار تجريبي' : 'Test notification',
            detail: $ar ? 'هكذا يصلك إشعار على البريد.' : 'This is how a notification reaches your inbox.',
            context: 'CampaignsHub', lang: $lang, recipientName: (string) $user->name, path: '/settings',
        ), $lang);

        $dedupKey = 'test:'.$user->id.':notification:'.$lang.':'.now()->format('YmdHi');
        $state = $this->mailer->send(
            recipient: (string) $user->email, mail: $mail, kind: TransactionalMailer::TEST_KIND,
            template: 'notification.test', locale: $lang, userId: (int) $user->id, dedupKey: $dedupKey,
        );
        $row = DB::table('mail_deliveries')->where('dedup_key', $dedupKey)->first();

        $this->audit->log(action: 'mail.test_send', entityType: 'mail_delivery', entityId: $row === null ? null : (string) $row->id,
            after: ['template' => 'notification.test', 'recipient' => MailAddress::mask((string) $user->email), 'state' => $state]);

        return ApiResponse::success(['channel' => 'email', 'state' => (string) ($row->status ?? $state)], 'Test recorded.');
    }

    private function whatsapp(User $user, bool $ar): JsonResponse
    {
        $optedIn = $user->whatsapp_opt_in_at !== null && ($user->whatsapp_opted_out_at === null || $user->whatsapp_opted_out_at < $user->whatsapp_opt_in_at);

        if (! $this->providers->isConfigured('whatsapp')) {
            return ApiResponse::error('WhatsApp is not available yet: no provider is configured.', ['code' => 'whatsapp_not_available'], status: 422);
        }

        if (! $optedIn) {
            return ApiResponse::error('WhatsApp is not on for this account: there is no confirmed opt-in.', ['code' => 'whatsapp_no_opt_in'], status: 422);
        }

        $probe = new AppNotification([
            'title' => $ar ? 'رسالة اختبار' : 'Test message',
            'message' => $ar ? 'هكذا تصلك رسالة واتساب.' : 'This is how a WhatsApp message reaches you.',
        ]);

        return ApiResponse::success([
            'channel' => 'whatsapp',
            'state' => $this->whatsapp->deliver($probe, (int) $user->id, 'test_notification', false, null),
        ], 'Test recorded.');
    }
}
