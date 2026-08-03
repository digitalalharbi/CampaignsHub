<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestAccessToken;
use App\Domains\Requests\Models\RequestComment;
use App\Domains\Requests\Models\RequestEvent;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Models\RequestUploadSession;
use App\Domains\Requests\Services\ClientNotifier;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Requests\Services\PortalTenantResolver;
use App\Domains\Requests\Services\RequestIntake;
use App\Domains\Taxonomy\Services\PaidServiceCatalog;
use App\Rules\PhoneNumberRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public (unauthenticated) request endpoints: intake + secure tracking. The tracking view exposes ONLY
 * client-safe fields — never internal notes, assignee, SLA, tenant or internal ids.
 */
final class PublicRequestController
{
    public function __construct(
        private readonly RequestIntake $intake,
        private readonly PortalTenantResolver $portal,
        private readonly ContactVerificationService $verification,
        private readonly ClientNotifier $notifier,
        private readonly PaidServiceCatalog $paidServices,
    ) {}

    /** GET /api/v1/requests/meta — public catalog for the intake form (active service types). */
    public function meta(): JsonResponse
    {
        // `offered()` drops the modules whose service is switched off (INFL-OFF-001). The rows stay —
        // every existing request of that type still reads normally — they simply stop being listed as
        // something new can be opened against.
        $types = RequestType::query()->offered()->where('is_active', true)->orderBy('sort')
            ->get(['key', 'module', 'name_ar', 'name_en'])
            ->map(fn (RequestType $t) => [
                'key' => $t->key, 'module' => $t->module, 'name_ar' => $t->name_ar, 'name_en' => $t->name_en,
            ]);

        return response()->json(['data' => ['types' => $types]]);
    }

    /** POST /api/v1/requests — public intake. */
    public function store(Request $request): JsonResponse
    {
        // Honeypot: real users never fill this hidden field; bots do.
        if (filled($request->input('website'))) {
            throw ValidationException::withMessages(['website' => 'Rejected.']);
        }

        $data = $request->validate([
            // Validated against what is OFFERED, not merely what exists. A type the form can no
            // longer show must not still be accepted from a payload somebody typed by hand — that is
            // how a withdrawn service keeps taking orders it cannot fulfil.
            'type' => [
                'required', 'string',
                Rule::exists('request_types', 'key')->where('is_active', true),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $offered = RequestType::query()->offered()->where('key', $value)->exists();
                    if (! $offered) {
                        $fail(__('api.portal_unavailable'));
                    }
                },
            ],
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'contact_email' => ['required', 'email', 'max:160'],
            /*
             * PHONE-001 — accept the number the way the customer writes it.
             *
             * This demanded a leading «+», which rejected «0501234567» — the national format almost
             * every Saudi customer types — on a PUBLIC intake form, with an error they could not act
             * on. It is normalised to E.164 before storage either way, so nothing downstream changes.
             */
            'contact_phone' => ['required', 'string', 'max:32', new PhoneNumberRule],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'priority' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Selected paid-media services (request.paid_service option keys). Optional: legacy/simple requests
            // carry none. Custom keys are allowed because the definition allows_custom_options — so we validate
            // shape only (array of non-empty strings), never a hard whitelist that would 422 a valid custom key.
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'max:128'],
            'service_details' => ['nullable', 'array'], // optional per-service answers, keyed by service
            'metadata' => ['nullable', 'array'],
            'upload_token' => ['nullable', 'string'],
            // Proof that BOTH the phone and the email were verified (OTP) before submitting.
            'phone_verification_id' => ['required', 'string'],
            'email_verification_id' => ['required', 'string'],
        ]);

        // Server-side re-validation of the selected services — NEVER trust the browser's query params. Every
        // submitted key must be an active, PUBLIC option of request.paid_service; forged/unknown/inactive/
        // private keys are rejected (422). An empty selection is allowed (legacy/simple requests).
        $serviceMap = $this->paidServices->publicServiceMap();
        foreach (($data['services'] ?? []) as $i => $key) {
            if (! is_string($key) || ! array_key_exists($key, $serviceMap)) {
                throw ValidationException::withMessages([
                    "services.{$i}" => 'Unknown or unavailable service.',
                ]);
            }
        }

        // A final request is never accepted without a verified means of contact (phone + email).
        $phoneOk = $this->verification->consumeVerified($data['phone_verification_id'], $data['contact_phone']);
        $emailOk = $this->verification->consumeVerified($data['email_verification_id'], Str::lower($data['contact_email']));
        if (! $phoneOk || ! $emailOk) {
            throw ValidationException::withMessages([
                'verification' => 'Please verify your phone and email before submitting.',
            ]);
        }

        // Resolve the owning tenant from the request host / default portal — fail closed if none.
        $tenant = $this->portal->resolve($request);
        abort_if($tenant === null, 404, 'This request portal is not available.');

        ['request' => $req, 'token' => $token] = $this->intake->create($data, $tenant);

        // Record client-facing confirmations (email + WhatsApp) — honest delivery state, AFTER the DB txn.
        $this->notifier->notify($req, 'received');

        return response()->json([
            'data' => [
                'reference' => $req->reference,
                'type' => $req->type->name_en,
                'status' => 'new',
                'submitted_at' => optional($req->submitted_at)->toIso8601String(),
                'tracking_token' => $token,          // shown once
                'tracking_url' => "/requests/track/{$token}",
                'email_delivery' => 'awaiting_credentials', // honest: mailer not configured
                'next_step' => 'Our team will review your request shortly.',
            ],
        ], 201);
    }

    /** GET /api/v1/requests/track/{token} — client-safe status view. */
    public function track(string $token): JsonResponse
    {
        $req = $this->resolveByToken($token);

        // Only client-visible events; internal notes and metadata are never exposed here.
        $timeline = $req->events()
            ->where('is_client_visible', true)
            ->orderBy('created_at')
            ->get(['type', 'to_status', 'message', 'created_at'])
            ->map(fn (RequestEvent $e) => [
                'type' => $e->type,
                'status' => $e->to_status,
                'message' => $e->message,
                'at' => optional($e->created_at)->toIso8601String(),
            ]);

        $clientComments = $req->comments()
            ->where('visibility', 'client')
            ->orderBy('created_at')
            ->get(['author_label', 'body', 'created_at'])
            ->map(fn (RequestComment $c) => ['author' => $c->author_label ?? 'Team', 'body' => $c->body, 'at' => optional($c->created_at)->toIso8601String()]);

        // Only client-visible files; the storage path is never exposed (download would go via a token route).
        $files = $req->files()
            ->where('is_client_visible', true)
            ->whereNotNull('request_id')
            ->get(['id', 'original_name', 'size'])
            ->map(fn (RequestFile $f) => ['id' => $f->id, 'name' => $f->original_name, 'size' => $f->size]);

        return response()->json([
            'data' => [
                'reference' => $req->reference,
                'type' => $req->type->name_en,
                'type_ar' => $req->type->name_ar,
                'services' => $this->paidServices->resolve($this->selectedServiceKeys($req)),
                'status' => $req->status->is_client_visible ? $req->status->key : 'in_progress',
                'status_label' => $req->status->is_client_visible ? $req->status->name_ar : 'قيد التنفيذ',
                'status_label_en' => $req->status->is_client_visible ? $req->status->name_en : 'In progress',
                'submitted_at' => optional($req->submitted_at)->toIso8601String(),
                'updated_at' => optional($req->updated_at)->toIso8601String(),
                'timeline' => $timeline,
                'comments' => $clientComments,
                'files' => $files,
            ],
        ]);
    }

    /** POST /api/v1/requests/track/{token}/reply — the client adds a message (+ optional client files). */
    public function reply(Request $request, string $token): JsonResponse
    {
        $req = $this->resolveByToken($token);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'upload_token' => ['nullable', 'string'],
        ]);

        // Always CLIENT visibility — a client can never create an internal note or a system event.
        $req->comments()->create([
            'visibility' => 'client',
            'author_label' => 'Client',
            'body' => $data['message'],
        ]);
        $req->events()->create([
            'type' => 'comment',
            'is_client_visible' => true,
            'message' => 'Client added a message',
            'created_at' => now(),
        ]);

        if (! empty($data['upload_token'])) {
            $session = RequestUploadSession::where('token_hash', hash('sha256', $data['upload_token']))->first();
            if ($session !== null) {
                $session->files()->whereNull('request_id')->update([
                    'request_id' => $req->id, 'upload_session_id' => null, 'is_client_visible' => true,
                ]);
                $session->delete();
            }
        }

        return response()->json(['data' => ['status' => 'received']], 201);
    }

    /** GET /api/v1/requests/track/{token}/files/{file} — secure download of a CLIENT-VISIBLE file only. */
    public function downloadFile(string $token, int $file): StreamedResponse
    {
        $req = $this->resolveByToken($token);

        /** @var RequestFile|null $record */
        $record = $req->files()->where('is_client_visible', true)->find($file);
        abort_if($record === null, 404); // wrong request, internal-only file, or bad id → 404 (non-revealing)

        return Storage::disk($record->disk)
            ->download($record->path, $record->original_name);
    }

    /**
     * The request's selected paid-media service keys — canonical request_services first, jsonb mirror fallback.
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

    /** Resolve a request by a valid (non-revoked, non-expired) tracking token, or abort non-revealingly. */
    private function resolveByToken(string $token): ExternalRequest
    {
        $record = RequestAccessToken::where('token_hash', hash('sha256', $token))->first();
        abort_if($record === null, 404);
        abort_if($record->revoked_at !== null, 410, 'This tracking link has been revoked.');
        abort_if($record->expires_at !== null && $record->expires_at->isPast(), 410, 'This tracking link has expired.');
        $record->forceFill(['last_used_at' => now()])->save();

        /** @var ExternalRequest $req */
        $req = ExternalRequest::with(['type', 'status'])->findOrFail($record->request_id);

        return $req;
    }
}
