<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Settings;

use App\Domains\Metrics\Services\AttributionCredit;
use InvalidArgumentException;

/**
 * ATTRIB-PRESETS-001 — four presets over the one credit engine, and the advanced options.
 *
 * BASIC is last touch only; STANDARD adds first touch and linear; ADVANCED adds position-based and
 * time-decay and switches on the advanced options (consent honoured, refunds excluded, eligible
 * channels); CUSTOM is the weights themselves plus whatever models the Owner picks. Advanced is
 * OFF by default: a project that never chose is BASIC. A preset is a NAMED configuration of the
 * same engine — `AttributionCredit::config` still validates every value, so a preset cannot ask
 * for anything the engine cannot run.
 */
final class AttributionPresets
{
    public const BASIC = 'basic';

    public const STANDARD = 'standard';

    public const ADVANCED = 'advanced';

    public const CUSTOM = 'custom';

    public const ALL = [self::BASIC, self::STANDARD, self::ADVANCED, self::CUSTOM];

    public const DEFAULT = self::BASIC;

    /** @var array<string, list<string>> the models each preset runs, in display order */
    public const MODELS = [
        self::BASIC => [AttributionCredit::LAST_CLICK],
        self::STANDARD => [AttributionCredit::LAST_CLICK, AttributionCredit::FIRST_CLICK, AttributionCredit::LINEAR],
        self::ADVANCED => [AttributionCredit::LAST_CLICK, AttributionCredit::FIRST_CLICK, AttributionCredit::LINEAR, AttributionCredit::POSITION_BASED, AttributionCredit::TIME_DECAY],
    ];

    /** The advanced options and their defaults when the preset switches them on. */
    public const ADVANCED_DEFAULTS = ['honour_consent' => true, 'exclude_refunds' => true, 'eligible_channels' => null];

    /** @return array<string, array{label_ar: string, label_en: string, models: list<string>, advanced: bool}> */
    public static function catalogue(): array
    {
        return [
            self::BASIC => ['label_ar' => 'أساسي — اللمسة الأخيرة', 'label_en' => 'Basic — last touch', 'models' => self::MODELS[self::BASIC], 'advanced' => false],
            self::STANDARD => ['label_ar' => 'قياسي — الأخيرة والأولى والخطي', 'label_en' => 'Standard — last, first and linear', 'models' => self::MODELS[self::STANDARD], 'advanced' => false],
            self::ADVANCED => ['label_ar' => 'متقدم — مع الموضع وتضاؤل الزمن والموافقة والمرتجعات', 'label_en' => 'Advanced — adds position, time decay, consent and refunds', 'models' => self::MODELS[self::ADVANCED], 'advanced' => true],
            self::CUSTOM => ['label_ar' => 'مخصّص — الأوزان نفسها', 'label_en' => 'Custom — the weights themselves', 'models' => [AttributionCredit::CUSTOM_WEIGHTED], 'advanced' => true],
        ];
    }

    /**
     * The raw configuration a preset stands for, merged with what the request may still set.
     *
     * BASIC and STANDARD ignore models and weights sent beside them — a preset is a decision, not a
     * suggestion — but keep the lookback and half-life the Owner typed. ADVANCED does the same and
     * takes the advanced options. CUSTOM takes everything the Owner sent, and requires weights.
     *
     * @param  array<string, mixed>  $raw
     * @return array{config: array<string, mixed>, advanced: array<string, mixed>|null}
     */
    public static function resolve(string $preset, array $raw): array
    {
        if (! in_array($preset, self::ALL, true)) {
            throw new InvalidArgumentException("Unknown attribution preset: {$preset}");
        }

        $keep = array_intersect_key($raw, array_flip(['lookback_days', 'half_life_days']));

        if ($preset === self::CUSTOM) {
            if (! isset($raw['weights']) || ! is_array($raw['weights'])) {
                throw new InvalidArgumentException('A custom preset needs its weights (first, middle, last).');
            }
            $models = array_values(array_unique(array_merge((array) ($raw['models'] ?? []), [AttributionCredit::CUSTOM_WEIGHTED])));

            return ['config' => $keep + ['models' => $models, 'weights' => $raw['weights']], 'advanced' => self::advanced($raw['advanced'] ?? [])];
        }

        $config = $keep + ['models' => self::MODELS[$preset]];

        return ['config' => $config, 'advanced' => $preset === self::ADVANCED ? self::advanced($raw['advanced'] ?? []) : null];
    }

    /**
     * @return array{honour_consent: bool, exclude_refunds: bool, eligible_channels: list<string>|null}
     */
    public static function advanced(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $channels = $raw['eligible_channels'] ?? null;
        if ($channels !== null) {
            if (! is_array($channels) || $channels === []) {
                throw new InvalidArgumentException('Eligible channels is a non-empty list of channel keys, or absent for every channel.');
            }
            $channels = array_values(array_unique(array_map('strval', $channels)));
        }

        return [
            'honour_consent' => (bool) ($raw['honour_consent'] ?? self::ADVANCED_DEFAULTS['honour_consent']),
            'exclude_refunds' => (bool) ($raw['exclude_refunds'] ?? self::ADVANCED_DEFAULTS['exclude_refunds']),
            'eligible_channels' => $channels,
        ];
    }
}
