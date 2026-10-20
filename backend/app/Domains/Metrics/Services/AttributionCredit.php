<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * ATTRIB-CREDIT-001 — how ONE order's credit is shared across the touches the store actually recorded.
 *
 * A touch is an earlier order of the same customer with its own evidence (see {@see OrderTouch}), plus
 * the converting order itself. Nothing here invents a journey: the touches arrive from outside, oldest
 * first, and a model only decides how a whole of 1.0 is divided among them. Every model returns shares
 * that sum to 1.0, so the totals of a model are the totals of the orders and a model can be compared with
 * another without either one creating or losing a sale.
 *
 * Touches that name nobody (`unattributed`) are not credited unless the caller asks. When there is no
 * touch left to credit, the whole order stays `unattributed` — an order with no evidence is reported as
 * having none, not given to the nearest channel.
 *
 * The model's name and {@see VERSION} travel with every figure built from it, so a number from last month
 * can be reproduced with the rules that produced it, and a change of rule is a new version.
 */
final class AttributionCredit
{
    /** Bump when any rule below changes: a figure is only reproducible under the version that made it. */
    public const VERSION = '2026-10-credit-1';

    public const LINEAR = 'linear';

    public const POSITION_BASED = 'position_based';

    public const TIME_DECAY = 'time_decay';

    public const CUSTOM_WEIGHTED = 'custom_weighted';

    public const LAST_NON_DIRECT = 'last_non_direct';

    public const MODELS = [self::LINEAR, self::POSITION_BASED, self::TIME_DECAY, self::CUSTOM_WEIGHTED, self::LAST_NON_DIRECT];

    public const DEFAULT_LOOKBACK_DAYS = 90;

    public const DEFAULT_HALF_LIFE_DAYS = 7;

    /** The usual position-based split: the first and the last touch each take 40%, the middle shares 20%. */
    public const DEFAULT_WEIGHTS = ['first' => 0.4, 'middle' => 0.2, 'last' => 0.4];

    /**
     * Normalise and validate a configuration, so an unknown model or an impossible number is refused
     * where it is entered, not discovered in a report.
     *
     * @param  array<string,mixed>  $raw
     * @return array{models: list<string>, lookback_days: int, half_life_days: int, weights: array{first: float, middle: float, last: float}, include_unattributed: bool}
     */
    public static function config(array $raw): array
    {
        $models = array_values(array_unique(array_map('strval', (array) ($raw['models'] ?? self::MODELS))));
        foreach ($models as $model) {
            if (! in_array($model, self::MODELS, true)) {
                throw new InvalidArgumentException("Unknown attribution model: {$model}");
            }
        }
        if ($models === []) {
            throw new InvalidArgumentException('At least one attribution model is needed.');
        }

        $lookback = (int) ($raw['lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS);
        $halfLife = (int) ($raw['half_life_days'] ?? self::DEFAULT_HALF_LIFE_DAYS);
        if ($lookback < 1 || $lookback > 365) {
            throw new InvalidArgumentException('The lookback window is between 1 and 365 days.');
        }
        if ($halfLife < 1 || $halfLife > 90) {
            throw new InvalidArgumentException('The half-life is between 1 and 90 days.');
        }

        $weights = self::DEFAULT_WEIGHTS;
        if (isset($raw['weights'])) {
            $given = (array) $raw['weights'];
            $weights = [];
            foreach (['first', 'middle', 'last'] as $slot) {
                $w = $given[$slot] ?? null;
                if (! is_numeric($w) || (float) $w < 0) {
                    throw new InvalidArgumentException("The {$slot} weight must be a number of zero or more.");
                }
                $weights[$slot] = (float) $w;
            }
            $sum = array_sum($weights);
            if ($sum <= 0) {
                throw new InvalidArgumentException('The weights cannot all be zero.');
            }
            // Stored as shares of one, so the same configuration reads the same however it was typed.
            $weights = array_map(static fn (float $w): float => $w / $sum, $weights);
        }

        return [
            'models' => $models,
            'lookback_days' => $lookback,
            'half_life_days' => $halfLife,
            'weights' => $weights,
            'include_unattributed' => ($raw['include_unattributed'] ?? false) === true,
        ];
    }

    /**
     * Share one order across its touches.
     *
     * @param  list<array{channel: string, at: ?CarbonInterface}>  $touches  oldest first; the last one is the converting order's own
     * @param  array{lookback_days: int, half_life_days: int, weights: array{first: float, middle: float, last: float}, include_unattributed: bool}  $config
     * @return array<string,float> channel => share, summing to 1.0
     */
    public static function split(string $model, array $touches, ?CarbonInterface $convertedAt, array $config): array
    {
        if (! in_array($model, self::MODELS, true)) {
            throw new InvalidArgumentException("Unknown attribution model: {$model}");
        }

        $since = $convertedAt?->copy()->subDays($config['lookback_days']);
        $eligible = [];
        foreach ($touches as $i => $touch) {
            $isOwn = $i === count($touches) - 1;
            // An earlier order outside the window is not a touch of this one; the order itself always is.
            if (! $isOwn && $since !== null && ($touch['at'] === null || $touch['at']->lt($since))) {
                continue;
            }
            // «Last non-direct» is defined by ignoring the touches that name nobody, so it ignores them whatever else is set.
            if ($touch['channel'] === OrderTouch::UNATTRIBUTED && (! $config['include_unattributed'] || $model === self::LAST_NON_DIRECT)) {
                continue;
            }
            $eligible[] = $touch;
        }

        if ($eligible === []) {
            return [OrderTouch::UNATTRIBUTED => 1.0];
        }

        $n = count($eligible);
        $shares = match ($model) {
            self::LINEAR => array_fill(0, $n, 1 / $n),
            self::LAST_NON_DIRECT => self::lastOnly($n),
            self::POSITION_BASED, self::CUSTOM_WEIGHTED => self::byPosition($n, $model === self::POSITION_BASED ? self::DEFAULT_WEIGHTS : $config['weights']),
            self::TIME_DECAY => self::byDecay($eligible, $convertedAt, $config['half_life_days']),
        };

        $out = [];
        foreach ($eligible as $i => $touch) {
            // A channel that earns nothing under this model is absent, not present at zero.
            if ($shares[$i] <= 0.0) {
                continue;
            }
            $out[$touch['channel']] = ($out[$touch['channel']] ?? 0.0) + $shares[$i];
        }

        return $out;
    }

    /** @return list<float> */
    private static function lastOnly(int $n): array
    {
        $shares = array_fill(0, $n, 0.0);
        $shares[$n - 1] = 1.0;

        return $shares;
    }

    /**
     * @param  array{first: float, middle: float, last: float}  $w
     * @return list<float>
     */
    private static function byPosition(int $n, array $w): array
    {
        if ($n === 1) {
            return [1.0];
        }
        if ($n === 2) {
            $total = $w['first'] + $w['last'];

            // Two touches have no middle; the middle's weight is not handed to either end, only their ratio is kept.
            return $total > 0 ? [$w['first'] / $total, $w['last'] / $total] : [0.5, 0.5];
        }

        $shares = array_fill(0, $n, $w['middle'] / ($n - 2));
        $shares[0] = $w['first'];
        $shares[$n - 1] = $w['last'];

        return $shares;
    }

    /**
     * @param  list<array{channel: string, at: ?CarbonInterface}>  $touches
     * @return list<float>
     */
    private static function byDecay(array $touches, ?CarbonInterface $convertedAt, int $halfLife): array
    {
        $raw = [];
        foreach ($touches as $touch) {
            $days = ($convertedAt === null || $touch['at'] === null) ? 0.0 : max(0.0, $touch['at']->diffInSeconds($convertedAt, false) / 86400);
            $raw[] = 0.5 ** ($days / $halfLife);
        }
        $sum = array_sum($raw);

        return array_map(static fn (float $w): float => $w / $sum, $raw);
    }
}
