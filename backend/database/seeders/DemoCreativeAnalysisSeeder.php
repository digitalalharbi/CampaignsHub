<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ten creative cases §15.16 requires, as data somebody can actually look at.
 *
 * Every one of them exists because a page that only ever sees healthy content cannot be reviewed:
 *
 *   1. **Awareness image** and **2. awareness video** — judged on reach and attention, never on CPA.
 *   3. **Traffic image** — clicks and cost per landing-page view.
 *   4. **Sales video** — the case where video metrics AND orders both matter.
 *   5. **Carousel** — a format neither image nor video, so the player must not assume.
 *   6. **The same video on two platforms** — grouped by file hash, which is what makes cross-platform
 *      reading real rather than theoretical.
 *   7. **High spend, no results** — the creative somebody must be told to switch off.
 *   8. **Improving** — so «improving» is a verdict the UI has to render, not a branch nobody hits.
 *   9. **Fatigued** — frequency up, CTR down, CPA up, spend up with flat orders: the pattern, not one
 *      threshold.
 *  10. **Insufficient data** — three days old. The verdict that must NOT read as «stable».
 *
 * Video metrics are written ONLY where the platform would report them. The traffic image and the
 * carousel leave every video column NULL, because that is what makes «Not provided» testable — and a
 * demo that filled them with zeros would prove the opposite of what §15.4 asks for.
 *
 * Deterministic and idempotent. DEVELOPMENT ONLY.
 */
final class DemoCreativeAnalysisSeeder extends Seeder
{
    /**
     * SIXTY days, so a thirty-day window has a thirty-day window before it.
     *
     * `CreativeFatigue` refuses to judge without a previous period, and rightly so — but seeded with
     * thirty days every case came back `insufficient_data`, including the two whose whole purpose is
     * to render «fatigued» and «improving». A fixture that cannot reach the state it exists to
     * demonstrate is not a fixture.
     */
    private const DAYS = 60;

    /** Reserved prefix — `demo:remove` matches on it, and no synced creative can carry it. */
    public const EXTERNAL_ID_PREFIX = 'demo-creative-';

    /**
     * The one playable file every video fixture points at, under this app's own `public/`.
     *
     * Served by the application rather than fetched from anywhere, so the player works on a laptop
     * with no network and no credentials — the same reason the images are inline data URIs.
     */
    public const SAMPLE_VIDEO = '/demo/creative-sample.mp4';

    public function run(): void
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            $this->command?->warn('Creative demo data is development-only — skipped.');

            return;
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->where('slug', 'demo-agency')->first();
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->getKey());

        $workspace = ClientWorkspace::query()->where('slug', DemoClientPortalSeeder::WORKSPACE_SLUG)->first();
        if ($workspace === null) {
            app(TenantContext::class)->forget();

            return;
        }

        $project = Project::query()
            ->where('client_workspace_id', $workspace->getKey())
            ->where('name', 'Q3 Launch — Demo')
            ->first();

        if ($project === null) {
            app(TenantContext::class)->forget();

            return;
        }

        $campaigns = UnifiedCampaign::query()
            ->where('project_id', $project->getKey())
            ->get()
            ->keyBy('objective');

        $today = Carbon::today();

        foreach ($this->cases() as $case) {
            $campaign = $campaigns->get($case['objective']) ?? $campaigns->first();
            if ($campaign === null) {
                continue;
            }

            $creative = ExternalCreative::updateOrCreate(
                ['external_creative_id' => self::EXTERNAL_ID_PREFIX.$case['key']],
                [
                    'tenant_id' => $tenant->getKey(),
                    'project_id' => $project->getKey(),
                    'campaign_id' => $campaign->getKey(),
                    'provider' => $case['provider'],
                    'external_ad_id' => 'demo-ad-'.$case['key'],
                    'name' => $case['name'],
                    'format' => $case['format'],
                    'status' => $case['status'] ?? 'active',
                    'width' => $case['width'] ?? null,
                    'height' => $case['height'] ?? null,
                    'aspect_ratio' => $case['aspect_ratio'] ?? null,
                    'duration_seconds' => $case['duration'] ?? null,
                    'file_hash' => $case['hash'] ?? null,
                    'body' => $case['body'] ?? null,
                    'headline' => $case['headline'] ?? null,
                    'cta' => $case['cta'] ?? null,
                    'destination_url' => 'https://example.com/demo-store',
                    /*
                     * A real, self-contained SVG data URI.
                     *
                     * Not a link to a stock photo service, and not a platform CDN URL that would 404
                     * on every install: the preview has to render offline, on a laptop with no
                     * credentials, or «the image appears» cannot be demonstrated at all. Data URIs
                     * also carry no token and cannot expire, which keeps the demo out of the two
                     * hazards the presenter exists to handle.
                     */
                    'thumbnail_url' => $this->placeholder($case['name'], $case['tint']),
                    'asset_url' => $this->placeholder($case['name'], $case['tint']),
                    /*
                     * A real, playable file for the video cases — nothing else proves §15.4.
                     *
                     * Every video fixture used to carry `video_url = null`, so the library showed a
                     * poster with a play button and the player had nothing to open. «تشغيل الفيديو
                     * فعليًا» is an acceptance test, and it cannot be met by a control that looks
                     * right; a reviewer pressing play on all four video cases got a dead button.
                     *
                     * 27 KB of solid colour and a tone, generated once and served from this app's own
                     * `public/`, so it plays offline with no credentials and cannot 404 on a fresh
                     * install. Deliberately dull: it is scaffolding for the player, not content, and
                     * nobody should mistake it for a real ad.
                     */
                    'video_url' => str_contains((string) $case['format'], 'video')
                        ? rtrim((string) config('app.url'), '/').self::SAMPLE_VIDEO
                        : null,
                    'first_seen_at' => $today->copy()->subDays($case['age']),
                    'last_active_at' => $today->copy()->subDays($case['idle'] ?? 0),
                    'source_updated_at' => $today->copy()->subDay(),
                    'last_synced_at' => Carbon::now(),
                    'source_type' => 'demo',
                    'is_demo' => true,
                ],
            );

            $this->metrics($creative, $case, $today);
        }

        $this->groupTheSharedVideo($tenant, $project);

        app(TenantContext::class)->forget();

        $this->command?->info('Demo: ten creative cases seeded — awareness, traffic, sales, carousel, cross-platform, fatigued, improving and insufficient-data.');
    }

    /**
     * The ten cases. `shape` drives how the daily numbers move over the window.
     *
     * @return list<array<string, mixed>>
     */
    private function cases(): array
    {
        return [
            [
                'key' => 'awareness-image', 'name' => 'اليوم الوطني — صورة', 'format' => 'image',
                'objective' => 'awareness', 'provider' => 'meta', 'tint' => '#2f6f4e', 'age' => 40,
                'width' => 1080, 'height' => 1080, 'aspect_ratio' => '1:1',
                'headline' => 'يوم وطني سعيد', 'cta' => 'LEARN_MORE',
                'shape' => 'steady', 'video' => false, 'sales' => false,
            ],
            [
                'key' => 'awareness-video', 'name' => 'اليوم الوطني — فيديو', 'format' => 'video',
                'objective' => 'awareness', 'provider' => 'tiktok', 'tint' => '#1f4f7a', 'age' => 38,
                'width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16', 'duration' => 22,
                'headline' => 'قصة علامتنا', 'cta' => 'WATCH_MORE',
                'shape' => 'steady', 'video' => true, 'sales' => false, 'hash' => 'demo-hash-hero-video',
            ],
            [
                'key' => 'traffic-image', 'name' => 'تخفيضات — بانر الزيارات', 'format' => 'image',
                'objective' => 'traffic', 'provider' => 'google', 'tint' => '#8a5a1f', 'age' => 30,
                'width' => 1200, 'height' => 628, 'aspect_ratio' => '1.91:1',
                'headline' => 'تصفّح العروض', 'cta' => 'SHOP_NOW',
                'shape' => 'steady', 'video' => false, 'sales' => false,
            ],
            [
                'key' => 'sales-video', 'name' => 'العرض الأخير — فيديو المبيعات', 'format' => 'video',
                'objective' => 'sales', 'provider' => 'meta', 'tint' => '#6a2f6f', 'age' => 32,
                'width' => 1080, 'height' => 1350, 'aspect_ratio' => '4:5', 'duration' => 15,
                'headline' => 'خصم 40% ينتهي الليلة', 'cta' => 'SHOP_NOW',
                'shape' => 'steady', 'video' => true, 'sales' => true,
            ],
            [
                'key' => 'carousel', 'name' => 'المنتجات الأكثر مبيعًا — كاروسيل', 'format' => 'carousel',
                'objective' => 'sales', 'provider' => 'snapchat', 'tint' => '#7a1f3f', 'age' => 25,
                'width' => 1080, 'height' => 1080, 'aspect_ratio' => '1:1',
                'headline' => 'اختر منتجك', 'cta' => 'SHOP_NOW',
                'shape' => 'steady', 'video' => false, 'sales' => true,
            ],
            [
                // The other half of the cross-platform pair — same hash as `awareness-video`.
                'key' => 'shared-video-snap', 'name' => 'قصة علامتنا — نسخة سناب', 'format' => 'video',
                'objective' => 'awareness', 'provider' => 'snapchat', 'tint' => '#1f4f7a', 'age' => 38,
                'width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16', 'duration' => 22,
                'headline' => 'قصة علامتنا', 'cta' => 'WATCH_MORE',
                'shape' => 'steady', 'video' => true, 'sales' => false, 'hash' => 'demo-hash-hero-video',
            ],
            [
                'key' => 'burner', 'name' => 'إنفاق بلا نتائج — صورة', 'format' => 'image',
                'objective' => 'sales', 'provider' => 'meta', 'tint' => '#6f2f2f', 'age' => 28,
                'width' => 1080, 'height' => 1080, 'aspect_ratio' => '1:1',
                'headline' => 'جرّبنا الآن', 'cta' => 'SHOP_NOW',
                'shape' => 'burner', 'video' => false, 'sales' => true,
            ],
            [
                'key' => 'improving', 'name' => 'نسخة محسّنة — فيديو قصير', 'format' => 'video',
                'objective' => 'sales', 'provider' => 'tiktok', 'tint' => '#2f5f6f', 'age' => 26,
                'width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16', 'duration' => 9,
                'headline' => 'ثلاث ثوانٍ تكفي', 'cta' => 'SHOP_NOW',
                'shape' => 'improving', 'video' => true, 'sales' => true,
            ],
            [
                'key' => 'fatigued', 'name' => 'حملة مستمرة — صورة متعبة', 'format' => 'image',
                'objective' => 'sales', 'provider' => 'meta', 'tint' => '#5f5f2f', 'age' => 60,
                'width' => 1080, 'height' => 1080, 'aspect_ratio' => '1:1',
                'headline' => 'العرض مستمر', 'cta' => 'SHOP_NOW',
                'shape' => 'fatigued', 'video' => false, 'sales' => true,
            ],
            [
                'key' => 'too-new', 'name' => 'إعلان جديد — بيانات غير كافية', 'format' => 'image',
                'objective' => 'sales', 'provider' => 'google', 'tint' => '#3f3f5f', 'age' => 3,
                'width' => 1200, 'height' => 628, 'aspect_ratio' => '1.91:1',
                'headline' => 'وصل حديثًا', 'cta' => 'SHOP_NOW',
                'shape' => 'new', 'video' => false, 'sales' => true,
            ],
        ];
    }

    /**
     * Daily rows shaped to the case, deterministic, with NULL where the platform reports nothing.
     *
     * @param  array<string, mixed>  $case
     */
    private function metrics(ExternalCreative $creative, array $case, Carbon $today): void
    {
        DB::table('creative_daily_metrics')->where('creative_id', $creative->getKey())->delete();

        $days = $case['shape'] === 'new' ? 3 : self::DAYS;
        $start = $today->copy()->subDays($days - 1);
        $rows = [];

        for ($d = 0; $d < $days; $d++) {
            $date = $start->copy()->addDays($d);
            $t = $days === 1 ? 1.0 : $d / ($days - 1);   // 0 → 1 across the window

            // Each shape moves the same four levers differently. `fatigued` is the one that has to
            // move SEVERAL at once, because a single lever is the threshold rule §15.9 rejects.
            [$impressionScale, $ctr, $cvr, $frequency] = match ($case['shape']) {
                'fatigued' => [1.0 + 0.5 * $t, 0.022 - 0.013 * $t, 0.030 - 0.020 * $t, 1.8 + 3.2 * $t],
                'improving' => [1.0 + 0.3 * $t, 0.014 + 0.014 * $t, 0.015 + 0.020 * $t, 1.6 + 0.2 * $t],
                'burner' => [1.0 + 0.2 * $t, 0.018, 0.0004, 2.0 + 0.4 * $t],
                default => [1.0 + 0.1 * $t, 0.019, 0.022, 1.9 + 0.3 * $t],
            };

            /*
             * A scale of its own per creative, so no two are identical.
             *
             * Every `default`-shape case ran off the same 24,000 base, so the awareness image and the
             * sales video reported the SAME spend to the riyal and the same impressions to the unit.
             * A comparison of two creatives whose every delivery figure matches exactly reads as a
             * broken join, and «sort by spend» had nothing to sort. Derived from the case key so it
             * stays deterministic across re-seeds — this is a fixture, not a random walk.
             */
            $spread = 0.55 + (crc32($case['key']) % 90) / 100;

            $impressions = round(24000 * $spread * $impressionScale * (1 + 0.12 * sin($d / 4.0)));
            $clicks = round($impressions * $ctr);
            $conversions = $case['sales'] ? round($clicks * $cvr) : null;
            $spend = round($clicks * 1.35, 2);
            $revenue = $conversions === null ? null : round($conversions * 340, 2);

            $row = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $creative->tenant_id,
                'project_id' => $creative->project_id,
                'creative_id' => $creative->getKey(),
                'campaign_id' => $creative->campaign_id,
                'metric_date' => $date->toDateString(),
                'spend' => $spend,
                'impressions' => $impressions,
                'clicks' => $clicks,
                /*
                 * NULL for a creative that was never bought to sell — not zero.
                 *
                 * These were written as `?? 0`, so an awareness image reported `conversions = 0` and
                 * `revenue = 0` as MEASURED values. The consequence showed up the moment two
                 * creatives were compared: the awareness image sat beside the sales video reading
                 * «ROAS 0.00×», which says its return was nothing, when the truth is that no return
                 * was ever reported for it. That is exactly the awareness/sales mixing the contract
                 * forbids, produced by the very fixtures meant to demonstrate the rule.
                 *
                 * NULL makes the sum NULL, `reported` false, and the surfaces say «غير مُرسَل».
                 */
                'conversions' => $conversions,
                'revenue' => $revenue,
                'reach' => round($impressions / max($frequency, 1)),
                'frequency' => round($frequency, 2),
                'landing_page_views' => round($clicks * 0.86),
                'is_demo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($case['sales']) {
                $row['add_to_cart'] = round($clicks * 0.09);
                $row['checkout'] = round($clicks * 0.04);
                $row['purchases'] = $conversions;
            }

            /*
             * Video columns ONLY for video creatives.
             *
             * An image ad leaves every one of them NULL, which is what makes «Not provided» real: a
             * demo that wrote zeros here would render «completion rate 0%» on a still image and prove
             * exactly the failure §15.4 exists to prevent.
             */
            if ($case['video']) {
                $views = round($impressions * 0.42);
                $row['video_views'] = $views;
                $row['video_views_2s'] = round($views * 0.78);
                $row['video_views_3s'] = round($views * 0.61);
                $row['video_views_6s'] = round($views * 0.38);
                $row['video_p25'] = round($views * 0.52);
                $row['video_p50'] = round($views * 0.34);
                $row['video_p75'] = round($views * 0.24);
                $row['video_p100'] = round($views * (0.18 + ($case['shape'] === 'improving' ? 0.12 * $t : 0)));
                $row['video_completions'] = $row['video_p100'];
                $row['video_avg_watch_seconds'] = round(($case['duration'] ?? 15) * 0.42, 2);
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('creative_daily_metrics')->insert($chunk);
        }
    }

    /**
     * The cross-platform pair, grouped by the evidence that proves it: the same file hash.
     *
     * `method = file_hash` rather than `manual`, because this is the automatic case §15.8 allows a
     * machine to make on its own — and the demo has to show that method existing, or the UI's
     * distinction between proven and asserted grouping has nothing to render.
     */
    private function groupTheSharedVideo(Tenant $tenant, Project $project): void
    {
        $pair = ExternalCreative::query()
            ->where('project_id', $project->getKey())
            ->whereIn('external_creative_id', [
                self::EXTERNAL_ID_PREFIX.'awareness-video',
                self::EXTERNAL_ID_PREFIX.'shared-video-snap',
            ])
            ->get();

        if ($pair->count() < 2) {
            return;
        }

        $group = CreativeGroup::firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'fingerprint' => 'demo-hash-hero-video'],
            [
                'project_id' => $project->getKey(),
                'name' => 'قصة علامتنا',
                'method' => 'file_hash',
            ],
        );

        ExternalCreative::query()->whereIn('id', $pair->modelKeys())
            ->update(['creative_group_id' => $group->getKey()]);
    }

    /**
     * A self-contained SVG placeholder, as a data URI.
     *
     * Deliberately not a stock image or a platform CDN link: the library has to render on a laptop
     * with no credentials and no network, or «the images actually appear» is untestable. It is also
     * visibly a placeholder — nobody should mistake demo content for a real asset.
     */
    private function placeholder(string $label, string $tint): string
    {
        $text = htmlspecialchars(mb_substr($label, 0, 28), ENT_XML1);
        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="600" height="600" viewBox="0 0 600 600">
        <rect width="600" height="600" fill="{$tint}"/>
        <text x="300" y="300" fill="#ffffff" font-family="sans-serif" font-size="28" text-anchor="middle">{$text}</text>
        <text x="300" y="345" fill="#ffffff" font-family="sans-serif" font-size="18" text-anchor="middle" opacity="0.75">Demo creative</text>
        </svg>
        SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
