<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Http\Client\Response;

/**
 * The shared mechanics: the support table, the transport, and the provider's verdict.
 *
 * `SUPPORT` is the whole truth about what an adapter does. A cell holding `true` is implemented; a
 * cell holding `WriteRefusal::PROVIDER_UNSUPPORTED` is a documented platform limit; an ABSENT cell is
 * `NOT_IMPLEMENTED` — so forgetting to declare something can only ever hide a control, never show
 * one that does nothing.
 */
abstract class AbstractWriteAdapter implements ProviderWriteAdapter
{
    /** @var array<string, array<string, true|string>> level → action → true | refusal */
    protected const SUPPORT = [];

    /** Currencies whose smallest unit is the unit itself (Meta's «offset 1» list). */
    private const ZERO_DECIMAL = ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'];

    public function refusal(WriteLevel $level, WriteAction $action): ?string
    {
        $cell = static::SUPPORT[$level->value][$action->value] ?? WriteRefusal::NOT_IMPLEMENTED;

        return $cell === true ? null : (string) $cell;
    }

    public function bidStrategies(WriteLevel $level): array
    {
        return [];
    }

    public function budgetKinds(WriteLevel $level): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function send(ApiAdvertisingConnector $connector, string $method, string $path, array $payload = [], bool $idempotent = true, array $headers = [], bool $asForm = false): Response
    {
        $request = $connector->providerRequest($idempotent, $headers);
        if ($asForm) {
            $request = $request->asForm();
        }
        $url = $connector->providerUrl($path);

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $payload),
            'DELETE' => $payload === [] ? $request->delete($url) : $request->delete($url, $payload),
            'PUT' => $request->put($url, $payload),
            default => $request->post($url, $payload),
        };
    }

    /**
     * The provider's verdict on one response, with the confirmed changes to mirror.
     *
     * @param  array<string, mixed>  $mirror
     */
    protected function verdict(Response $response, array $mirror = [], ?string $newExternalId = null): WriteOutcome
    {
        $requestId = $this->requestId($response);

        if (! PlatformHttp::succeeded($response)) {
            return WriteOutcome::refused(
                ProviderErrorText::forStorage(PlatformHttp::reason($response)) ?? 'The provider refused the change.',
                $requestId,
                $response->status() >= 500 ? 502 : 422,
            );
        }

        return WriteOutcome::confirmed($mirror, $requestId, $newExternalId);
    }

    protected function requestId(Response $response): ?string
    {
        $body = $response->json() ?? [];
        foreach (['request_id', 'requestId'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string) $body[$key];
            }
        }
        foreach (['x-fb-trace-id', 'request-id', 'x-tt-logid'] as $header) {
            $value = $response->header($header);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** An amount in the currency's smallest unit — cents for SAR/USD, the unit itself for JPY. */
    protected function minorUnits(float $amount, ?string $currency): int
    {
        return in_array(strtoupper((string) $currency), self::ZERO_DECIMAL, true)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    protected function micros(float $amount): int
    {
        return (int) round($amount * 1_000_000);
    }

    /** @param  array<string, mixed>  $input */
    protected function budgetMirror(array $input): array
    {
        return array_filter([
            'daily_budget' => isset($input['daily_budget']) ? (float) $input['daily_budget'] : null,
            'lifetime_budget' => isset($input['lifetime_budget']) ? (float) $input['lifetime_budget'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** The local status a confirmed action leaves behind, in the mirror's own vocabulary. */
    protected function statusMirror(WriteAction $action): array
    {
        return match ($action) {
            WriteAction::Pause => ['status' => 'paused'],
            WriteAction::Resume => ['status' => 'active'],
            WriteAction::Archive => ['status' => 'archived'],
            WriteAction::Delete => ['status' => 'deleted'],
            default => [],
        };
    }
}
