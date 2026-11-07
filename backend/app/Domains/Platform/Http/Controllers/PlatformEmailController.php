<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\EnrolMemberNotifications;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Notifications\Support\MailDeliveryState;
use App\Domains\Notifications\Support\MailFailure;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\PlainText;
use App\Domains\Notifications\Support\TestSendMark;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * What this installation's mail has actually done — MAIL-014.
 *
 * ## Two ledgers, because there are two, and an operator asking one question
 *
 * `mail_deliveries` holds transactional messages (MAIL-009) by recipient address; `digest_sends`
 * holds digests and alerts (MAIL-003, MAIL-006) by user and period. They were written separately for
 * good reasons — one is idempotent per period, the other per message — but «is mail working?» is one
 * question, and a console that answered it from one table would report a healthy install while every
 * digest in it was failing. So they are merged here, each row carrying which ledger it came from.
 *
 * ## The transport is stated, not inferred from the rows
 *
 * A screen full of `awaiting_credentials` has two possible readings: nothing is configured, or
 * something is broken. `MailTransportState` answers it directly — `awaiting_credentials`, `sandbox`
 * or `live` — and `sandbox` is the one worth being loud about: the driver works, every send
 * SUCCEEDS, and not one message reaches a human. An operator reading «sent» against a `log` mailer
 * and concluding customers got their invoices is the failure this exists to prevent.
 *
 * ## Read-only, and no message bodies
 *
 * No resend, no delete, no export. A ledger an operator can edit stops being evidence, and a resend
 * button on a console that can reach every tenant's recipients is a way to mail thousands of people
 * by mis-click. The rows carry addresses and subjects' worth of metadata — kind, template, status —
 * and never the rendered body: a delivery log is not an inbox, and reading customers' mail is not
 * what the owner's console is for.
 */
final class PlatformEmailController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * The states either ledger can hold, in the order an operator triages them.
     *
     * `awaiting_provider_credentials` is deliberately absent. It is the older vocabulary on
     * `registration_verifications` and its siblings (see `TransactionalMailer::asDeliveryStatus`),
     * and NEITHER of the two tables read here can hold it — offering it produced a filter with two
     * options reading «بانتظار بيانات الاعتماد», one of which matched nothing. Found by opening the
     * page.
     */
    private const STATES = ['failed', 'awaiting_credentials', 'sandbox', 'skipped', 'claimed', 'sent', 'queued', 'suppressed', 'unsubscribed', 'limit_reached', 'repeat_limit', 'refused_scope'];

    public function __construct(private readonly ProviderRegistry $providers) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATES)],
            'state' => ['nullable', Rule::in(MailDeliveryState::ALL)],
            'kind' => ['nullable', 'string', 'max:60'],
            'recipient' => ['nullable', 'string', 'max:190'],
            'source' => ['nullable', 'in:transactional,digest,subscription,test'],
            'days' => ['nullable', 'integer', 'between:1,90'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $since = now()->subDays((int) ($filters['days'] ?? 30));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $rows = collect($this->transactional($filters, $since))
            ->concat($this->digests($filters, $since))
            ->concat($this->subscriptions($filters, $since))
            // Filtered after the translation, because «accepted» and «delivered» share one stored word.
            ->when($filters['state'] ?? null, fn ($rows, $state) => $rows->where('state', $state))
            ->sortByDesc('at')
            ->values();

        return ApiResponse::success([
            'deliveries' => $rows->forPage($page, self::PER_PAGE)->values()->all(),
            'total' => $rows->count(),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            // Counted over the whole filtered window, not the page — a page of failures out of ten
            // thousand sends is a different situation from ten thousand failures.
            // EMAIL-TEST-SEND-001 — an admin's own tests are counted apart, so they cannot read as real mail working.
            'by_state' => $rows->reject(fn (array $row): bool => $row['is_test'] ?? false)->groupBy('state')->map->count()->all(),
            'test_sends' => $rows->filter(fn (array $row): bool => $row['is_test'] ?? false)->count(),
            'transport' => $this->transport(),
            'available_states' => MailDeliveryState::ALL,
            // NOTIFICATION-ENROLMENT-001 — «is it effective?» answered from the product, not from a query nobody remembers.
            'reach' => $this->reach(),
        ], 'Email delivery ledger retrieved.');
    }

    /**
     * Who the notification system can reach at all, and when each digest last actually went out.
     *
     * Staff members only (the same population enrolment uses), counted per workspace they belong to. `enrolled` is a person
     * with a preferences row — the thing every sender is driven from — and `digest_on` is the part of them with at least one
     * periodic digest switched on. `last_digest` is the newest SCHEDULED send per rhythm: an on-demand «send me this now»
     * is ledgered under another kind and must never make the scheduler look like it ran.
     *
     * @return array{members: int, verified: int, enrolled: int, digest_on: int, last_digest: array<string, ?string>}
     */
    private function reach(): array
    {
        $rows = DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('notification_preferences as p', fn ($j) => $j->on('p.tenant_id', '=', 'm.tenant_id')->on('p.user_id', '=', 'm.user_id'))
            ->where('m.status', 'active')->whereIn('m.portal', EnrolMemberNotifications::STAFF_PORTALS)
            ->where('u.is_platform_admin', false)->whereNull('u.disabled_at')
            ->get(['m.tenant_id', 'm.user_id', 'u.email_verified_at', 'p.id as pref_id', 'p.digests'])
            ->unique(fn (object $r): string => $r->tenant_id.':'.$r->user_id);

        $enrolled = $rows->filter(fn (object $r): bool => $r->pref_id !== null);
        $digestOn = $enrolled->filter(function (object $r): bool {
            $chosen = (array) json_decode((string) ($r->digests ?? '[]'), true);

            return ($chosen['daily'] ?? false) === true || ($chosen['weekly'] ?? false) === true || ($chosen['monthly'] ?? false) === true;
        });

        $last = [];
        foreach (['daily', 'weekly', 'monthly'] as $kind) {
            $at = DB::table('digest_sends')->where('kind', $kind)->where('status', 'sent')->max('sent_at');
            $last[$kind] = $at === null ? null : Carbon::parse((string) $at)->toIso8601String();
        }

        return [
            'members' => $rows->count(),
            'verified' => $rows->filter(fn (object $r): bool => $r->email_verified_at !== null)->count(),
            'enrolled' => $enrolled->count(),
            'digest_on' => $digestOn->count(),
            'last_digest' => $last,
        ];
    }

    /**
     * Which messages the gallery can show, and what each one is.
     *
     * The keys come from `MailGallery`, which is also what `notifications:preview` writes — so this
     * page cannot show a message the command does not, or miss one it does.
     */
    public function previews(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'keys' => MailGallery::keys(),
            'locales' => ['ar', 'en'],
            // EMAIL-TEST-SEND-001 — where a test would go, so the page can say so before anybody presses anything.
            // EMAIL-TEST-SEND-002 — and the platform's own mailbox, the other of the two places a test may go.
            'test_send' => [
                'recipient' => (string) $user->email,
                'address_proven' => $user->email_verified_at !== null,
                'platform_mailbox' => self::platformMailbox(),
            ],
        ], 'Available previews retrieved.');
    }

    /**
     * One rendered message, as HTML.
     *
     * Returned as a string in JSON rather than served as a document: the caller puts it in a sandboxed
     * frame, and an endpoint that returned `text/html` from the same origin would be a way to render
     * arbitrary markup under the console's own domain.
     */
    public function preview(Request $request, string $key): JsonResponse
    {
        $locale = $request->query('locale') === 'en' ? 'en' : 'ar';

        $mail = MailGallery::messages($locale)[$key] ?? null;

        abort_if($mail === null, 404, "There is no message preview called «{$key}».");

        $html = $mail->render();

        return ApiResponse::success([
            'key' => $key,
            'locale' => $locale,
            'html' => $html,
            // §17.4.5 — what a review needs beside the picture: what the inbox shows, what a text-only
            // client shows, and whether the reader could have switched this message off.
            'subject' => method_exists($mail, 'envelope') ? (string) $mail->envelope()->subject : '',
            'test_subject' => TestSendMark::subject($mail, $locale),
            'text' => PlainText::fromHtml($html),
            'mandatory' => str_starts_with($key, 'account-'),
        ], 'Preview rendered.');
    }

    /**
     * What this install can actually do with an email.
     *
     * @return array{state: string, provider_configured: bool, driver: string}
     */
    private function transport(): array
    {
        return [
            'state' => MailTransportState::current(),
            'provider_configured' => $this->providers->isConfigured('email'),
            'driver' => (string) config('mail.default', 'log'),
            /*
             * EMAIL-SETTINGS-PAGE-001 — who the mail says it is from, and when it last worked or failed.
             *
             * Addresses and timestamps only. No host, port, username, password or key: this payload is a
             * diagnosis, and a settings screen that echoes a credential is a credential leak with a
             * friendly face.
             */
            'sender' => [
                'address' => (string) config('mail.from.address', ''),
                'name' => (string) config('mail.from.name', ''),
            ],
            'reply_to' => self::platformMailbox() ?? '',
            'last_success_at' => $this->lastAt(['sent']),
            'last_failure' => $this->lastFailure(),
        ];
    }

    /**
     * The newest time a message reached one of the given states, over both ledgers.
     *
     * @param  list<string>  $states
     */
    private function lastAt(array $states): ?string
    {
        // A test is not «the last real send»; the ledger below shows it under its own name.
        $a = DB::table('mail_deliveries')->where('kind', '!=', TransactionalMailer::TEST_KIND)->whereIn('status', $states)->max('created_at');
        $b = DB::table('digest_sends')->whereIn('status', $states)->max('created_at');
        $c = DB::table('subscription_notifications')->whereIn('status', $states)->max('created_at');
        $latest = collect([$a, $b, $c])->filter()->max();

        return $latest === null ? null : Carbon::parse((string) $latest)->toIso8601String();
    }

    /** @return array{at: string, kind: string, error: string, failure_code: ?string}|null */
    private function lastFailure(): ?array
    {
        $row = DB::table('mail_deliveries')->where('kind', '!=', TransactionalMailer::TEST_KIND)->where('status', 'failed')->orderByDesc('created_at')->first(['created_at', 'kind', 'error', 'failure_code']);
        if ($row === null) {
            return null;
        }

        // Trimmed to one short line, the same reason the ledger below already shows: a raw transport error can run to pages.
        return [
            'at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            'kind' => (string) $row->kind,
            'error' => Str::limit(MailFailure::redact((string) ($row->error ?? '')), 80, '…'),
            'failure_code' => $row->failure_code,
        ];
    }

    /**
     * The platform's own mailbox: the configured Reply-To, which is where a human reads this product's mail.
     *
     * One reader for the settings row, the test target and the page's chip, so the address a test goes to
     * is always the address the page shows as «الرد إلى».
     */
    private static function platformMailbox(): ?string
    {
        $address = trim((string) (config('mail.reply_to.address') ?? ''));

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : $address;
    }

    /**
     * POST /admin/email/test — EMAIL-TEST-SEND-001 (§17.4.5), EMAIL-TEST-SEND-002.
     *
     * One message, through the one mailer and its ledger, to one of two addresses the server knows.
     *
     * - **Who.** The `platform` gate on the route; a workspace has no way to reach this.
     * - **Where to.** `to` is a choice of two words, never an address: `self` (the default) is `$user->email`,
     *   and only when `email_verified_at` says it was proved; `platform_mailbox` is the configured Reply-To.
     *   The second exists because a sign-in address need not have a mailbox behind it (Owner, Production
     *   2026-11-06: «عندي انفو فقط»), and an accepted message nobody can open proves nothing. Nothing else
     *   in the request is read, so this cannot be used to mail a customer.
     * - **What.** A gallery fixture marked by `TestSendMark`. No tenant, no database read.
     * - **What it proves.** The answer is what the transport said. `accepted` is not delivery, and the
     *   only thing that makes a row `delivered` is `mail:confirm-delivery` with evidence.
     * - **Trace.** Audited, with the target named and the address masked, whatever the outcome.
     */
    public function test(Request $request, TransactionalMailer $mailer, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'lang' => ['nullable', 'in:ar,en'],
            'key' => ['nullable', 'string', 'max:60'],
            'to' => ['nullable', 'string', Rule::in(['self', 'platform_mailbox'])],
        ]);
        $lang = (string) ($data['lang'] ?? 'ar');
        $key = $data['key'] ?? null;
        $target = (string) ($data['to'] ?? 'self');
        $template = 'gallery.'.($key ?? 'plain');

        $mail = MailGallery::testMessage($key, $lang);
        abort_if($mail === null, 422, 'There is no such message.');

        $recipient = $target === 'platform_mailbox' ? self::platformMailbox() : (string) $user->email;

        if ($recipient === null) {
            $audit->log(action: 'mail.test_send.refused', after: ['template' => $template, 'locale' => $lang, 'target' => $target], reason: 'platform_mailbox_not_configured');

            return ApiResponse::error('No Reply-To mailbox is configured, so there is no platform mailbox to test.', ['code' => 'platform_mailbox_not_configured'], status: 422);
        }

        $masked = MailAddress::mask($recipient);

        // The proof is about the admin's own address; the platform's mailbox is the platform's.
        if ($target === 'self' && $user->email_verified_at === null) {
            $audit->log(action: 'mail.test_send.refused', after: ['template' => $template, 'locale' => $lang, 'target' => $target, 'recipient' => $masked], reason: 'address_not_proven');

            return ApiResponse::error('Your address has not been verified, so no test can be sent to it.', ['code' => 'address_not_proven'], status: 422);
        }

        // `self` keeps the key it always had; the other target is another message, not a repeat of it.
        $dedupKey = 'test:'.$user->id.':'.($target === 'self' ? '' : $target.':').($key ?? 'plain').':'.$lang.':'.now()->format('YmdHi');
        $state = $mailer->send(
            recipient: $recipient,
            mail: $mail,
            kind: TransactionalMailer::TEST_KIND,
            template: $template,
            locale: $lang,
            userId: (int) $user->id,
            dedupKey: $dedupKey,
        );

        // The row holds what the transport answered. `already_sent` reads the attempt it repeats.
        $row = DB::table('mail_deliveries')->where('dedup_key', $dedupKey)->first();
        $stored = (string) ($row->status ?? $state);
        $answer = [
            'delivery_state' => MailDeliveryState::of($stored, $row->delivered_at ?? null),
            'suppression_reason' => MailDeliveryState::suppressionReason($stored),
            'failure_code' => $row->failure_code ?? null,
            'provider_message_id' => $row->provider_message_id ?? null,
        ];

        $audit->log(
            action: 'mail.test_send',
            entityType: 'mail_delivery',
            entityId: $row === null ? null : (string) $row->id,
            after: ['template' => $template, 'locale' => $lang, 'target' => $target, 'recipient' => $masked, 'state' => $state] + $answer,
        );

        return ApiResponse::success([
            'state' => $state,
            'delivery_id' => $row === null ? null : (string) $row->id,
            'to' => $target,
            'recipient' => $recipient,
            'transport' => $row->transport ?? null,
        ] + $answer, 'Test message recorded.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function transactional(array $filters, Carbon $since): array
    {
        if (($filters['source'] ?? null) === 'digest') {
            return [];
        }

        return DB::table('mail_deliveries as m')
            ->leftJoin('tenants as t', 't.id', '=', 'm.tenant_id')
            ->where('m.created_at', '>=', $since)
            ->when(($filters['source'] ?? null) === 'test', fn ($q) => $q->where('m.kind', TransactionalMailer::TEST_KIND))
            ->when(($filters['source'] ?? null) === 'transactional', fn ($q) => $q->where('m.kind', '!=', TransactionalMailer::TEST_KIND))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('m.status', $s))
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('m.kind', $k))
            ->when($filters['recipient'] ?? null, fn ($q, $r) => $q->where('m.recipient', 'ilike', '%'.$r.'%'))
            ->orderByDesc('m.created_at')
            ->limit(1000)
            ->get([
                'm.id', 'm.kind', 'm.recipient', 'm.locale', 'm.template', 'm.status', 'm.transport',
                'm.attempts', 'm.error', 'm.sent_at', 'm.created_at', 't.name as tenant_name',
                'm.failure_code', 'm.delivered_at', 'm.delivery_evidence', 'm.next_attempt_at', 'm.provider_message_id',
            ])
            ->map(static fn (object $r): array => [
                'id' => (string) $r->id,
                'source' => 'transactional',
                'at' => (string) ($r->sent_at ?? $r->created_at),
                'kind' => (string) $r->kind,
                'template' => (string) $r->template,
                // A test is the admin's own address in a list other admins read. The audit trail masks it too.
                'recipient' => (string) $r->kind === TransactionalMailer::TEST_KIND ? MailAddress::mask((string) $r->recipient) : (string) $r->recipient,
                'is_test' => (string) $r->kind === TransactionalMailer::TEST_KIND,
                'tenant_name' => $r->tenant_name,
                'locale' => (string) $r->locale,
                'status' => (string) $r->status,
                'transport' => (string) $r->transport,
                'attempts' => (int) $r->attempts,
                // Redacted here too: rows written before MailFailure existed still hold the transport's words.
                'reason' => $r->error === null ? null : MailFailure::redact((string) $r->error),
            ] + self::stage($r))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function digests(array $filters, Carbon $since): array
    {
        if (in_array($filters['source'] ?? null, ['transactional', 'test'], true)) {
            return [];
        }

        return DB::table('digest_sends as d')
            ->leftJoin('tenants as t', 't.id', '=', 'd.tenant_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.created_at', '>=', $since)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('d.status', $s))
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('d.kind', $k))
            ->when($filters['recipient'] ?? null, fn ($q, $r) => $q->where('u.email', 'ilike', '%'.$r.'%'))
            ->orderByDesc('d.created_at')
            ->limit(1000)
            ->get([
                'd.id', 'd.kind', 'd.status', 'd.reason', 'd.attempts', 'd.last_error', 'd.sent_at',
                'd.created_at', 'd.period_key', 't.name as tenant_name', 'u.email as recipient',
                'd.failure_code', 'd.delivered_at', 'd.delivery_evidence', 'd.next_attempt_at', 'd.provider_message_id',
            ])
            ->map(static fn (object $r): array => [
                'id' => (string) $r->id,
                'source' => 'digest',
                'at' => (string) ($r->sent_at ?? $r->created_at),
                'kind' => (string) $r->kind,
                // The digest ledger has no template column: what it renders is decided by its kind.
                'template' => (string) $r->kind === 'alert' ? 'alerts' : 'digest',
                'recipient' => $r->recipient,
                'tenant_name' => $r->tenant_name,
                'locale' => null,
                'status' => (string) $r->status,
                'transport' => null,
                'attempts' => (int) $r->attempts,
                'reason' => ($r->last_error ?? $r->reason) === null ? null : MailFailure::redact((string) ($r->last_error ?? $r->reason)),
            ] + self::stage($r))
            ->values()
            ->all();
    }

    /**
     * Subscription and account mail — it has its own table and its own queue, and «is mail working?»
     * could not see it, so an install could fail every renewal notice with this page showing green.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function subscriptions(array $filters, Carbon $since): array
    {
        if (in_array($filters['source'] ?? null, ['transactional', 'digest', 'test'], true)) {
            return [];
        }

        return DB::table('subscription_notifications as n')
            ->leftJoin('tenants as t', 't.id', '=', 'n.tenant_id')
            ->where('n.created_at', '>=', $since)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('n.status', $s))
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('n.event', $k))
            ->when($filters['recipient'] ?? null, fn ($q, $r) => $q->where('n.to_email', 'ilike', '%'.$r.'%'))
            ->orderByDesc('n.created_at')
            ->limit(1000)
            ->get([
                'n.id', 'n.event', 'n.to_email', 'n.locale', 'n.status', 'n.attempts', 'n.error', 'n.sent_at', 'n.created_at', 't.name as tenant_name',
                'n.failure_code', 'n.delivered_at', 'n.delivery_evidence', 'n.provider_message_id',
            ])
            ->map(static fn (object $r): array => [
                'id' => (string) $r->id,
                'source' => 'subscription',
                'at' => (string) ($r->sent_at ?? $r->created_at),
                'kind' => (string) $r->event,
                'template' => 'mail.operational',
                'recipient' => (string) $r->to_email,
                'tenant_name' => $r->tenant_name,
                'locale' => (string) $r->locale,
                'status' => (string) $r->status,
                'transport' => null,
                'attempts' => (int) $r->attempts,
                'reason' => $r->error === null ? null : MailFailure::redact((string) $r->error),
            ] + self::stage($r))
            ->values()
            ->all();
    }

    /**
     * What every row adds to its stored status: the stage a reader sees, why it stopped, and the proof.
     *
     * @return array<string, mixed>
     */
    private static function stage(object $r): array
    {
        return [
            'state' => MailDeliveryState::of((string) $r->status, $r->delivered_at),
            'suppression_reason' => MailDeliveryState::suppressionReason((string) $r->status),
            'failure_code' => $r->failure_code,
            'evidence' => $r->delivery_evidence,
            'next_attempt_at' => $r->next_attempt_at ?? null,
            'provider_message_id' => $r->provider_message_id,
        ];
    }
}
