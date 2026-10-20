<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Touchpoints\OrderJourneys;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * ATTRIB-CREDIT-001 — how ONE order's credit is shared across the marketing touchpoints that led to it.
 *
 * ## What it divides, and among what
 *
 * The touches are recorded marketing interactions that were linked to the order with verified evidence (see
 * {@see OrderJourneys}): a tracked click, a click id the platform issued, a
 * token we put in the destination. They are NEVER earlier orders — a customer's order history is repeat-purchase
 * analysis, and nothing in this class reads it. The order itself is not a touch either: it is the conversion.
 *
 * Each model divides a whole of 1.0 among those touches, so the shares of a model always add up to exactly the
 * order, and no model can create or lose a sale. An order with no eligible touch gets no share anywhere: it
 * stays `unattributed`, and is reported as having no evidence rather than being handed to the nearest channel.
 *
 * The model's name and {@see VERSION} travel with every figure built from it, so a number from last month can be
 * rebuilt with the rules that produced it.
 */
final class AttributionCredit
{
    /** Bump when any rule below changes: a figure is only reproducible under the version that made it. */
    public const VERSION = '2026-10-credit-2';

    public const UNATTRIBUTED = 'unattributed';

    public const FIRST_CLICK = 'first_click';

    public const LAST_CLICK = 'last_click';

    public const LAST_PAID_CLICK = 'last_paid_click';

    public const LINEAR = 'linear';

    public const POSITION_BASED = 'position_based';

    public const TIME_DECAY = 'time_decay';

    public const CUSTOM_WEIGHTED = 'custom_weighted';

    public const MODELS = [
        self::FIRST_CLICK, self::LAST_CLICK, self::LAST_PAID_CLICK,
        self::LINEAR, self::POSITION_BASED, self::TIME_DECAY, self::CUSTOM_WEIGHTED,
    ];

    public const DEFAULT_LOOKBACK_DAYS = 30;

    public const DEFAULT_HALF_LIFE_DAYS = 7;

    /** The usual position-based split: the first and the last touch each take 40%, the middle shares 20%. */
    public const DEFAULT_WEIGHTS = ['first' => 0.4, 'middle' => 0.2, 'last' => 0.4];

    /**
     * Normalise and validate a configuration, so an unknown model or an impossible number is refused where it
     * is entered, not discovered in a report.
     *
     * @param  array<string,mixed>  $raw
     * @return array{models: list<string>, lookback_days: int, half_life_days: int, weights: array{first: float, middle: float, last: float}}
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

        return ['models' => $models, 'lookback_days' => $lookback, 'half_life_days' => $halfLife, 'weights' => $weights];
    }

    /**
     * Share one order across its verified touches.
     *
     * @param  list<array{channel: string, at: CarbonInterface, is_paid: bool}>  $touches  every touch linked to the order, any order of time
     * @param  array{lookback_days: int, half_life_days: int, weights: array{first: float, middle: float, last: float}}  $config
     * @return array<string,float> channel => share, summing to 1.0
     */
    public static function split(string $model, array $touches, CarbonInterface $convertedAt, array $config): array
    {
        if (! in_array($model, self::MODELS, true)) {
            throw new InvalidArgumentException("Unknown attribution model: {$model}");
        }

        $since = $convertedAt->copy()->subDays($config['lookback_days']);
        $eligible = array_values(array_filter(
            $touches,
            static fn (array $t): bool => $t['at']->lte($convertedAt) && $t['at']->gte($since),
        ));
        usort($eligible, static fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        if ($model === self::LAST_PAID_CLICK) {
            $eligible = array_values(array_filter($eligible, static fn (array $t): bool => $t['is_paid']));
        }

        if ($eligible === []) {
            return [self::UNATTRIBUTED => 1.0];
        }

        $n = count($eligible);
        $shares = match ($model) {
            self::FIRST_CLICK => self::only(0, $n),
            self::LAST_CLICK, self::LAST_PAID_CLICK => self::only($n - 1, $n),
            self::LINEAR => array_fill(0, $n, 1 / $n),
            self::POSITION_BASED => self::byPosition($n, self::DEFAULT_WEIGHTS),
            self::CUSTOM_WEIGHTED => self::byPosition($n, $config['weights']),
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
    private static function only(int $at, int $n): array
    {
        $shares = array_fill(0, $n, 0.0);
        $shares[$at] = 1.0;

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

            // Two touches have no middle; only the ratio of the ends is kept.
            return $total > 0 ? [$w['first'] / $total, $w['last'] / $total] : [0.5, 0.5];
        }

        $shares = array_fill(0, $n, $w['middle'] / ($n - 2));
        $shares[0] = $w['first'];
        $shares[$n - 1] = $w['last'];

        return $shares;
    }

    /**
     * @param  list<array{channel: string, at: CarbonInterface, is_paid: bool}>  $touches
     * @return list<float>
     */
    private static function byDecay(array $touches, CarbonInterface $convertedAt, int $halfLife): array
    {
        $raw = [];
        foreach ($touches as $touch) {
            $days = max(0.0, ($convertedAt->getTimestamp() - $touch['at']->getTimestamp()) / 86400);
            $raw[] = 0.5 ** ($days / $halfLife);
        }
        $sum = array_sum($raw);

        return array_map(static fn (float $w): float => $w / $sum, $raw);
    }
}
