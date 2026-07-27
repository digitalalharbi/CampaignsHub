<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers;

use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestComment;
use App\Domains\Requests\Models\RequestEvent;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Requests\Services\PortalTenantResolver;
use App\Domains\Requests\Services\RequestUploadAttacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        // The browser uses the httpOnly cookie (auto-sent, never in JS/localStorage). Non-production also
        // returns the raw token so tests/tooling can authenticate via the X-Client-Token header.
        $devToken = config('requests.verification.expose_dev_code') ? $plain : null;

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
            'status' => $req->status->is_client_visible ? $req->status->key : 'in_progress',
            'status_label' => $req->status->is_client_visible ? $req->status->name_en : 'In progress',
            'progress' => $this->progress($req->status->key),
            'submitted_at' => optional($req->submitted_at)->toIso8601String(),
            'updated_at' => optional($req->updated_at)->toIso8601String(),
            'timeline' => $timeline,
            'comments' => $comments,
            'files' => $files,
            'result' => $this->conversionResult($req),
        ]]);
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

    // ---- session helpers ----

    private function resolveSession(Request $request): ?ClientPortalToken
    {
        // Browser: httpOnly cookie (auto-sent). Tests/tooling: X-Client-Token header. Never localStorage.
        $plain = $request->cookie(self::COOKIE);
        if (! is_string($plain) || $plain === '') {
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
