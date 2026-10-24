<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Metrics\Services\ReportingTimezone;
use Illuminate\Support\Carbon;

/**
 * DELIVERY-SETTINGS-001 — how one client's periodic delivery is prepared, kept in `settings.delivery` of the client.
 *
 *  - `weekly`     the client is opted in (the flag the sweep has always read — kept, so nothing already stored changes meaning)
 *  - `cadence`    `weekly` (default) or `monthly`
 *  - `week_start` the day a delivery week begins: `monday` (default, what every pack so far used) or `sunday`
 *  - `mode`       the report behind the pack: `snapshot` (fixed for its period, default) or `live` (follows the synced data)
 *  - `components` what the client receives by default (see DeliveryPack::COMPONENTS)
 *
 * The period is derived here and nowhere else: the sweep, the manual «prepare» and the overview's «what is due» all ask this class.
 */
final class DeliverySettings
{
    public const CADENCES = ['weekly', 'monthly'];

    public const WEEK_STARTS = ['monday' => Carbon::MONDAY, 'sunday' => Carbon::SUNDAY];

    public const MODES = ['snapshot', 'live'];

    /** @return array{enabled:bool,cadence:string,week_start:string,mode:string,components:array<string,bool>} */
    public static function for(ClientWorkspace $workspace): array
    {
        $s = (array) data_get($workspace->settings, 'delivery', []);

        return ['enabled' => ($s['weekly'] ?? false) === true] + self::sanitise($s);
    }

    /**
     * @param  array<string,mixed>  $in
     * @return array{cadence:string,week_start:string,mode:string,components:array<string,bool>}
     */
    public static function sanitise(array $in): array
    {
        $components = [];
        foreach (DeliveryPack::COMPONENTS as $key) {
            if (array_key_exists($key, (array) ($in['components'] ?? []))) {
                $components[$key] = filter_var($in['components'][$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return [
            'cadence' => in_array($in['cadence'] ?? null, self::CADENCES, true) ? (string) $in['cadence'] : 'weekly',
            'week_start' => array_key_exists((string) ($in['week_start'] ?? ''), self::WEEK_STARTS) ? (string) $in['week_start'] : 'monday',
            'mode' => in_array($in['mode'] ?? null, self::MODES, true) ? (string) $in['mode'] : 'snapshot',
            'components' => $components,
        ];
    }

    /**
     * @param  array<string,mixed>  $input  enabled, cadence, week_start, mode, components — only the keys present are changed
     * @return array{enabled:bool,cadence:string,week_start:string,mode:string,components:array<string,bool>}
     */
    public static function save(ClientWorkspace $workspace, array $input): array
    {
        $current = (array) data_get($workspace->settings, 'delivery', []);
        $merged = $current;
        foreach (['cadence', 'week_start', 'mode'] as $key) {
            if (array_key_exists($key, $input)) {
                $merged[$key] = $input[$key];
            }
        }
        if (array_key_exists('components', $input)) {
            $merged['components'] = array_replace((array) ($current['components'] ?? []), (array) $input['components']);
        }
        $clean = self::sanitise($merged);
        $enabled = array_key_exists('enabled', $input) ? filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) : (($current['weekly'] ?? false) === true);

        $settings = (array) $workspace->settings;
        $settings['delivery'] = array_merge($current, $clean, ['weekly' => $enabled]);
        $workspace->forceFill(['settings' => $settings])->save();

        return self::for($workspace);
    }

    /**
     * The period BEFORE the one containing $anchor — the one a delivery is prepared for.
     *
     * @param  array<string,mixed>  $settings  the output of for()
     * @return array{0:Carbon,1:Carbon}
     */
    public static function period(array $settings, ?Carbon $anchor = null): array
    {
        $day = ($anchor ?? Carbon::now(ReportingTimezone::DEFAULT))->copy()->startOfDay();

        if (($settings['cadence'] ?? 'weekly') === 'monthly') {
            $from = $day->copy()->startOfMonth()->subMonth();

            return [$from, $from->copy()->endOfMonth()->startOfDay()];
        }

        $start = $day->copy()->startOfWeek(self::WEEK_STARTS[$settings['week_start'] ?? 'monday'] ?? Carbon::MONDAY)->subWeek();

        return [$start, $start->copy()->addDays(6)];
    }

    /** Is $day the day this client's next delivery is prepared on (the first day of its week, or of the month)? */
    public static function isPrepareDay(array $settings, Carbon $day): bool
    {
        if (($settings['cadence'] ?? 'weekly') === 'monthly') {
            return $day->day === 1;
        }

        return $day->dayOfWeek === (self::WEEK_STARTS[$settings['week_start'] ?? 'monday'] ?? Carbon::MONDAY);
    }
}
