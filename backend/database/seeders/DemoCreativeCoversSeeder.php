<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * CREATIVE-PREVIEWS-001 — one demo creative per platform holds a cached cover, the way a synced one does.
 *
 * The demo world never fabricates a provider's asset (`DemoCreativesSeeder` leaves every link null), so no
 * demo card ever travelled the path the Owner's 2026-11-07 report was about: a copy in our storage, linked
 * as `/media/covers/…`, reaching the browser through the SPA's own origin. This gives the image template
 * («Product Shot») of the first demo campaign on Meta, TikTok and Snapchat a cover in that store — a plain
 * tinted panel with the platform's name, plainly a demo — so the library, the drawer and the gate can show
 * that the path works end to end. Each row also keeps a provider-shaped signed link, which must never be
 * what the browser loads.
 */
final class DemoCreativeCoversSeeder extends Seeder
{
    /** @var array<string, array{0: array{int,int,int}, 1: ?string}> tint and the provider-shaped signed link the browser must not load */
    private const PROVIDERS = [
        'meta' => [[24, 119, 242], 'https://scontent-fra3-1.xx.fbcdn.net/v/demo-cover.jpg?oe=6A000000'],
        'tiktok' => [[37, 244, 238], 'https://p16-common-sign.tiktokcdn.com/obj/demo-cover.jpg?x-expires=1762000000&x-signature=demo'],
        'snapchat' => [[255, 252, 0], null],
    ];

    private const STORE_PROJECT = 'متجر تجريبي — Demo';

    public function run(): void
    {
        $seeded = 0;
        foreach (self::PROVIDERS as $provider => [$tint, $signed]) {
            $creative = ExternalCreative::withoutGlobalScopes()
                ->where('provider', $provider)->where('is_demo', true)->where('client_display_name', 'Product Shot')
                // The agency's store project when it holds one: the project the content specs stand in.
                ->orderByRaw('CASE WHEN project_id IN (SELECT id FROM projects WHERE name = ?) THEN 0 ELSE 1 END', [self::STORE_PROJECT])
                ->orderBy('external_creative_id')->first();
            if ($creative === null || ! function_exists('imagecreatetruecolor')) {
                continue;
            }

            $name = hash_hmac('sha256', (string) $creative->getKey(), (string) config('app.key')).'.png';
            $path = substr($name, 0, 2).'/'.$name;
            Storage::disk(CoverCache::DISK)->put(CoverCache::ROOT.'/'.$path, $this->panel($tint, strtoupper($provider)));

            $creative->forceFill([
                'thumbnail_url' => $signed,
                'cover_path' => $path,
                'cover_cached_at' => Carbon::now(),
                'cover_state' => CoverCache::STATE_CACHED,
                'cover_error' => null,
                'cover_attempts' => 0,
                'cover_next_attempt_at' => null,
                'width' => 640,
                'height' => 640,
            ])->save();
            $seeded++;
        }

        $this->command?->info("Seeded {$seeded} demo creative cover(s).");
    }

    /** @param array{int,int,int} $tint */
    private function panel(array $tint, string $label): string
    {
        $image = imagecreatetruecolor(640, 640);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $tint[0], $tint[1], $tint[2]));
        $ink = (int) imagecolorallocate($image, 17, 17, 17);
        imagefilledrectangle($image, 40, 280, 600, 360, (int) imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 60, 312, "DEMO COVER - {$label}", $ink);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
