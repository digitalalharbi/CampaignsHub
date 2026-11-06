<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp over Twilio — the vendor this repository already has (`TwilioVerifyService`).
 *
 * Request shape, from Twilio's Messages API (`POST /2010-04-01/Accounts/{AccountSid}/Messages.json`):
 * form-encoded, HTTP Basic with the Account SID and Auth Token, `To` and `From` carrying the `whatsapp:`
 * prefix on an E.164 number, and — for a business-initiated message — `ContentSid` (the approved
 * template, `HX…`) with `ContentVariables` as a JSON STRING of numbered values. `Body` and `ContentSid`
 * are mutually exclusive, so only `ContentSid` is ever sent: free text cannot leave this class.
 * A Messaging Service may stand in for `From` (`MessagingServiceSid`).
 *
 * ## Configured means all of it
 *
 * `isConfigured()` is false unless the Account SID, the Auth Token and a sender (a WhatsApp-enabled
 * `From` number or a Messaging Service) are all present. Until then `send()` returns
 * `awaiting_credentials` without opening a connection. `config/providers.php` ALSO keeps the Null
 * adapter registered until `TWILIO_WHATSAPP_FROM` or `TWILIO_WHATSAPP_MESSAGING_SERVICE_SID` exists, so
 * two things must be true before a byte leaves.
 *
 * ## What is never logged
 *
 * The destination, the template values, the response body, the SIDs and the token. A failure logs the
 * HTTP status and Twilio's numeric error code, which is enough to look up and says nothing about a person.
 *
 * `payload` — `content_sid` (string), `variables` (array of numbered strings).
 */
final class TwilioWhatsAppProvider implements MessageProvider
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01/Accounts';

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        return $this->accountSid() !== '' && $this->authToken() !== '' && ($this->from() !== '' || $this->messagingService() !== '');
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'awaiting_credentials', 'provider_message_id' => null, 'error' => null];
        }

        $sid = (string) ($payload['content_sid'] ?? '');

        if ($sid === '' || preg_match('/^\+[1-9]\d{6,14}$/', $destination) !== 1) {
            return ['status' => 'failed', 'provider_message_id' => null, 'error' => 'invalid_request'];
        }

        $form = [
            'To' => 'whatsapp:'.$destination,
            'ContentSid' => $sid,
            'ContentVariables' => json_encode((object) ($payload['variables'] ?? []), JSON_UNESCAPED_UNICODE),
        ];
        $this->messagingService() !== ''
            ? $form['MessagingServiceSid'] = $this->messagingService()
            : $form['From'] = 'whatsapp:'.$this->from();

        try {
            $response = Http::withBasicAuth($this->accountSid(), $this->authToken())
                ->acceptJson()->asForm()
                ->connectTimeout((int) config('services.twilio.connect_timeout', 3))
                ->timeout((int) config('services.twilio.timeout', 8))
                ->post(self::BASE_URL.'/'.$this->accountSid().'/Messages.json', $form);
        } catch (ConnectionException) {
            Log::warning('Twilio WhatsApp request failed.', ['http_status' => null, 'provider_code' => 'connection']);

            return ['status' => 'retrying', 'provider_message_id' => null, 'error' => 'connection'];
        }

        if ($response->successful()) {
            $messageSid = $response->json('sid');

            return ['status' => 'sent', 'provider_message_id' => is_string($messageSid) ? $messageSid : null, 'error' => null];
        }

        $code = $response->json('code');
        $safe = is_int($code) || (is_string($code) && preg_match('/^\d{1,8}$/', $code) === 1) ? (string) $code : 'unknown';
        Log::warning('Twilio WhatsApp request failed.', ['http_status' => $response->status(), 'provider_code' => $safe]);

        // 429 and 5xx are the vendor's to recover from; a 4xx is this request's own fault and will not improve.
        $transient = $response->status() === 429 || $response->status() >= 500;

        return ['status' => $transient ? 'retrying' : 'failed', 'provider_message_id' => null, 'error' => $safe];
    }

    private function accountSid(): string
    {
        return trim((string) config('services.twilio.account_sid'));
    }

    private function authToken(): string
    {
        return trim((string) config('services.twilio.auth_token'));
    }

    private function from(): string
    {
        return trim((string) config('services.twilio.whatsapp.from'));
    }

    private function messagingService(): string
    {
        return trim((string) config('services.twilio.whatsapp.messaging_service_sid'));
    }
}
