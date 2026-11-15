<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Charts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Chart images through resvg (spec §11): SVG from the same chart config → PNG at 2x, light and dark.
 *
 * - Route chosen: the npm package `@resvg/resvg-js` (prebuilt binaries incl. Alpine/musl) driven by `scripts/chart-render.mjs`
 *   on the Node runtime the production image already carries for reports — no Rust toolchain, no headless browser, no PDF.
 * - Cached per (workspace, project + period + type + theme, spec hash) in the application cache for `cache_hours`, so the
 *   same digest to ten recipients renders once.
 * - Batched: one Node process per message for every chart not in the cache.
 * - Budgets: a chart over `max_ms` (300) or over `max_png_bytes` (light + dark of all charts ≤ 400 KB) is dropped and the
 *   table stands in; so does any failure (no Node, no module, a resvg error) — never a broken image, never an exception.
 */
final class ResvgCharts implements ChartImages
{
    public function render(string $tenantId, string $cacheScope, array $specs): array
    {
        $out = [];
        $todo = [];
        foreach ($specs as $spec) {
            $key = (string) $spec['key'];
            $out[$key] = null;
            $cacheKey = 'notif-chart:v1:'.$tenantId.':'.sha1($cacheScope.'|'.json_encode($spec));
            $hit = Cache::get($cacheKey);
            if (is_array($hit) && isset($hit['light'], $hit['dark'])) {
                $out[$key] = ['light' => (string) base64_decode((string) $hit['light']), 'dark' => (string) base64_decode((string) $hit['dark']), 'width' => (int) $hit['width'], 'height' => (int) $hit['height'], 'ms' => 0.0];

                continue;
            }
            $light = ChartSvg::for($spec, 'light');
            $dark = ChartSvg::for($spec, 'dark');
            if ($light === null || $dark === null) {
                continue;
            }
            $todo[$key] = ['cache' => $cacheKey, 'light' => $light, 'dark' => $dark];
        }
        if ($todo === []) {
            return $this->withinSize($out);
        }

        $charts = [];
        foreach ($todo as $key => $t) {
            $charts[] = ['key' => $key.'|light', 'svg' => $t['light']['svg'], 'width' => $t['light']['width'] * 2];
            $charts[] = ['key' => $key.'|dark', 'svg' => $t['dark']['svg'], 'width' => $t['dark']['width'] * 2];
        }
        $rendered = $this->run($charts);
        $maxMs = (float) config('notifications_v2.external.charts.max_ms', 300);

        foreach ($todo as $key => $t) {
            $l = $rendered[$key.'|light'] ?? null;
            $d = $rendered[$key.'|dark'] ?? null;
            if (! is_array($l) || ! is_array($d) || ! isset($l['png'], $d['png'])) {
                continue;
            }
            $ms = max((float) ($l['ms'] ?? 0), (float) ($d['ms'] ?? 0));
            if ($ms > $maxMs) {
                Log::info('notifications.external.chart_over_budget', ['chart' => $key, 'ms' => $ms]);

                continue;
            }
            $entry = ['light' => (string) $l['png'], 'dark' => (string) $d['png'], 'width' => $t['light']['width'] * 2, 'height' => $t['light']['height'] * 2];
            Cache::put($t['cache'], $entry, now()->addHours((int) config('notifications_v2.external.charts.cache_hours', 24)));
            $out[$key] = ['light' => (string) base64_decode($entry['light']), 'dark' => (string) base64_decode($entry['dark']), 'width' => $entry['width'], 'height' => $entry['height'], 'ms' => $ms];
        }

        return $this->withinSize($out);
    }

    public static function available(): bool
    {
        $base = self::requireBase();

        return (bool) config('notifications_v2.external.charts.enabled', true) && is_file($base)
            && is_dir(dirname($base).'/node_modules/@resvg/resvg-js');
    }

    public static function requireBase(): string
    {
        return (string) (config('notifications_v2.external.charts.require_base') ?: config('reports.chromium.require_base') ?: base_path('../frontend/package.json'));
    }

    /**
     * @param  list<array{key: string, svg: string, width: int}>  $charts
     * @return array<string, mixed>
     */
    private function run(array $charts): array
    {
        if (! self::available()) {
            return [];
        }
        try {
            $process = new Process([(string) config('notifications_v2.external.charts.node', 'node'), base_path('scripts/chart-render.mjs')]);
            $process->setInput((string) json_encode(['requireBase' => self::requireBase(), 'charts' => $charts]));
            $process->setTimeout((float) config('notifications_v2.external.charts.timeout_seconds', 10));
            $process->run();
            if (! $process->isSuccessful()) {
                Log::warning('notifications.external.chart_render_failed', ['exit' => $process->getExitCode()]);

                return [];
            }
            $decoded = json_decode($process->getOutput(), true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            Log::warning('notifications.external.chart_render_failed', ['error' => class_basename($e)]);

            return [];
        }
    }

    /**
     * Images over the size budget fall back to tables, largest first.
     *
     * @param  array<string, array{light: string, dark: string, width: int, height: int, ms: float}|null>  $out
     * @return array<string, array{light: string, dark: string, width: int, height: int, ms: float}|null>
     */
    private function withinSize(array $out): array
    {
        $limit = (int) config('notifications_v2.external.charts.max_png_bytes', 400 * 1024);
        $size = static fn (array $o): int => array_sum(array_map(static fn ($c): int => $c === null ? 0 : strlen($c['light']) + strlen($c['dark']), $o));
        while ($size($out) > $limit) {
            $largest = null;
            foreach ($out as $k => $c) {
                if ($c !== null && ($largest === null || strlen($c['light']) > strlen((string) $out[$largest]['light']))) {
                    $largest = $k;
                }
            }
            if ($largest === null) {
                break;
            }
            $out[$largest] = null;
        }

        return $out;
    }
}
