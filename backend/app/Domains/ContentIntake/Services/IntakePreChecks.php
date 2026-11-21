<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\ContentIntake\Models\ContentIntakeItem;
use Illuminate\Support\Facades\Storage;

/**
 * CONTENT-INTAKE-001 — the deterministic pre-checks of the quality gate.
 *
 * Every rule compares a MEASURED value (from the file header, the declared fields or the workspace's own items) with
 * a REFERENCE in config/content_intake.php, and reports both. A value that could not be measured is `not_measured`
 * with the reason — never a pass and never a guess. Nothing here estimates performance.
 *
 * A check: {key, status: pass|warn|fail|not_measured, params, reason_ar, reason_en}. The result is the worst status
 * (not_measured does not make a result worse; it is listed so the gap is visible).
 */
final class IntakePreChecks
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const NOT_MEASURED = 'not_measured';

    /** @return array{result: string, checks: list<array<string, mixed>>} */
    public function run(ContentIntakeItem $item): array
    {
        $checks = [
            $this->target($item),
            $this->assets($item),
            ...$this->specChecks($item),
            $this->copyLength($item),
            $this->utm($item),
            $this->naming($item),
            $this->duplicate($item),
        ];
        $checks = array_values(array_filter($checks, static fn ($c): bool => $c !== null));

        $result = self::PASS;
        foreach ($checks as $c) {
            if ($c['status'] === self::FAIL) {
                $result = self::FAIL;
                break;
            }
            if ($c['status'] === self::WARN) {
                $result = self::WARN;
            }
        }

        return ['result' => $result, 'checks' => $checks];
    }

    /** @param array<string, scalar|null> $params @return array<string, mixed> */
    private function check(string $key, string $status, array $params = []): array
    {
        $for = static function (string $locale) use ($key, $status, $params): string {
            $p = $params;
            if (isset($params['why'])) {
                $p['why_text'] = trans('content_intake.why.'.$params['why'], [], $locale);
            }
            if ($key === 'utm') {
                $p['detail'] = isset($params['why'])
                    ? trans('content_intake.why.'.$params['why'], [], $locale)
                    : trans('content_intake.utm_missing', ['missing' => $params['missing'] ?? ''], $locale);
            }
            if (isset($params['missing']) && $key === 'target') {
                $p['missing_text'] = implode(' + ', array_map(
                    static fn (string $m): string => trans('content_intake.fields.'.$m, [], $locale),
                    explode('+', (string) $params['missing']),
                ));
            }

            return trans("content_intake.checks.{$key}.{$status}", $p, $locale);
        };
        $ar = $for('ar');
        $en = $for('en');

        return ['key' => $key, 'status' => $status, 'params' => $params, 'reason_ar' => $ar, 'reason_en' => $en];
    }

    /** @return array<string, mixed> */
    private function target(ContentIntakeItem $item): array
    {
        $missing = [];
        if ($item->provider === null || $item->provider === '') {
            $missing[] = 'platform';
        }
        if ($item->objective === null || ! in_array($item->objective, $this->objectives(), true)) {
            $missing[] = 'objective';
        }

        return $missing === []
            ? $this->check('target', self::PASS)
            : $this->check('target', self::FAIL, ['missing' => implode('+', $missing)]);
    }

    /** @return list<string> */
    public function objectives(): array
    {
        return array_values(array_filter(ObjectiveFamily::values(), static fn (string $v): bool => $v !== 'unknown'));
    }

    /** @return array<string, mixed> */
    private function assets(ContentIntakeItem $item): array
    {
        $ok = match ($item->kind) {
            'image', 'video' => $item->path !== null || $item->drive_file_id !== null || $item->destination_url !== null,
            'copy' => trim((string) $item->copy_text) !== '' || trim((string) $item->headline) !== '',
            'link' => trim((string) $item->destination_url) !== '',
            default => false,
        };

        return $ok ? $this->check('assets', self::PASS) : $this->check('assets', self::FAIL, ['kind' => (string) $item->kind]);
    }

    /** @return list<array<string, mixed>> */
    private function specChecks(ContentIntakeItem $item): array
    {
        if (! in_array($item->kind, ['image', 'video'], true)) {
            return [];
        }
        $spec = $item->provider === null ? null : config("content_intake.specs.{$item->provider}");
        if (! is_array($spec) || ! isset($spec['kinds'][$item->kind])) {
            return [$this->check('spec', self::NOT_MEASURED, ['platform' => (string) $item->provider])];
        }
        /** @var array<string, mixed> $kind */
        $kind = $spec['kinds'][$item->kind];
        $out = [];

        // Format (mime).
        if ($item->mime === null) {
            $out[] = $this->check('format', self::NOT_MEASURED, ['why' => $item->source === 'drive' ? 'drive' : 'unread']);
        } else {
            $allowed = (array) ($kind['mime'] ?? []);
            $out[] = in_array($item->mime, $allowed, true)
                ? $this->check('format', self::PASS, ['measured' => $item->mime])
                : $this->check('format', self::FAIL, ['measured' => $item->mime, 'required' => implode(', ', $allowed)]);
        }

        // File size.
        if ($item->size_bytes === null) {
            $out[] = $this->check('file_size', self::NOT_MEASURED);
        } else {
            $max = (int) ($kind['max_bytes'] ?? 0);
            $out[] = $max > 0 && $item->size_bytes > $max
                ? $this->check('file_size', self::FAIL, ['measured' => $this->mb($item->size_bytes), 'required' => $this->mb($max)])
                : $this->check('file_size', self::PASS, ['measured' => $this->mb($item->size_bytes), 'required' => $this->mb($max)]);
        }

        // Dimensions: aspect ratio and resolution.
        $w = $item->width;
        $h = $item->height;
        if ($w === null || $h === null || $w <= 0 || $h <= 0) {
            $why = $item->source === 'drive' ? 'drive' : 'unread';
            $out[] = $this->check('aspect_ratio', self::NOT_MEASURED, ['why' => $why]);
            $out[] = $this->check('resolution', self::NOT_MEASURED, ['why' => $why]);
        } else {
            $ratio = $w / $h;
            $ratios = $this->ratiosFor($spec, $item->placement);
            $tol = (float) config('content_intake.tolerance', 0.03);
            $match = array_any($ratios, static fn (float $r): bool => abs($ratio - $r) / $r <= $tol);
            $out[] = $match
                ? $this->check('aspect_ratio', self::PASS, ['measured' => $w.'x'.$h, 'ratio' => round($ratio, 3)])
                : $this->check('aspect_ratio', self::FAIL, [
                    'measured' => $w.'x'.$h, 'ratio' => round($ratio, 3),
                    'required' => implode(' / ', array_map(static fn (float $r): string => (string) round($r, 3), $ratios)),
                ]);
            $min = (int) ($kind['min_side'] ?? 0);
            $out[] = $min > 0 && min($w, $h) < $min
                ? $this->check('resolution', self::FAIL, ['measured' => min($w, $h), 'required' => $min])
                : $this->check('resolution', self::PASS, ['measured' => min($w, $h), 'required' => $min]);
        }

        if ($item->kind === 'video') {
            if ($item->duration_ms === null) {
                $out[] = $this->check('duration', self::NOT_MEASURED, ['why' => $item->source === 'drive' ? 'drive' : 'unread']);
            } else {
                $sec = round($item->duration_ms / 1000, 1);
                $minS = (float) ($kind['min_seconds'] ?? 0);
                $maxS = (float) ($kind['max_seconds'] ?? PHP_INT_MAX);
                $out[] = ($sec < $minS || $sec > $maxS)
                    ? $this->check('duration', self::FAIL, ['measured' => $sec, 'required' => $minS.'-'.$maxS])
                    : $this->check('duration', self::PASS, ['measured' => $sec, 'required' => $minS.'-'.$maxS]);
            }
        }

        $safe = $this->safeZone($item);
        if ($safe !== null) {
            $out[] = $safe;
        }

        return $out;
    }

    /** @param array<string, mixed> $spec @return list<float> */
    private function ratiosFor(array $spec, ?string $placement): array
    {
        /** @var array<string, list<float>> $placements */
        $placements = (array) ($spec['placements'] ?? []);
        if ($placement !== null && isset($placements[$placement])) {
            return array_map('floatval', $placements[$placement]);
        }
        $all = [];
        foreach ($placements as $list) {
            foreach ($list as $r) {
                $all[(string) $r] = (float) $r;
            }
        }

        return array_values($all);
    }

    private function mb(int $bytes): string
    {
        return (string) round($bytes / 1048576, 2);
    }

    /**
     * Safe-zone HEURISTIC for a tall placement and a raster image: the detail density (mean luminance gradient)
     * of the top and bottom bands the platform UI covers, against the centre. It is measured, but it is a
     * heuristic about «busy» pixels — it cannot read text — so the best it says is a warning, never a failure.
     *
     * @return array<string, mixed>|null
     */
    private function safeZone(ContentIntakeItem $item): ?array
    {
        $zone = $item->placement === null ? null : config("content_intake.safe_zone.{$item->placement}");
        if (! is_array($zone)) {
            return null;
        }
        if ($item->kind !== 'image' || $item->path === null || $item->disk === null) {
            return $this->check('safe_zone', self::NOT_MEASURED, ['why' => $item->kind === 'video' ? 'video' : 'unread']);
        }
        $max = (float) config('content_intake.safe_zone_max_megapixels', 25);
        if (($item->width ?? 0) * ($item->height ?? 0) > $max * 1_000_000 || ! function_exists('imagecreatefromstring')) {
            return $this->check('safe_zone', self::NOT_MEASURED, ['why' => 'too_large']);
        }
        $disk = Storage::disk($item->disk);
        if (! $disk->exists($item->path)) {
            return $this->check('safe_zone', self::NOT_MEASURED, ['why' => 'unread']);
        }
        $density = $this->density((string) $disk->get($item->path), (float) $zone['top'], (float) $zone['bottom']);
        if ($density === null) {
            return $this->check('safe_zone', self::NOT_MEASURED, ['why' => 'unread']);
        }
        [$top, $bottom, $centre] = $density;
        $factor = (float) config('content_intake.safe_zone_detail_ratio', 1.5);
        $params = ['top' => round($top, 1), 'bottom' => round($bottom, 1), 'centre' => round($centre, 1), 'factor' => $factor];
        if ($centre <= 0.0) {
            return $this->check('safe_zone', self::NOT_MEASURED, ['why' => 'flat']);
        }

        return ($top > $centre * $factor || $bottom > $centre * $factor)
            ? $this->check('safe_zone', self::WARN, $params)
            : $this->check('safe_zone', self::PASS, $params);
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    private function density(string $bytes, float $top, float $bottom): ?array
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }
        $small = imagescale($img, 96, -1);
        imagedestroy($img);
        if ($small === false) {
            return null;
        }
        $w = imagesx($small);
        $h = imagesy($small);
        $lum = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($small, $x, $y);
                $lum[$y][$x] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            }
        }
        imagedestroy($small);
        $rowEnergy = [];
        for ($y = 0; $y < $h - 1; $y++) {
            $sum = 0.0;
            for ($x = 0; $x < $w - 1; $x++) {
                $sum += abs($lum[$y][$x] - $lum[$y][$x + 1]) + abs($lum[$y][$x] - $lum[$y + 1][$x]);
            }
            $rowEnergy[$y] = $sum / max(1, $w - 1);
        }
        $n = count($rowEnergy);
        if ($n < 4) {
            return null;
        }
        $mean = static fn (array $rows): float => $rows === [] ? 0.0 : array_sum($rows) / count($rows);
        $t = (int) max(1, floor($n * $top));
        $b = (int) max(1, floor($n * $bottom));

        return [
            $mean(array_slice($rowEnergy, 0, $t)),
            $mean(array_slice($rowEnergy, $n - $b)),
            $mean(array_slice($rowEnergy, $t, max(1, $n - $t - $b))),
        ];
    }

    /** @return array<string, mixed>|null */
    private function copyLength(ContentIntakeItem $item): ?array
    {
        $limits = $item->provider === null ? null : config("content_intake.specs.{$item->provider}.copy");
        if (! is_array($limits)) {
            return null;
        }
        $over = [];
        foreach ($limits as $field => $limit) {
            $len = mb_strlen(trim((string) ($item->{$field} ?? '')));
            if ($len > (int) $limit) {
                $over[] = $field.' '.$len.'/'.$limit;
            }
        }
        if ($over === []) {
            return trim((string) $item->headline.(string) $item->copy_text) === '' ? null : $this->check('copy_length', self::PASS);
        }

        return $this->check('copy_length', self::WARN, ['fields' => implode(', ', $over)]);
    }

    /** @return array<string, mixed>|null */
    private function utm(ContentIntakeItem $item): ?array
    {
        $url = trim((string) $item->destination_url);
        if ($url === '') {
            return in_array($item->kind, ['image', 'video', 'copy'], true) ? $this->check('utm', self::WARN, ['why' => 'no_url']) : null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $missing = array_values(array_filter(['utm_source', 'utm_medium', 'utm_campaign'], static fn (string $k): bool => ! isset($q[$k]) || $q[$k] === ''));

        return $missing === [] ? $this->check('utm', self::PASS) : $this->check('utm', self::WARN, ['missing' => implode(', ', $missing)]);
    }

    /** @return array<string, mixed> */
    private function naming(ContentIntakeItem $item): array
    {
        $pattern = config('content_intake.naming_pattern');
        if (! is_string($pattern) || $pattern === '') {
            return $this->check('naming', self::NOT_MEASURED);
        }

        return @preg_match($pattern, $item->name) === 1
            ? $this->check('naming', self::PASS)
            : $this->check('naming', self::WARN, ['pattern' => $pattern]);
    }

    /** @return array<string, mixed>|null */
    private function duplicate(ContentIntakeItem $item): ?array
    {
        if ($item->content_hash === null) {
            return null;
        }
        $twin = ContentIntakeItem::query()->where('content_hash', $item->content_hash)->where('id', '!=', $item->id)
            ->whereIn('status', ['in_review', 'needs_changes', 'approved', 'live'])->orderBy('created_at')->first();
        if ($twin === null) {
            return $this->check('duplicate', self::PASS);
        }
        $params = ['name' => $twin->name, 'status' => $twin->status];

        return $twin->project_id === $item->project_id
            ? $this->check('duplicate', self::FAIL, $params)
            : $this->check('duplicate', self::WARN, $params);
    }
}
