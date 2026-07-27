<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers;

use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Requests\Services\PortalTenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public contact-verification endpoints (OTP for phone via SMS/WhatsApp, and for email). Honest about
 * delivery: with no provider configured the code is not sent (delivery_status = awaiting_provider_credentials)
 * and, in non-production only, the code is returned so the flow remains testable.
 */
final class ContactVerificationController
{
    public function __construct(
        private readonly ContactVerificationService $service,
        private readonly PortalTenantResolver $portal,
    ) {}

    /** POST /api/v1/requests/verify/start */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['sms', 'whatsapp', 'email'])],
            'destination' => ['required', 'string', 'max:190'],
            'purpose' => ['nullable', Rule::in(['contact_verify', 'portal_login'])],
        ]);

        $destination = $this->normalize($data['channel'], $data['destination']);
        $this->assertShape($data['channel'], $destination);

        $tenant = $this->portal->resolve($request);
        $result = $this->service->start($data['channel'], $destination, $data['purpose'] ?? 'contact_verify', $tenant ? (string) $tenant->id : null);

        return response()->json(['data' => [
            'verification_id' => $result['id'],
            'channel' => $result['channel'],
            'destination' => $result['destination'],
            'delivery_status' => $result['delivery_status'], // awaiting_provider_credentials until a provider is wired
            'dev_code' => $result['dev_code'],               // non-production only; null otherwise
        ]], 201);
    }

    /** POST /api/v1/requests/verify/check */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $v = $this->service->verify($data['verification_id'], $data['code']);

        return response()->json(['data' => [
            'verification_id' => (string) $v->id,
            'channel' => $v->channel,
            'destination' => $v->destination,
            'verified' => true,
        ]]);
    }

    private function normalize(string $channel, string $destination): string
    {
        $destination = trim($destination);
        if ($channel === 'email') {
            return Str::lower($destination);
        }

        // Phone: keep a leading + and digits only.
        $plus = str_starts_with($destination, '+') ? '+' : '';

        return $plus.preg_replace('/\D+/', '', $destination);
    }

    private function assertShape(string $channel, string $destination): void
    {
        if ($channel === 'email') {
            validator(['email' => $destination], ['email' => ['required', 'email', 'max:190']])->validate();

            return;
        }
        // Phone must be E.164-ish: + and 8–15 digits.
        validator(['phone' => $destination], ['phone' => ['required', 'regex:/^\+[1-9]\d{7,14}$/']])->validate();
    }
}
