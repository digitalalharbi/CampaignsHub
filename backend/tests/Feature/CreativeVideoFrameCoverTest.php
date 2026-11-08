<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Jobs\CacheCreativeCoverJob;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\VideoFrameExtractor;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CREATIVE-VIDEO-FRAME-COVER-001 — a film with no still of the platform's gets one frame taken from the film
 * itself, stored as our own cover copy.
 *
 * Owner, 2026-11-08: «يجب أن تظهر كل المحتويات 100% … سناب وميتا وتيك توك وChatGPT وجوجل ادس».
 */
final class CreativeVideoFrameCoverTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'x-signature=SECRETSIG123';

    private const VIDEO = 'https://v16-sign.tiktokcdn.com/film.mp4?x-expires=4102444800&'.self::SECRET;

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
            'external_creative_id' => 'v-'.uniqid(), 'name' => 'Film', 'format' => 'video', 'status' => 'active',
            'video_url' => self::VIDEO,
        ], $over));
    }

    private function fakeFrame(string $body = "\xFF\xD8\xFF\xE0JFIFBYTES", int $exit = 0, string $error = ''): void
    {
        Process::fake(['*' => Process::result(output: $exit === 0 ? $body : '', errorOutput: $error, exitCode: $exit)]);
    }

    /** @return list<string> every message and context the log saw while $run ran */
    private function logged(callable $run): array
    {
        $seen = [];
        Log::listen(function ($event) use (&$seen): void {
            $seen[] = $event->message.' '.json_encode($event->context);
        });
        $run();

        return $seen;
    }

    public function test_a_video_with_no_provider_still_gets_a_video_frame_cover(): void
    {
        $this->fakeFrame();
        Http::fake();
        $creative = $this->creative();

        $this->assertTrue(CoverCache::wanted($creative), 'a film with no still link is due');
        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        $this->assertSame(CoverCache::SOURCE_VIDEO_FRAME, $creative->cover_source);
        $this->assertSame(CoverCache::STATE_CACHED, $creative->cover_state);
        $this->assertNotNull($creative->cover_path);
        $stored = Storage::disk(CoverCache::DISK)->get(CoverCache::ROOT.'/'.$creative->cover_path);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $stored);
        $this->assertFalse(CoverCache::wanted($creative), 'taken once');
        Http::assertNothingSent();

        // An ARGUMENT ARRAY, with the bounded flags, never a shell string.
        Process::assertRan(function (PendingProcess $p): bool {
            return is_array($p->command) && in_array('ffmpeg', $p->command, true) && in_array('-ss', $p->command, true)
                && in_array('-frames:v', $p->command, true) && in_array('nice', $p->command, true)
                && in_array(self::VIDEO, $p->command, true) && $p->timeout === VideoFrameExtractor::TIMEOUT_SECONDS;
        });

        // The presenter draws the frame as the still and keeps the film for Play.
        $reading = app(CreativePresenter::class)->preview($creative);
        $this->assertSame('/media/covers/'.$creative->cover_path, $reading['thumbnail_url']);
        $this->assertSame('/media/covers/'.$creative->cover_path, $reading['image_url']);
        $this->assertSame(self::VIDEO, $reading['video_url']);
    }

    public function test_a_dead_still_link_with_a_valid_video_gets_a_video_frame_cover(): void
    {
        $this->fakeFrame();
        Http::fake(['p16.tiktokcdn.com/*' => Http::response('denied', 403)]);
        $creative = $this->creative(['thumbnail_url' => 'https://p16.tiktokcdn.com/cover.jpg?x-expires=4102444800']);

        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        $this->assertSame(CoverCache::SOURCE_VIDEO_FRAME, $creative->cover_source);
        $this->assertNotNull($creative->cover_path);
        $this->assertNull($creative->cover_error);
    }

    public function test_a_disallowed_host_never_reaches_ffmpeg_and_backs_off(): void
    {
        $this->fakeFrame();
        Http::fake(['p16.tiktokcdn.com/*' => Http::response('gone', 500)]);
        $creative = $this->creative([
            'thumbnail_url' => 'https://p16.tiktokcdn.com/cover.jpg?x-expires=4102444800',
            'video_url' => 'https://evil.example.com/film.mp4?'.self::SECRET,
        ]);

        $this->assertFalse(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        $this->assertSame(CoverCache::STATE_FAILED, $creative->cover_state);
        $this->assertSame('http_500', $creative->cover_error);
        $this->assertSame(1, $creative->cover_attempts);
        $this->assertTrue($creative->cover_next_attempt_at->isFuture());
        Process::assertNothingRan();
    }

    public function test_only_https_known_cdn_hosts_may_be_framed(): void
    {
        foreach ([
            'http://v16-sign.tiktokcdn.com/f.mp4', 'https://evil.example.com/f.mp4', 'https://tiktokcdn.com.evil.io/f.mp4',
            'https://user:pw@v16-sign.tiktokcdn.com/f.mp4', 'file:///etc/passwd', 'https://127.0.0.1/f.mp4', '',
        ] as $bad) {
            $this->assertFalse(VideoFrameExtractor::allowed($bad), $bad);
        }
        foreach (['https://v16-sign.tiktokcdn.com/f.mp4?x', 'https://scontent-fra5-1.xx.fbcdn.net/v/f.mp4', 'https://cf-st.sc-cdn.net/d/f.mp4'] as $good) {
            $this->assertTrue(VideoFrameExtractor::allowed($good), $good);
        }
    }

    public function test_an_ffmpeg_failure_backs_off_without_crashing_and_the_url_is_never_logged(): void
    {
        // ffmpeg echoes the URL in its stderr; nothing of it may reach a log.
        $this->fakeFrame(exit: 1, error: 'Server returned 403 for '.self::VIDEO);
        $creative = $this->creative();

        $lines = $this->logged(fn () => $this->assertFalse(app(CoverCache::class)->capture($creative)));

        $creative->refresh();
        $this->assertSame(CoverCache::STATE_FAILED, $creative->cover_state);
        $this->assertSame('frame_ffmpeg_failed', $creative->cover_error);
        $this->assertSame(1, $creative->cover_attempts);
        $this->assertTrue($creative->cover_next_attempt_at->isFuture());
        $this->assertNull($creative->cover_path);
        $this->assertFalse(CoverCache::wanted($creative), 'backing off');
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('SECRETSIG123', $line);
            $this->assertStringNotContainsString('tiktokcdn.com', $line);
        }
        Storage::disk(CoverCache::DISK)->assertDirectoryEmpty(CoverCache::ROOT);
    }

    public function test_a_throwing_process_is_a_failure_reason_not_a_crash(): void
    {
        Process::fake(['*' => fn () => throw new \RuntimeException('spawn failed for '.self::VIDEO)]);
        $creative = $this->creative();

        $lines = $this->logged(fn () => $this->assertFalse(app(CoverCache::class)->capture($creative)));

        $this->assertSame('frame_ffmpeg_failed', $creative->fresh()->cover_error);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('SECRETSIG123', $line);
        }
    }

    public function test_output_that_is_not_a_small_jpeg_is_refused(): void
    {
        $extractor = app(VideoFrameExtractor::class);

        $this->fakeFrame('not an image');
        $this->assertSame([null, 'frame_not_image'], $extractor->extract(self::VIDEO));

        $this->fakeFrame("\xFF\xD8\xFF".str_repeat('a', VideoFrameExtractor::MAX_BYTES));
        $this->assertSame([null, 'frame_too_large'], $extractor->extract(self::VIDEO));
    }

    public function test_when_the_platform_still_is_copied_ffmpeg_is_not_called(): void
    {
        $this->fakeFrame();
        Http::fake(['p16.tiktokcdn.com/*' => Http::response("\xFF\xD8\xFFJPEG", 200, ['Content-Type' => 'image/jpeg'])]);
        $creative = $this->creative(['thumbnail_url' => 'https://p16.tiktokcdn.com/cover.jpg?x-expires=4102444800']);

        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $this->assertSame(CoverCache::SOURCE_PROVIDER, $creative->fresh()->cover_source);
        Process::assertNothingRan();
    }

    public function test_a_frame_stands_in_until_the_platforms_own_still_can_be_copied(): void
    {
        $this->fakeFrame();
        $creative = $this->creative();
        app(CoverCache::class)->capture($creative);
        $creative->refresh();
        $this->assertFalse(CoverCache::wanted($creative));

        // The platform now names a still: the frame is replaced by it, and ffmpeg is not run again.
        $creative->forceFill(['thumbnail_url' => 'https://p16.tiktokcdn.com/cover.jpg?x-expires=4102444800'])->save();
        $this->assertTrue(CoverCache::wanted($creative));
        Process::fake();
        Http::fake(['p16.tiktokcdn.com/*' => Http::response("\xFF\xD8\xFFREAL", 200, ['Content-Type' => 'image/jpeg'])]);
        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $this->assertSame(CoverCache::SOURCE_PROVIDER, $creative->fresh()->cover_source);
        Process::assertRanTimes(fn (): bool => true, 1); // the first capture's run only
    }

    public function test_the_job_and_the_dry_run_count_films_that_can_be_framed(): void
    {
        Queue::fake();
        $this->creative(['external_creative_id' => 'a']);
        $this->creative(['external_creative_id' => 'b', 'provider' => 'meta', 'video_url' => 'https://video.xx.fbcdn.net/f.mp4?oe=7ffffff0']);
        $this->creative(['external_creative_id' => 'c', 'video_url' => 'https://elsewhere.example/f.mp4']);

        Artisan::call('creatives:backfill-covers');
        $out = Artisan::output();
        $this->assertStringContainsString('via_video_frame', $out);
        $this->assertMatchesRegularExpression('/\|\s*meta\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*tiktok\s*\|\s*2\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*1\s*\|/', $out);

        Artisan::call('creatives:backfill-covers', ['--execute' => true]);
        Queue::assertPushed(CacheCreativeCoverJob::class, 2);
    }

    /** Real ffmpeg, a real file: guarded, run locally with ffmpeg on PATH. */
    public function test_real_ffmpeg_takes_a_jpeg_frame_from_a_generated_clip(): void
    {
        $bin = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($bin === '') {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $dir = sys_get_temp_dir().'/ffcover-'.uniqid();
        mkdir($dir);
        $clip = $dir.'/clip.mp4';
        exec('ffmpeg -hide_banner -loglevel error -f lavfi -i testsrc=duration=1:size=1280x720:rate=10 -pix_fmt yuv420p '.escapeshellarg($clip), $o, $code);
        $this->assertSame(0, $code, 'the test clip was generated');

        [$frame, $reason] = app(VideoFrameExtractor::class)->grab($clip);

        @unlink($clip);
        @rmdir($dir);
        $this->assertNull($reason);
        $this->assertStringStartsWith("\xFF\xD8\xFF", (string) $frame);
        $size = getimagesizefromstring((string) $frame);
        $this->assertSame(640, $size[0], 'scaled down to at most 640 wide');
        $this->assertSame(360, $size[1]);
    }
}
