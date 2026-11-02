<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The server-only boundary to CampaignsHub's existing Twilio Verify Service.
 *
 * Twilio owns the OTP value and its lifetime. CampaignsHub sends only the E.164 destination when a
 * challenge starts and the submitted code when it is checked; neither value is persisted or logged.
 */
final class TwilioVerifyService
{
    private const BASE_URL = 'https://verify.twilio.com/v2/Services';

    public function configured(): bool
    {
        return $this->accountSid() !== ''
            && $this->authToken() !== ''
            && $this->serviceSid() !== '';
    }

    /** Start a Verify challenge and return Twilio's non-secret status (normally `pending`). */
    public function send(string $e164): string
    {
        $response = $this->request('Verifications', [
            'To' => $e164,
            'Channel' => (string) config('services.twilio.verify_channel', 'sms'),
        ]);

        return (string) ($response->json('status') ?? 'pending');
    }

    /** Check a submitted code. Callers must accept only the exact `approved` status. */
    public function check(string $e164, string $code): string
    {
        $response = $this->request('VerificationCheck', [
            'To' => $e164,
            'Code' => $code,
        ], 'VerificationCheck');

        return (string) ($response->json('status') ?? ($response->status() === 404 ? 'deleted' : 'pending'));
    }

    private function request(string $resource, array $form, ?string $logOperation = null): Response
    {
        if (! $this->configured()) {
            throw new TwilioVerifyException('Twilio Verify is not configured.');
        }

        $operation = $logOperation ?? $resource;

        try {
            $response = $this->client()->asForm()->post(
                self::BASE_URL.'/'.$this->serviceSid().'/'.$resource,
                $form,
            );
        } catch (ConnectionException $e) {
            $this->logFailure($operation, null, 'connection');
            throw new TwilioVerifyException('Twilio Verify is temporarily unavailable.', previous: $e);
        }

        // Verify removes expired/exhausted challenges; its check endpoint then answers 404.
        if ($operation === 'VerificationCheck' && $response->status() === 404) {
            return $response;
        }

        if (! $response->successful()) {
            $this->logFailure(
                $operation,
                $response->status(),
                $this->safeProviderCode($response),
            );
            throw new TwilioVerifyException('Twilio Verify rejected the request.');
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth($this->accountSid(), $this->authToken())
            ->acceptJson()
            ->connectTimeout((int) config('services.twilio.connect_timeout', 3))
            ->timeout((int) config('services.twilio.timeout', 8))
            ->retry(1, 150, throw: false);
    }

    private function logFailure(string $operation, ?int $httpStatus, string $providerCode): void
    {
        // No destination, submitted code, response body, account SID, token or service SID is logged.
        Log::warning('Twilio Verify request failed.', [
            'operation' => $operation,
            'http_status' => $httpStatus,
            'provider_code' => $providerCode,
        ]);
    }

    private function safeProviderCode(Response $response): string
    {
        $code = $response->json('code');

        return is_int($code) || (is_string($code) && preg_match('/^\d{1,8}$/', $code) === 1)
            ? (string) $code
            : 'unknown';
    }

    private function accountSid(): string
    {
        return trim((string) config('services.twilio.account_sid'));
    }

    private function authToken(): string
    {
        return trim((string) config('services.twilio.auth_token'));
    }

    private function serviceSid(): string
    {
        return trim((string) config('services.twilio.verify_service_sid'));
    }
}
