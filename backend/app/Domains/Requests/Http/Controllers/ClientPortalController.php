<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Quote;
use App\Domains\Billing\Providers\PaymentProviderRegistry;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Messaging\Services\MessagingService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Requests\Journey\RequestStage;
use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestComment;
use App\Domains\Requests\Models\RequestEvent;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Requests\Services\PortalTenantResolver;
use App\Domains\Requests\Services\RequestJourneyService;
use App\Domains\Requests\Services\RequestUploadAttacher;
use App\Domains\Taxonomy\Services\PaidServiceCatalog;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * External Client Portal — a single place for a verified client to see ALL their requests, track status,
 * exchange messages and files, and see the resulting project/campaign after conversion. Auth is a verified
 * contact (OTP) exchanged for an httpOnly-cookie session (never a localStorage token). Every payload is
 * client-safe: internal notes, internal SLA, assignee, tenant ids and audit metadata are never exposed.
 */
final class ClientPortalController
{
    private const COOKIE = 'client_portal';

    public function __construct(
        private readonly ContactVerificationService $verification,
        private readonly PortalTenantResolver $portal,
        private readonly RequestUploadAttacher $uploads,
        private readonly BillingService $billing,
        private readonly MessagingService $messaging,
        private readonly RequestJourneyService $journey,
        private readonly PaymentProviderRegistry $providers,
        private readonly TenantContext $tenant,
        private readonly MetricsAggregator $metrics,
        private readonly PaidServiceCatalog $paidServices,
    ) {}

    /** POST /client/login/start — send an OTP to the client's phone or email (portal login). */
    public function loginStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['sms', 'whatsapp', 'email'])],
            'destination' => ['required', 'string', 'max:190'],
        ]);
        $destination = $this->normalize($data['channel'], $data['destination']);
        $tenant = $this->portal->resolve($request);
        $result = $this->verification->start($data['channel'], $destination, 'portal_login', $tenant ? (string) $tenant->id : null);

        return response()->json(['data' => [
            'verification_id' => $result['id'],
            'delivery_status' => $result['delivery_status'],
            'dev_code' => $result['dev_code'],
        ]], 201);
    }

    /** POST /client/login/verify — verify the OTP and open a portal session (httpOnly cookie). */
    public function loginVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);
        $v = $this->verification->verify($data['verification_id'], $data['code']);
        abort_unless($v->purpose === 'portal_login', 422);

        $tenant = $this->portal->resolve($request);
        abort_if($tenant === null, 404);

        $plain = Str::random(48);
        ClientPortalToken::create([
            'tenant_id' => $tenant->id,
            'token_hash' => hash('sha256', $plain),
            'contact_email' => $v->channel === 'email' ? $v->destination : null,
            'contact_phone' => $v->channel !== 'email' ? $v->destination : null,
            'expires_at' => Carbon::now()->addDays((int) config('requests.verification.portal_session_days', 14)),
        ]);

        $minutes = (int) config('requests.verification.portal_session_days', 14) * 24 * 60;

        // The browser uses the httpOnly cookie (auto-sent, never in JS/localStorage). Non-production ONLY also
        // returns the raw token so tests/tooling can authenticate via the X-Client-Token header. HARD-gated:
        // never in production, so the token never leaves the httpOnly cookie there.
        $devToken = ContactVerificationService::exposeDevSecrets() ? $plain : null;

        // Secure only in production (over HTTPS); http://localhost dev + tests must keep the cookie.
        return response()->json(['data' => ['authenticated' => true, 'contact' => $v->destination, 'dev_token' => $devToken]])
            ->cookie(self::COOKIE, $plain, $minutes, '/', null, app()->environment('production'), true, false, 'lax');
    }

    /** GET /client/session — who am I (client-safe). */
    public function session(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);

        return response()->json(['data' => [
            'contact_email' => $token->contact_email,
            'contact_phone' => $token->contact_phone,
        ]]);
    }

    /** POST /client/logout */
    public function logout(Request $request): JsonResponse
    {
        $token = $this->resolveSession($request);
        $token?->forceFill(['revoked_at' => Carbon::now()])->save();

        return response()->json(['data' => ['ok' => true]])->withoutCookie(self::COOKIE);
    }

    /** GET /client/requests — all of the verified client's requests (client-safe cards). */
    public function index(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);

        $requests = $this->contactScope($token)
            ->with(['type', 'status'])->orderByDesc('submitted_at')->get()
            ->map(fn (ExternalRequest $r) => [
                'reference' => $r->reference,
                'type' => $r->type->name_en,
                'type_ar' => $r->type->name_ar,
                'status' => $r->status->is_client_visible ? $r->status->key : 'in_progress',
                'status_label' => $r->status->is_client_visible ? $r->status->name_en : 'In progress',
                'progress' => $this->progress($r->status->key),
                'submitted_at' => optional($r->submitted_at)->toIso8601String(),
                'updated_at' => optional($r->updated_at)->toIso8601String(),
            ]);

        return response()->json(['data' => ['requests' => $requests]]);
    }

    /** GET /client/requests/{reference} — client-safe detail + resulting project/campaign after conversion. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $token = $this->requireSession($request);
        $req = $this->resolveOwnedRequest($token, $reference);

        $timeline = $req->events()->where('is_client_visible', true)->orderBy('created_at')
            ->get(['type', 'to_status', 'message', 'created_at'])
            ->map(fn (RequestEvent $e) => ['type' => $e->type, 'status' => $e->to_status, 'message' => $e->message, 'at' => optional($e->created_at)->toIso8601String()]);

        $comments = $req->comments()->where('visibility', 'client')->orderBy('created_at')
            ->get(['author_label', 'body', 'created_at'])
            ->map(fn (RequestComment $c) => ['author' => $c->author_label ?? 'Team', 'body' => $c->body, 'at' => optional($c->created_at)->toIso8601String()]);

        $files = $req->files()->where('is_client_visible', true)->whereNotNull('request_id')
            ->get(['id', 'original_name', 'size'])
            ->map(fn (RequestFile $f) => ['id' => $f->id, 'name' => $f->original_name, 'size' => $f->size]);

        return response()->json(['data' => [
            'reference' => $req->reference,
            'type' => $req->type->name_en,
            'type_ar' => $req->type->name_ar,
            'services' => $this->paidServices->resolve($this->selectedServiceKeys($req)),
            'status' => $req->status->is_client_visible ? $req->status->key : 'in_progress',
            'status_label' => $req->status->is_client_visible ? $req->status->name_en : 'In progress',
            'progress' => $this->progress($req->status->key),
            'submitted_at' => optional($req->submitted_at)->toIso8601String(),
            'updated_at' => optional($req->updated_at)->toIso8601String(),
            'timeline' => $timeline,
            'comments' => $comments,
            'files' => $files,
            'result' => $this->conversionResult($req),
            'notifications' => $this->notificationLog($req),
        ]]);
    }

    /**
     * Client-safe view of the notifications we (attempted to) send for this request — honest delivery state.
     *
     * @return list<array<string,mixed>>
     */
    private function notificationLog(ExternalRequest $req): array
    {
        return DB::table('client_notifications')->where('request_id', $req->id)
            ->orderByDesc('created_at')->limit(50)
            ->get(['event', 'channel', 'status', 'created_at'])
            ->map(fn ($n) => [
                'event' => $n->event,
                'channel' => $n->channel,
                'status' => $n->status, // awaiting_provider_credentials until a provider is wired
                'at' => $n->created_at,
            ])->all();
    }

    /** POST /client/requests/{reference}/reply — client message (+ optional files). Always client-visible. */
    public function reply(Request $request, string $reference): JsonResponse
    {
        $token = $this->requireSession($request);
        $req = $this->resolveOwnedRequest($token, $reference);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'upload_token' => ['nullable', 'string'],
        ]);

        $req->comments()->create(['visibility' => 'client', 'author_label' => 'Client', 'body' => $data['message']]);
        $req->events()->create(['type' => 'comment', 'is_client_visible' => true, 'message' => 'Client added a message', 'created_at' => now()]);
        if (! empty($data['upload_token'])) {
            $this->uploads->attach($req, $data['upload_token']);
        }

        return response()->json(['data' => ['status' => 'received']], 201);
    }

    /** GET /client/requests/{reference}/files/{file}/download — secure, client-visible only. */
    public function download(Request $request, string $reference, int $file): StreamedResponse
    {
        $token = $this->requireSession($request);
        $req = $this->resolveOwnedRequest($token, $reference);
        /** @var RequestFile|null $record */
        $record = $req->files()->where('is_client_visible', true)->find($file);
        abort_if($record === null, 404);

        return Storage::disk($record->disk)->download($record->path, $record->original_name);
    }

    // ============================================================================================
    // Client-facing Billing, Messaging and Journey — every query is scoped to the workspaces this
    // verified client owns (derived from their own requests) AND to the token's tenant. Fail-closed:
    // a client with no owned workspace sees nothing, and an unowned resource id is a non-revealing 404.
    // These reuse the SAME session guard (requireSession) as the existing /client endpoints.
    // ============================================================================================

    /** GET /client/quotes — the client's quotes (client-safe). */
    public function quotes(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $ids = $this->ownedWorkspaceIds($token);

        $quotes = $ids === [] ? collect() : Quote::query()
            ->whereIn('client_workspace_id', $ids)
            ->latest('created_at')->limit(200)->get()
            ->map(fn (Quote $q) => $this->quoteShape($q));

        return response()->json(['data' => ['quotes' => $quotes]]);
    }

    /** GET /client/quotes/{quote} — a single owned quote (client-safe). */
    public function showQuote(Request $request, string $quote): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);

        return response()->json(['data' => ['quote' => $this->quoteShape($this->ownedQuote($token, $quote))]]);
    }

    /** POST /client/quotes/{quote}/approve — client approves → BillingService issues the invoice. */
    public function approveQuote(Request $request, string $quote): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $model = $this->ownedQuote($token, $quote);

        try {
            $invoice = $this->billing->approveQuote($model);
        } catch (InvalidArgumentException $e) {
            return response()->json(['data' => ['error' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => [
            'quote' => $this->quoteShape($model->refresh()),
            'invoice' => $this->invoiceShape($invoice),
        ]], 201);
    }

    /** POST /client/quotes/{quote}/reject — client rejects a still-open quote. */
    public function rejectQuote(Request $request, string $quote): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $model = $this->ownedQuote($token, $quote);

        if (! in_array($model->status, ['draft', 'sent'], true)) {
            return response()->json(['data' => ['error' => "Quote cannot be rejected from status [{$model->status}]."]], 422);
        }
        $model->update(['status' => 'rejected']);

        return response()->json(['data' => ['quote' => $this->quoteShape($model->refresh())]]);
    }

    /** GET /client/invoices — the client's invoices with payment status (client-safe). */
    public function invoices(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $ids = $this->ownedWorkspaceIds($token);

        $invoices = $ids === [] ? collect() : Invoice::query()
            ->whereIn('client_workspace_id', $ids)
            ->latest('created_at')->limit(200)->get()
            ->map(fn (Invoice $i) => $this->invoiceShape($i));

        return response()->json(['data' => ['invoices' => $invoices]]);
    }

    /** GET /client/invoices/{invoice} — a single owned invoice (client-safe). */
    public function showInvoice(Request $request, string $invoice): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);

        return response()->json(['data' => ['invoice' => $this->invoiceShape($this->ownedInvoice($token, $invoice))]]);
    }

    /**
     * POST /client/invoices/{invoice}/pay — open a payment via BillingService. With the Null provider the
     * payment stays pending and the client sees an honest `awaiting_provider_credentials` state — never a fake
     * success. NOTHING is marked paid here (only a verified webhook settles).
     */
    public function payInvoice(Request $request, string $invoice): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $model = $this->ownedInvoice($token, $invoice);

        try {
            $payment = $this->billing->startPayment($model);
        } catch (InvalidArgumentException $e) {
            return response()->json(['data' => ['error' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => ['payment' => $this->paymentShape($payment)]], 201);
    }

    /** GET /client/messages — the client's message threads (client-safe cards + client-side unread). */
    public function messages(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $ids = $this->ownedWorkspaceIds($token);

        $threads = $ids === [] ? collect() : MessageThread::query()
            ->whereIn('client_workspace_id', $ids)
            ->orderByDesc('last_message_at')->limit(200)->get()
            ->map(fn (MessageThread $t) => $this->threadShape($t));

        return response()->json(['data' => ['threads' => $threads]]);
    }

    /** GET /client/messages/{thread} — an owned thread with its messages; marks the client side read. */
    public function showThread(Request $request, string $thread): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $model = $this->ownedThread($token, $thread);

        $messages = $model->messages()->orderBy('created_at')->limit(500)->get()
            ->map(fn (Message $m) => $this->messageShape($m));
        $this->messaging->markRead($model, 'client');

        return response()->json(['data' => [
            'thread' => $this->threadShape($model->refresh()),
            'messages' => $messages,
        ]]);
    }

    /** POST /client/messages — open a new thread from the client side (author_type=client). */
    public function openThread(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $ids = $this->ownedWorkspaceIds($token);
        abort_if($ids === [], 422, 'No client workspace is available to open a conversation.');

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:2', 'max:200'],
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'client_workspace_id' => ['nullable', 'uuid'],
        ]);

        // Only a workspace the client actually owns may anchor the thread; default to their first workspace.
        $workspaceId = isset($data['client_workspace_id']) && in_array($data['client_workspace_id'], $ids, true)
            ? $data['client_workspace_id']
            : $ids[0];

        $thread = $this->messaging->openThread([
            'tenant_id' => $token->tenant_id,
            'client_workspace_id' => $workspaceId,
            'subject' => $data['subject'],
        ]);
        $this->messaging->postMessage($thread, 'client', $data['body']);

        return response()->json(['data' => ['thread' => $this->threadShape($thread->refresh())]], 201);
    }

    /** POST /client/messages/{thread} — post a client reply into an owned thread. */
    public function postThreadMessage(Request $request, string $thread): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $model = $this->ownedThread($token, $thread);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:20000'],
        ]);

        $message = $this->messaging->postMessage($model, 'client', $data['body']);

        return response()->json(['data' => ['message' => $this->messageShape($message)]], 201);
    }

    /** GET /client/requests/{reference}/journey — the client's request stage + allowed next client actions. */
    public function journey(Request $request, string $reference): JsonResponse
    {
        $token = $this->requireSession($request);
        $req = $this->resolveOwnedRequest($token, $reference);
        $stage = $this->journey->currentStage($req);

        return response()->json(['data' => [
            'reference' => $req->reference,
            'stage' => $stage->value,
            'stage_label' => $stage->label(),
            'progress' => $this->progress($req->status->key),
            'is_terminal' => $stage->isTerminal(),
            'client_actions' => $this->clientActionsFor($stage),
        ]]);
    }

    // ============================================================================================
    // Client-facing Files / Campaigns / Reports — read-only, client-safe, and scoped EXACTLY like the
    // other /client endpoints: to the workspaces this verified client owns (derived from their own
    // requests) AND to the token's tenant. Fail-closed: a client with no owned workspace sees nothing,
    // and cross-client resources are never returned (empty, never another client's data).
    // ============================================================================================

    /**
     * GET /client/files — every file the client is allowed to see: client-visible files uploaded on their OWN
     * requests, plus read-only Drive file METADATA for folders linked to their workspaces / their projects /
     * their campaigns. Client-safe shapes only — the storage disk/path and Drive link ids are never exposed.
     */
    public function files(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);

        // 1) Client-visible uploads on the client's own requests.
        $requests = (clone $this->contactScope($token))->get(['id', 'reference']);
        $referenceById = $requests->pluck('reference', 'id');
        $requestIds = $requests->pluck('id')->all();

        $requestFiles = $requestIds === [] ? collect() : RequestFile::query()
            ->whereIn('request_id', $requestIds)
            ->where('is_client_visible', true)
            ->orderByDesc('created_at')->get(['id', 'request_id', 'original_name', 'mime', 'size', 'created_at'])
            ->map(fn (RequestFile $f) => [
                'id' => (string) $f->id,
                'source' => 'request',
                'name' => $f->original_name,
                'mime' => $f->mime,
                'size' => $f->size,
                'request_reference' => $referenceById[$f->request_id] ?? null,
                'created_at' => optional($f->created_at)->toIso8601String(),
            ]);

        // 2) Read-only Drive file metadata linked to the client's workspaces/projects/campaigns.
        $driveFiles = $this->clientDriveFiles($token);

        return response()->json(['data' => [
            'files' => $requestFiles->values()->concat($driveFiles->values())->all(),
        ]]);
    }

    /**
     * GET /client/campaigns — the campaigns in the client's own workspace projects (read-only, client-safe).
     * NO internal cost fields (budget/spend/revenue/roas/cpa/cpc/cpm) — only high-level delivery metrics.
     */
    public function campaigns(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $ids = $this->ownedWorkspaceIds($token);

        $campaigns = $ids === [] ? collect() : UnifiedCampaign::query()
            ->whereIn('client_workspace_id', $ids)
            ->orderByDesc('created_at')->limit(200)->get()
            ->map(fn (UnifiedCampaign $c) => $this->campaignShape($c));

        return response()->json(['data' => ['campaigns' => $campaigns->values()->all()]]);
    }

    /**
     * GET /client/reports — reports permitted to the client: only CLIENT-audience reports that belong to the
     * client's own projects AND have an ACTIVE secure share. Internal/executive reports are NEVER returned.
     */
    public function reports(Request $request): JsonResponse
    {
        $token = $this->requireSession($request);
        $this->bindTenant($token);
        $projectIds = $this->ownedProjectIds($token);
        if ($projectIds === []) {
            return response()->json(['data' => ['reports' => []]]);
        }

        // Reports that currently have at least one active (non-revoked, non-expired) secure share.
        $sharedReportIds = $this->activeShareQuery()->pluck('report_id')
            ->map(fn ($id): string => (string) $id)->unique()->all();
        if ($sharedReportIds === []) {
            return response()->json(['data' => ['reports' => []]]);
        }

        $reports = Report::query()
            ->whereIn('project_id', $projectIds)
            ->where('audience', 'client') // internal/executive reports are never client-facing
            ->whereIn('id', $sharedReportIds)
            ->orderByDesc('created_at')->limit(200)->get()
            ->map(fn (Report $r) => $this->reportShape($r));

        return response()->json(['data' => ['reports' => $reports->values()->all()]]);
    }

    // ---- client-facing scoping + shapes ----

    /**
     * The project ids belonging to the workspaces this verified client owns. Fail-closed: no owned workspace
     * → no projects → no reports/drive scope.
     *
     * @return list<string>
     */
    private function ownedProjectIds(ClientPortalToken $token): array
    {
        $workspaceIds = $this->ownedWorkspaceIds($token);
        if ($workspaceIds === []) {
            return [];
        }

        return Project::query()->whereIn('client_workspace_id', $workspaceIds)
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /** Active (non-revoked, non-expired) report shares for the bound tenant. */
    private function activeShareQuery(): Builder
    {
        return ReportShare::query()
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }

    /**
     * Read-only Drive file metadata for links scoped to the client's workspaces / projects / campaigns.
     *
     * @return Collection<int, array<string,mixed>>
     */
    private function clientDriveFiles(ClientPortalToken $token): Collection
    {
        $workspaceIds = $this->ownedWorkspaceIds($token);
        if ($workspaceIds === []) {
            return collect();
        }

        $projectIds = $this->ownedProjectIds($token);
        $campaignIds = UnifiedCampaign::query()->whereIn('client_workspace_id', $workspaceIds)
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $linkIds = DriveLink::query()->where(function (Builder $q) use ($workspaceIds, $projectIds, $campaignIds): void {
            $q->where(fn (Builder $w) => $w->where('scope', 'client')->whereIn('scope_id', $workspaceIds));
            if ($projectIds !== []) {
                $q->orWhere(fn (Builder $w) => $w->where('scope', 'project')->whereIn('scope_id', $projectIds));
            }
            if ($campaignIds !== []) {
                $q->orWhere(fn (Builder $w) => $w->where('scope', 'campaign')->whereIn('scope_id', $campaignIds));
            }
        })->pluck('id')->all();

        if ($linkIds === []) {
            return collect();
        }

        return DriveFile::query()->whereIn('drive_link_id', $linkIds)
            ->orderByDesc('modified_time')
            ->get(['id', 'name', 'mime', 'size', 'web_view_link', 'thumbnail_link', 'modified_time'])
            ->map(function (DriveFile $f): array {
                /** @var array<string,mixed> $row */
                $row = [
                    'id' => (string) $f->getKey(),
                    'source' => 'drive',
                    'name' => $f->name,
                    'mime' => $f->mime,
                    'size' => $f->size,
                    'web_view_link' => $f->web_view_link,
                    'thumbnail_link' => $f->thumbnail_link,
                    'modified_time' => optional($f->modified_time)->toIso8601String(),
                ];

                return $row;
            });
    }

    /** @return array<string,mixed> client-safe campaign: high-level fields + delivery metrics, NO cost fields. */
    private function campaignShape(UnifiedCampaign $c): array
    {
        // Reuse the SAME metrics engine (MetricsAggregator) the internal surfaces use, scoped to this campaign.
        $totals = $this->metrics->forCampaign((string) $c->getKey())
            ->totals(Carbon::now()->subDays(30), Carbon::now());

        return [
            'id' => (string) $c->getKey(),
            'name' => $c->client_display_name ?: $c->name,
            'status' => $c->status,
            'objective' => $c->objective,
            'starts_on' => optional($c->starts_on)->toDateString(),
            'ends_on' => optional($c->ends_on)->toDateString(),
            // High-level, client-safe metrics ONLY — spend/revenue/roas/cpa/cpc/cpm are deliberately omitted.
            'metrics' => [
                'impressions' => $totals['impressions'] ?? 0,
                'clicks' => $totals['clicks'] ?? 0,
                'conversions' => $totals['conversions'] ?? 0,
                'ctr' => $totals['ctr'] ?? null,
            ],
        ];
    }

    /** @return array<string,mixed> client-safe report + its active-share descriptor (never the raw token). */
    private function reportShape(Report $r): array
    {
        /** @var ReportShare|null $share */
        $share = (clone $this->activeShareQuery())->where('report_id', $r->id)->latest('created_at')->first();

        return [
            'id' => (string) $r->getKey(),
            'name' => $r->name,
            'type' => $r->type,
            'audience' => $r->audience,
            'status' => $r->status,
            'period_start' => optional($r->period_start)->toDateString(),
            'period_end' => optional($r->period_end)->toDateString(),
            'generated_at' => optional($r->generated_at)->toIso8601String(),
            'share' => $share === null ? null : [
                'shared' => true,
                'allow_download' => (bool) $share->allow_download,
                'watermark' => (bool) $share->watermark,
                'expires_at' => optional($share->expires_at)->toIso8601String(),
            ],
        ];
    }

    /** Bind the request-scoped tenant to the token's tenant so tenant-global-scoped models resolve correctly. */
    private function bindTenant(ClientPortalToken $token): void
    {
        $this->tenant->setTenantId((string) $token->tenant_id);
    }

    /**
     * The client-workspace ids this verified client owns, derived from their own requests. Fail-closed: an
     * empty list means the client owns no workspace and therefore no billing/messaging data.
     *
     * @return list<string>
     */
    private function ownedWorkspaceIds(ClientPortalToken $token): array
    {
        return (clone $this->contactScope($token))
            ->whereNotNull('client_id')
            ->pluck('client_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()->values()->all();
    }

    private function ownedQuote(ClientPortalToken $token, string $id): Quote
    {
        $ids = $this->ownedWorkspaceIds($token);
        /** @var Quote|null $quote */
        $quote = $ids === [] ? null : Quote::query()->whereIn('client_workspace_id', $ids)->whereKey($id)->first();
        abort_if($quote === null, 404);

        return $quote;
    }

    private function ownedInvoice(ClientPortalToken $token, string $id): Invoice
    {
        $ids = $this->ownedWorkspaceIds($token);
        /** @var Invoice|null $invoice */
        $invoice = $ids === [] ? null : Invoice::query()->whereIn('client_workspace_id', $ids)->whereKey($id)->first();
        abort_if($invoice === null, 404);

        return $invoice;
    }

    private function ownedThread(ClientPortalToken $token, string $id): MessageThread
    {
        $ids = $this->ownedWorkspaceIds($token);
        /** @var MessageThread|null $thread */
        $thread = $ids === [] ? null : MessageThread::query()->whereIn('client_workspace_id', $ids)->whereKey($id)->first();
        abort_if($thread === null, 404);

        return $thread;
    }

    /** @return array<string,mixed> client-safe quote (no tenant/workspace/creator/internal notes). */
    /**
     * The request's selected paid-media service keys — canonical request_services first, jsonb mirror as a
     * fallback.
     *
     * @return list<string>
     */
    private function selectedServiceKeys(ExternalRequest $req): array
    {
        $keys = $req->requestServices()->orderBy('position')->pluck('service_key')->all();
        if ($keys === []) {
            $keys = array_values(array_filter((array) ($req->services ?? []), 'is_string'));
        }

        return $keys;
    }

    private function quoteShape(Quote $q): array
    {
        return [
            'id' => (string) $q->getKey(),
            'number' => $q->number,
            'currency' => $q->currency,
            'subtotal' => (string) $q->subtotal,
            'tax' => (string) $q->tax,
            'tax_treatment' => $q->tax_treatment,
            'discount' => (string) $q->discount,
            'total' => (string) $q->total,
            'line_items' => $q->line_items ?? [],
            'status' => $q->status,
            'valid_until' => optional($q->valid_until)->toDateString(),
            'created_at' => optional($q->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> client-safe invoice with derived payment status. */
    private function invoiceShape(Invoice $i): array
    {
        return [
            'id' => (string) $i->getKey(),
            'number' => $i->number,
            'currency' => $i->currency,
            'subtotal' => (string) $i->subtotal,
            'tax' => (string) $i->tax,
            'tax_treatment' => $i->tax_treatment,
            'discount' => (string) $i->discount,
            'total' => (string) $i->total,
            'line_items' => $i->line_items ?? [],
            'amount_paid' => (string) $i->amount_paid,
            'status' => $i->status,
            'payment_status' => $this->invoicePaymentStatus($i),
            'due_date' => optional($i->due_date)->toDateString(),
            'issued_at' => optional($i->issued_at)->toIso8601String(),
            'paid_at' => optional($i->paid_at)->toIso8601String(),
        ];
    }

    private function invoicePaymentStatus(Invoice $i): string
    {
        return match ($i->status) {
            'paid' => 'paid',
            'partially_paid' => 'partially_paid',
            'refunded' => 'refunded',
            'void' => 'void',
            default => 'unpaid',
        };
    }

    /**
     * @return array<string,mixed> client-safe payment. With an unconfigured provider (the Null adapter) the
     *                             state is an HONEST `awaiting_provider_credentials` — never a fake success.
     */
    private function paymentShape(Payment $p): array
    {
        $configured = $this->providers->isConfigured((string) $p->provider);

        return [
            'id' => (string) $p->getKey(),
            'status' => $p->status,
            'amount' => (string) $p->amount,
            'currency' => $p->currency,
            'payment_state' => $configured ? 'processing' : 'awaiting_provider_credentials',
            'message' => $configured
                ? 'Payment session opened.'
                : 'Payment provider not yet configured — no charge was made.',
        ];
    }

    /** @return array<string,mixed> client-safe thread card with the client-side unread count. */
    private function threadShape(MessageThread $t): array
    {
        return [
            'id' => (string) $t->getKey(),
            'subject' => $t->subject,
            'status' => $t->status,
            'last_message_at' => optional($t->last_message_at)->toIso8601String(),
            'unread' => $this->messaging->unreadCountFor($t, 'client'),
        ];
    }

    /** @return array<string,mixed> client-safe message (no internal author user id / tenant). */
    private function messageShape(Message $m): array
    {
        return [
            'id' => (string) $m->getKey(),
            'author_type' => $m->author_type,
            'body' => $m->body,
            'attachments' => $m->attachments,
            'created_at' => optional($m->created_at)->toIso8601String(),
            'read_by_client_at' => optional($m->read_by_client_at)->toIso8601String(),
        ];
    }

    /**
     * The actions a client may legitimately take from the current journey stage, grounded in the RequestStage
     * transition map (single source of truth). Purely commercial/self-service actions only.
     *
     * @return list<array<string,string>>
     */
    private function clientActionsFor(RequestStage $stage): array
    {
        $actions = [];
        if (in_array($stage, [RequestStage::ProposalSent, RequestStage::AwaitingClientApproval], true)) {
            $actions[] = ['key' => 'approve_quote', 'label' => 'Approve quote'];
            $actions[] = ['key' => 'reject_quote', 'label' => 'Reject quote'];
        }
        if (in_array($stage, [RequestStage::PaymentPending, RequestStage::PaymentFailed], true)) {
            $actions[] = ['key' => 'pay_invoice', 'label' => 'Pay invoice'];
        }
        if ($stage->canTransitionTo(RequestStage::Cancelled)) {
            $actions[] = ['key' => 'cancel_request', 'label' => 'Cancel request'];
        }

        return $actions;
    }

    // ---- session helpers ----

    private function resolveSession(Request $request): ?ClientPortalToken
    {
        // Browser: httpOnly cookie (auto-sent, never localStorage). Tests/tooling: X-Client-Token header —
        // HARD-gated to non-production so production is cookie-only and the header path cannot be abused live.
        $plain = $request->cookie(self::COOKIE);
        if ((! is_string($plain) || $plain === '') && ! app()->environment('production')) {
            $plain = $request->header('X-Client-Token');
        }
        if (! is_string($plain) || $plain === '') {
            return null;
        }
        /** @var ClientPortalToken|null $token */
        $token = ClientPortalToken::where('token_hash', hash('sha256', $plain))->first();
        if ($token === null || ! $token->isActive()) {
            return null;
        }
        $token->forceFill(['last_used_at' => Carbon::now()])->save();

        return $token;
    }

    private function requireSession(Request $request): ClientPortalToken
    {
        $token = $this->resolveSession($request);
        abort_if($token === null, 401, 'Please sign in to the client portal.');

        return $token;
    }

    /** @return Builder<ExternalRequest> */
    private function contactScope(ClientPortalToken $token)
    {
        return ExternalRequest::query()
            ->where('tenant_id', $token->tenant_id)
            ->where(function ($q) use ($token) {
                if ($token->contact_email) {
                    $q->orWhereRaw('lower(contact_email) = ?', [Str::lower($token->contact_email)]);
                }
                if ($token->contact_phone) {
                    $q->orWhere('contact_phone', $token->contact_phone);
                }
            });
    }

    private function resolveOwnedRequest(ClientPortalToken $token, string $reference): ExternalRequest
    {
        /** @var ExternalRequest|null $req */
        $req = (clone $this->contactScope($token))->with(['type', 'status'])->where('reference', $reference)->first();
        abort_if($req === null, 404); // not this client's request → 404 (non-revealing)

        return $req;
    }

    /** @return array<string,mixed>|null resulting project/campaign after conversion (client-safe) */
    private function conversionResult(ExternalRequest $req): ?array
    {
        $row = DB::table('request_conversions')
            ->where('external_request_id', $req->id)->where('status', 'completed')->first();
        if ($row === null) {
            return null;
        }
        $project = $row->project_id ? DB::table('projects')->where('id', $row->project_id)->value('name') : null;
        $campaign = $row->campaign_id ? DB::table('unified_campaigns')->where('id', $row->campaign_id)->first(['name', 'status']) : null;

        return [
            'converted' => true,
            'project_name' => $project,
            'campaign_name' => $campaign->name ?? null,
            'campaign_status' => $campaign->status ?? null,
        ];
    }

    private function progress(string $statusKey): int
    {
        return match ($statusKey) {
            'new' => 15, 'triage', 'reviewing' => 35, 'information_requested' => 45,
            'approved', 'accepted' => 60, 'in_progress' => 75, 'delivered', 'completed' => 100,
            'rejected', 'cancelled' => 100, default => 30,
        };
    }

    private function normalize(string $channel, string $destination): string
    {
        $destination = trim($destination);
        if ($channel === 'email') {
            return Str::lower($destination);
        }
        $plus = str_starts_with($destination, '+') ? '+' : '';

        return $plus.preg_replace('/\D+/', '', $destination);
    }
}
