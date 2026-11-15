<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Http;

use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Data\Recipient;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\RecipientKind;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Engine\Scheduling\SubscriptionScheduler;
use App\Domains\Notifications\External\Content\ExternalMessageComposer;
use App\Domains\Notifications\External\Mail\ExternalTransport;
use App\Domains\Notifications\Support\MailFailure;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * The wizard's live preview and the two send buttons (spec §14): «اعرض الرسالة» (real data, light/dark, AR/EN, the
 * subject and sender as an inbox shows them), «أرسل نسخة لي» (to the signed-in user only, marked as a test, outside the
 * log), «أرسل للمستلم الآن» (confirm; through the SAME engine path — checks, review, log — never a shortcut).
 */
final class ExternalPreviewController extends Controller
{
    public function __construct(private readonly ExternalMessageComposer $composer, private readonly TenantContext $tenants) {}

    public function preview(Request $request): JsonResponse
    {
        $tenant = $this->authorize($request);
        $data = $request->validate([
            'subscription_id' => ['required', 'uuid'], 'project_id' => ['nullable', 'uuid'], 'event_key' => ['nullable', 'string', 'max:80'],
            'theme' => ['nullable', 'in:light,dark'], 'locale' => ['nullable', 'in:ar,en'],
        ]);
        $delivery = $this->delivery($tenant, $data, 'PREVIEW');
        $m = $this->composer->preview($delivery, ($data['theme'] ?? 'light') === 'dark' ? 'dark' : 'auto');
        $html = $m->html;
        foreach ($m->inline as $cid => $png) {
            $html = str_replace('cid:'.$cid.'"', 'data:image/png;base64,'.base64_encode($png).'"', $html);
        }

        return ApiResponse::success([
            'subject' => $m->subject, 'sender_name' => $m->senderName, 'reply_to' => $m->replyTo,
            'html' => $html, 'text' => $m->text, 'html_bytes' => strlen($m->html), 'image_bytes' => array_sum(array_map('strlen', $m->inline)),
        ]);
    }

    public function testSend(Request $request, ExternalTransport $transport): JsonResponse
    {
        $tenant = $this->authorize($request);
        $data = $request->validate(['subscription_id' => ['required', 'uuid'], 'project_id' => ['nullable', 'uuid'], 'event_key' => ['nullable', 'string', 'max:80']]);
        $to = (string) $request->user()->email;
        $m = $this->composer->preview($this->delivery($tenant, $data, 'TEST'));
        $prefix = $request->query('lang') === 'en' ? '[Test] ' : '[تجربة] ';
        $email = (new Email)->from(new Address((string) config('mail.from.address'), (string) $m->senderName))->to(new Address($to))
            ->subject($prefix.$m->subject)->html($m->html)->text($m->text);
        foreach ($m->inline as $cid => $png) {
            $email->embed($png, $cid, 'image/png');
        }
        try {
            $transport->send($email, new Envelope(new Address((string) config('mail.from.address')), [new Address($to)]));
        } catch (Throwable $e) {
            return ApiResponse::error(null, ['mail' => [MailFailure::from($e)['safe']]], status: 422);
        }

        return ApiResponse::success(['sent_to' => $to]);
    }

    public function sendNow(Request $request, SubscriptionScheduler $scheduler, NotificationEngine $engine): JsonResponse
    {
        $tenant = $this->authorize($request);
        $data = $request->validate(['subscription_id' => ['required', 'uuid'], 'project_id' => ['required', 'uuid'], 'confirm' => ['accepted']]);
        $row = DB::table('notification_subscriptions')->where('tenant_id', $tenant)->where('id', $data['subscription_id'])->first();
        abort_if($row === null, 404);
        abort_unless(DB::table('notification_subscription_projects')->where('subscription_id', $row->id)->where('project_id', $data['project_id'])->exists(), 422);
        $sub = $scheduler->hydrate($row);
        $period = $scheduler->period($sub, Carbon::now());
        if ($period === null) {
            return ApiResponse::error(null, ['period' => ['No complete day in this period yet.']], status: 422);
        }
        $out = $engine->raise(new DeliveryIntent(
            eventKey: 'digest.periodic', tenantId: $tenant, projectId: (string) $data['project_id'], objectType: 'manual_send', objectId: (string) Str::uuid(),
            threshold: $period['key'].':'.$period['from'].':'.$period['to'], payload: ['period' => $period, 'digest_types' => $sub->digestTypes],
            actorUserId: (int) $request->user()->getKey(), subscriptionId: $sub->id,
        ));

        return ApiResponse::success(['queued' => $out]);
    }

    /** @param array<string, mixed> $data */
    private function delivery(string $tenant, array $data, string $mark): Delivery
    {
        $sub = DB::table('notification_subscriptions')->where('tenant_id', $tenant)->where('id', $data['subscription_id'])->first();
        abort_if($sub === null, 404);
        $r = DB::table('notification_external_recipients')->where('tenant_id', $tenant)->where('id', $sub->recipient_id)->first();
        $projects = DB::table('notification_subscription_projects')->where('subscription_id', $sub->id)->pluck('project_id')->map(static fn ($p): string => (string) $p)->all();
        $project = (string) ($data['project_id'] ?? $projects[0] ?? '');
        abort_unless(in_array($project, $projects, true), 422);
        $event = (string) ($data['event_key'] ?? 'digest.periodic');
        $payload = ['_locale' => $data['locale'] ?? $r->language, '_role' => $r->role_key];
        if ($event === 'digest.periodic' && $sub->period_key !== null) {
            $hydrated = app(SubscriptionScheduler::class)->hydrate($sub);
            $period = app(SubscriptionScheduler::class)->period($hydrated, Carbon::now());
            if ($period !== null) {
                $payload['period'] = $period;
            }
        }

        return new Delivery(
            (string) Str::uuid(), $mark.'-'.strtoupper(Str::random(6)), $tenant, $event, Scope::External, Channel::Email, Priority::Info,
            new Recipient(RecipientKind::External, null, (string) $r->id, (string) $r->email, (string) ($data['locale'] ?? $r->language), (string) $r->role_key),
            $project, (string) $sub->id, $payload, 1, 0,
        );
    }

    private function authorize(Request $request): string
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return (string) $this->tenants->tenantId();
    }
}
