<?php

declare(strict_types=1);

namespace App\Domains\Legal\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Legal\Services\DeletionBlockers;
use App\Domains\Legal\Services\DeletionVerification;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

/**
 * LEGAL-DELETE-001 — the public deletion flow behind `https://campaignshub.io/data-deletion`.
 *
 * Every ad platform review asks for that URL, and a page of prose does not satisfy what it is for:
 * somebody has to be able to ask for their data to be deleted and find out what happened to the
 * request. This is that flow.
 *
 * ## Three endpoints for a person, one for a platform
 *
 * `submit` → `verify` → `status` is what a human does. `callback` is what Meta's Data Deletion
 * Request Callback does, and it is deliberately separate: it authenticates with a signature rather
 * than a code, answers JSON in the shape that platform demands, and never sends anybody an email.
 *
 * ## No session, ever
 *
 * These are public by necessity — the person asking to be deleted has usually already lost access, or
 * never had an account at all and appears only inside a client's data. Requiring a sign-in to ask for
 * deletion would be a wall in front of the one right that has to work when everything else has failed.
 *
 * ## What the answers deliberately do not reveal
 *
 * Nothing here distinguishes «no such request» from «wrong code» from «expired». An endpoint that
 * did would be a way to ask which addresses have requested deletion — a list of exactly the people
 * with the strongest interest in not being on one.
 */
final class DataDeletionController extends Controller
{
    public function __construct(
        private readonly DeletionVerification $verification,
        private readonly DeletionBlockers $blockers,
        private readonly TransactionalMailer $mailer,
    ) {}

    /**
     * Open a request. A destructive one opens `verifying` and mails a code; nothing is deleted here.
     *
     * `provider` and `workspace` are optional context an operator needs to find the right rows — a
     * person deleting their Snapchat connection is a different job from a person deleting an account
     * — and neither is trusted for anything: they are notes on the request, not instructions.
     */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(DataRequest::TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'provider' => ['nullable', 'string', 'max:40'],
            'workspace' => ['nullable', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = $request->user();
        $tenantId = $user?->currentTenant()?->id;

        $context = array_filter([
            'provider' => $data['provider'] ?? null,
            'workspace' => $data['workspace'] ?? null,
        ]);

        $record = new DataRequest;
        $record->fill([
            'type' => $data['type'],
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'details' => $this->withContext($data['details'] ?? null, $context),
            'reference' => DataRequest::makeReference(),
            'user_id' => $user?->id,
            'tenant_id' => $tenantId,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'locale' => app()->getLocale(),
        ]);

        $destructive = $record->isDestructive();
        $blockers = $destructive ? $this->blockers->forTenant($tenantId ? (string) $tenantId : null) : [];

        /*
         * A destructive request opens `verifying`, and blockers are recorded WITHOUT overriding that.
         *
         * The order matters: proving the address comes first, because until it is proven we do not
         * know whose blockers those even are. The reasons still travel back, so somebody who cannot
         * be deleted today learns why now rather than after they have gone and found the code.
         */
        $record->status = $destructive ? 'verifying' : ($blockers !== [] ? 'blocked' : 'pending');
        $record->blockers = $blockers ?: null;
        $record->source = 'web';
        $record->save();

        $delivery = null;

        if ($destructive) {
            $code = $this->verification->issue($record);
            $delivery = $this->deliver($record, $code);
        }

        $this->audit($record, 'legal.deletion.requested', [
            'type' => $record->type,
            'status' => $record->status,
            'destructive' => $destructive,
            'blockers' => array_column($blockers, 'code'),
            'delivery' => $delivery,
        ]);

        return ApiResponse::success([
            'reference' => $record->reference,
            'status' => $record->status,
            'verification_required' => $destructive,
            // The honest delivery outcome, so the page can say «we could not send it» rather than
            // «check your email» when no mail provider is configured (MAIL is READY_FOR_CREDENTIALS).
            'delivery' => $delivery,
            'blockers' => $blockers,
        ], __('api.data_request_received'));
    }

    /** Return the code. On success the request stops being a claim and becomes actionable. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:160'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $record = $this->verification->verify($data['reference'], $data['email'], $data['code']);

        if ($record === null) {
            // One answer for every way this can fail — see the class docblock.
            return ApiResponse::error(__('api.data_request_verification_failed'), status: 422);
        }

        // Now that the address is proven, the blockers are recomputed against the right tenant and
        // the request lands in the queue it belongs in.
        $blockers = $this->blockers->forTenant($record->tenant_id ? (string) $record->tenant_id : null);
        $record->forceFill([
            'status' => $blockers !== [] ? 'blocked' : 'pending',
            'blockers' => $blockers ?: null,
        ])->save();

        $this->audit($record, 'legal.deletion.verified', [
            'status' => $record->status,
            'blockers' => array_column($blockers, 'code'),
        ]);

        return ApiResponse::success([
            'reference' => $record->reference,
            'status' => $record->status,
            'blockers' => $blockers,
        ], __('api.data_request_verified'));
    }

    /**
     * Where a request has got to. Reference AND email together, because a reference alone is short.
     *
     * A miss answers 404 with the same message whatever the reason.
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:160'],
        ]);

        $record = DataRequest::query()
            ->where('reference', mb_strtoupper(trim($data['reference'])))
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])
            ->first();

        if ($record === null) {
            return ApiResponse::error(__('api.data_request_not_found'), status: 404);
        }

        return ApiResponse::success([
            'reference' => $record->reference,
            'type' => $record->type,
            'status' => $record->status,
            'verification_required' => $record->isDestructive(),
            'verified' => $record->verified_at !== null,
            'blockers' => $record->blockers ?? [],
            'submitted_at' => $record->created_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),
        ]);
    }

    /**
     * The machine-readable callback a platform posts to, verified by its own app secret.
     *
     * Meta posts a `signed_request`: `base64url(hmac_sha256(payload, app_secret)).base64url(json)`.
     * It expects `{"url": …, "confirmation_code": …}` so the person can be shown where their request
     * stands.
     *
     * **Fail closed in both directions.** With no app secret configured there is nothing to verify a
     * signature against, so this answers 503 rather than opening a deletion for anyone who can find
     * the URL — a callback that trusts an unsigned body is a public «delete this account» endpoint.
     * And a signature that does not match is 401 with nothing written.
     *
     * The request it opens is already verified: the platform's signature IS the proof of identity,
     * and it is a stronger one than an email code.
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        $secret = $this->appSecretFor($provider);

        if ($secret === null || $secret === '') {
            return ApiResponse::error(__('api.data_deletion_callback_unconfigured'), status: 503);
        }

        $payload = $this->verifiedPayload((string) $request->input('signed_request'), $secret);

        if ($payload === null) {
            return ApiResponse::error(__('api.unauthenticated'), status: 401);
        }

        $externalId = (string) ($payload['user_id'] ?? '');

        if ($externalId === '') {
            return ApiResponse::error(__('api.validation'), status: 422);
        }

        /*
         * Idempotent by (provider, external id).
         *
         * A platform that retries — and they do — must not open a second request, because two
         * references for one deletion is two answers to give and one of them will be wrong.
         */
        $record = DataRequest::query()
            ->where('source', 'provider_callback')
            ->where('source_provider', $provider)
            ->where('details', 'like', '%'.$externalId.'%')
            ->first();

        if ($record === null) {
            $record = new DataRequest;
            $record->fill([
                'type' => 'delete_data',
                'name' => strtoupper($provider).' user',
                // No address is supplied by the platform, and inventing one would put a stranger's
                // inbox on a deletion request. The identifier we DO have is the external id.
                'email' => $provider.'+'.$externalId.'@deletion.invalid',
                'details' => 'Deletion requested by '.$provider.' for external user id '.$externalId.'.',
                'reference' => DataRequest::makeReference(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'locale' => 'en',
            ]);
            $record->status = 'pending';
            $record->source = 'provider_callback';
            $record->source_provider = $provider;
            // The signature is the identity proof; there is no address to mail a code to.
            $record->verified_at = now();
            $record->save();

            $this->audit($record, 'legal.deletion.callback_received', [
                'provider' => $provider,
                'external_user_id' => $externalId,
            ]);
        }

        return response()->json([
            'url' => Frontend::url('/data-deletion?reference='.$record->reference),
            'confirmation_code' => $this->verification->confirmationCode($record),
        ]);
    }

    /**
     * Verify and decode a `signed_request`. Null on anything that is not a genuine, current one.
     *
     * `hash_equals` rather than `===` for the same reason it is used on the email code, and the
     * algorithm is pinned to HMAC-SHA256 rather than read from the payload — a signature scheme that
     * lets the sender choose the algorithm lets the sender choose `none`.
     */
    private function verifiedPayload(string $signedRequest, string $secret): ?array
    {
        if (! str_contains($signedRequest, '.')) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);

        $signature = $this->base64UrlDecode($encodedSignature);
        $payloadJson = $this->base64UrlDecode($encodedPayload);

        if ($signature === null || $payloadJson === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, $secret, true);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($payloadJson, true);

        return is_array($payload) ? $payload : null;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * The app secret to verify a callback against, per provider.
     *
     * Read from the provider's own configured credential — the same secret that signs its webhooks —
     * so there is nothing extra for an operator to set up and no second place for it to go stale.
     */
    private function appSecretFor(string $provider): ?string
    {
        return match ($provider) {
            'meta' => Config::get('services.meta_ads.app_secret') ?: Config::get('ad_platforms.meta.client_secret'),
            default => null,
        };
    }

    /** Deliver the code, and report what actually happened rather than what was intended. */
    private function deliver(DataRequest $record, string $code): string
    {
        return $this->mailer->send(
            recipient: $record->email,
            mail: new CredentialMail(
                purpose: CredentialMail::EMAIL_VERIFICATION,
                lang: $record->locale ?: 'ar',
                code: $code,
                expiresInMinutes: 60,
            ),
            kind: CredentialMail::EMAIL_VERIFICATION,
            template: 'credential.email_verification',
            locale: $record->locale ?: 'ar',
            dedupKey: 'deletion:'.$record->reference,
        );
    }

    /** Fold the optional context into the details an operator reads, without a schema change. */
    private function withContext(?string $details, array $context): ?string
    {
        if ($context === []) {
            return $details;
        }

        $lines = [];
        foreach ($context as $key => $value) {
            $lines[] = ucfirst($key).': '.$value;
        }

        return trim(implode("\n", $lines)."\n\n".(string) $details);
    }

    private function audit(DataRequest $record, string $action, array $after): void
    {
        AuditLog::create([
            'tenant_id' => $record->tenant_id,
            'user_id' => $record->user_id,
            'action' => $action,
            'entity_type' => DataRequest::class,
            'entity_id' => (string) $record->getKey(),
            // The reference, never the code and never the verification hash.
            'after' => ['reference' => $record->reference] + $after,
            'ip_address' => $record->ip,
            'user_agent' => $record->user_agent,
        ]);
    }
}
