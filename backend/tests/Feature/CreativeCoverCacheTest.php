<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Jobs\CacheCreativeCoverJob;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TIKTOK-MEDIA-002 — a creative's cover is fetched once into our own storage and read from there first, so an
 * expired provider link no longer empties the library.
 */
final class CreativeCoverCacheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CoverCache::DISK);
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function creative(array $over = []): ExternalCreative
    {
        return ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'tiktok',
            'external_creative_id' => 'v-1', 'name' => 'Hero', 'format' => 'video', 'status' => 'active',
            'thumbnail_url' => 'https://p16.tiktokcdn.com/cover-v1.jpg?x-expires=1',
        ], $over));
    }

    public function test_a_cover_is_captured_into_our_storage_under_a_keyed_name_and_read_first(): void
    {
        Http::fake(['p16.tiktokcdn.com/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $creative = $this->creative();

        $this->assertTrue(CoverCache::wanted($creative));
        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        $this->assertNotNull($creative->cover_path);
        $this->assertStringNotContainsString('v-1', (string) $creative->cover_path, 'the name is a keyed hash, not the provider id');
        Storage::disk(CoverCache::DISK)->assertExists(CoverCache::ROOT.'/'.$creative->cover_path);
        $this->assertFalse(CoverCache::wanted($creative), 'captured once; not fetched again while the link is unchanged');

        $reading = app(CreativePresenter::class)->preview($creative);
        $this->assertSame('/media/covers/'.$creative->cover_path, $reading['thumbnail_url']);

        // The provider link has since expired: the cover still shows, and the state says what expired.
        $creative->forceFill(['asset_expires_at' => Carbon::now()->subHour()])->save();
        $reading = app(CreativePresenter::class)->preview($creative->fresh());
        $this->assertSame('/media/covers/'.$creative->cover_path, $reading['thumbnail_url']);
    }

    public function test_a_cover_that_is_not_a_small_image_is_refused_and_the_creative_is_left_as_it_was(): void
    {
        Http::fake(['p16.tiktokcdn.com/*' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html'])]);
        $creative = $this->creative();

        $this->assertFalse(app(CoverCache::class)->capture($creative));
        $this->assertNull($creative->fresh()->cover_path);
        $this->assertSame('https://p16.tiktokcdn.com/cover-v1.jpg?x-expires=1', app(CreativePresenter::class)->preview($creative->fresh())['thumbnail_url'], 'the live link is still offered');
    }

    public function test_the_nightly_pass_queues_only_creatives_without_a_current_cover(): void
    {
        Queue::fake();
        $this->creative(['external_creative_id' => 'v-1']);
        $this->creative(['external_creative_id' => 'v-2', 'cover_path' => 'ab/abc.jpg', 'cover_cached_at' => Carbon::now()]);
        $this->creative(['external_creative_id' => 'v-3', 'thumbnail_url' => null]);

        $this->artisan('creatives:refresh-covers', ['--provider' => 'tiktok'])->expectsOutputToContain('1 cover capture(s) queued.')->assertSuccessful();
        Queue::assertPushed(CacheCreativeCoverJob::class, 1);
    }

    public function test_the_cover_route_serves_the_file_and_refuses_a_path_outside_the_covers_root(): void
    {
        Storage::disk(CoverCache::DISK)->put(CoverCache::ROOT.'/ab/abc.jpg', 'JPEGBYTES');

        $this->get('/media/covers/ab/abc.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/media/covers/../demo/x.jpg')->assertNotFound();
        $this->get('/media/covers/ab/missing.jpg')->assertNotFound();
    }
}
