<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    private const STATES = ['failed', 'awaiting_credentials', 'sandbox', 'skipped', 'claimed', 'sent'];

    public function __construct(private readonly ProviderRegistry $providers) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATES)],
            'kind' => ['nullable', 'string', 'max:60'],
            'recipient' => ['nullable', 'string', 'max:190'],
            'source' => ['nullable', 'in:transactional,digest'],
            'days' => ['nullable', 'integer', 'between:1,90'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $since = now()->subDays((int) ($filters['days'] ?? 30));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $rows = $this->transactional($filters, $since)
            ->concat($this->digests($filters, $since))
            ->sortByDesc('at')
            ->values();

        return ApiResponse::success([
            'deliveries' => $rows->forPage($page, self::PER_PAGE)->values()->all(),
            'total' => $rows->count(),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            // Counted over the whole filtered window, not the page — a page of failures out of ten
            // thousand sends is a different situation from ten thousand failures.
            'by_state' => $rows->groupBy('status')->map->count()->all(),
            'transport' => $this->transport(),
            'available_states' => self::STATES,
        ], 'Email delivery ledger retrieved.');
    }

    /**
     * Which messages the gallery can show, and what each one is.
     *
     * The keys come from `MailGallery`, which is also what `notifications:preview` writes — so this
     * page cannot show a message the command does not, or miss one it does.
     */
    public function previews(): JsonResponse
    {
        return ApiResponse::success([
            'keys' => MailGallery::keys(),
            'locales' => ['ar', 'en'],
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

        $html = MailGallery::render($key, $locale);

        abort_if($html === null, 404, "There is no message preview called «{$key}».");

        return ApiResponse::success([
            'key' => $key,
            'locale' => $locale,
            'html' => $html,
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
            'reply_to' => (string) (config('mail.reply_to.address') ?? ''),
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
        $a = DB::table('mail_deliveries')->whereIn('status', $states)->max('created_at');
        $b = DB::table('digest_sends')->whereIn('status', $states)->max('created_at');
        $latest = collect([$a, $b])->filter()->max();

        return $latest === null ? null : Carbon::parse((string) $latest)->toIso8601String();
    }

    /** @return array{at: string, kind: string, error: string}|null */
    private function lastFailure(): ?array
    {
        $row = DB::table('mail_deliveries')->where('status', 'failed')->orderByDesc('created_at')->first(['created_at', 'kind', 'error']);
        if ($row === null) {
            return null;
        }

        // Trimmed to one short line, the same reason the ledger below already shows: a raw transport error can run to pages.
        return [
            'at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            'kind' => (string) $row->kind,
            'error' => Str::limit(preg_replace('/\s+/', ' ', (string) ($row->error ?? '')) ?? '', 80, '…'),
        ];
    }

    /**
     * POST /admin/email/test — one message, to the signed-in operator, through the same ledger.
     *
     * Never to an address in the request: a console that can reach every tenant's recipients must not
     * also be able to mail an arbitrary one. With no provider configured the honest outcome is recorded
     * (`awaiting_credentials`) rather than a pretend success.
     */
    public function test(Request $request, TransactionalMailer $mailer): JsonResponse
    {
        $user = $request->user();
        $lang = in_array($request->input('lang'), ['ar', 'en'], true) ? (string) $request->input('lang') : 'ar';

        $state = $mailer->send(
            recipient: (string) $user->email,
            mail: new OperationalMail(
                kind: 'message', severity: 'info',
                title: $lang === 'ar' ? 'رسالة اختبار من كامبينز هب' : 'A test message from CampaignsHub',
                detail: $lang === 'ar' ? 'وصلتك هذه الرسالة لأن مشرف المنصة طلب اختبار إرسال البريد.' : 'You received this because a platform operator asked to test outgoing mail.',
                context: 'CampaignsHub',
                lang: $lang,
                recipientName: (string) $user->name,
            ),
            kind: 'test',
            template: 'operational',
            locale: $lang,
            userId: (int) $user->id,
            dedupKey: 'test:'.$user->id.':'.now()->format('YmdHi'),
        );

        return ApiResponse::success(['state' => $state, 'recipient' => (string) $user->email], 'Test message recorded.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function transactional(array $filters, Carbon $since)
    {
        if (($filters['source'] ?? null) === 'digest') {
            return collect();
        }

        return DB::table('mail_deliveries as m')
            ->leftJoin('tenants as t', 't.id', '=', 'm.tenant_id')
            ->where('m.created_at', '>=', $since)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('m.status', $s))
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('m.kind', $k))
            ->when($filters['recipient'] ?? null, fn ($q, $r) => $q->where('m.recipient', 'ilike', '%'.$r.'%'))
            ->orderByDesc('m.created_at')
            ->limit(1000)
            ->get([
                'm.id', 'm.kind', 'm.recipient', 'm.locale', 'm.template', 'm.status', 'm.transport',
                'm.attempts', 'm.error', 'm.sent_at', 'm.created_at', 't.name as tenant_name',
            ])
            ->map(static fn (object $r): array => [
                'id' => (string) $r->id,
                'source' => 'transactional',
                'at' => (string) ($r->sent_at ?? $r->created_at),
                'kind' => (string) $r->kind,
                'template' => (string) $r->template,
                'recipient' => (string) $r->recipient,
                'tenant_name' => $r->tenant_name,
                'locale' => (string) $r->locale,
                'status' => (string) $r->status,
                'transport' => (string) $r->transport,
                'attempts' => (int) $r->attempts,
                'reason' => $r->error,
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function digests(array $filters, Carbon $since)
    {
        if (($filters['source'] ?? null) === 'transactional') {
            return collect();
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
                'reason' => $r->reason ?? $r->last_error,
            ]);
    }
}
