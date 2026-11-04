<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — the live report's payload is what it was before its queries were batched.
 *
 * The stored baseline `tests/Fixtures/live_report_payload_baseline.json` was written by the code as it
 * stood BEFORE the query work (`origin/main` at 6852b572d), over the deterministic world in
 * {@see BuildsLiveReportWorld}: three platforms, six bound accounts, a deselected account and an
 * account bound elsewhere whose rows are filed under the project and must stay invisible. This test
 * reads the same endpoints today and holds every one of them to that file byte for byte.
 *
 * Re-write the baseline only for an intended change of the payload, never for a performance change:
 * `WRITE_LIVE_REPORT_BASELINE=1 vendor/bin/phpunit --filter=LiveReportPayloadIdentityTest`.
 */
final class LiveReportPayloadIdentityTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private const BASELINE = __DIR__.'/../Fixtures/live_report_payload_baseline.json';

    protected function setUp(): void
    {
        parent::setUp();
        // `content_key` is an HMAC under the application key, and CI generates a new key every run.
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->freezeWorld();
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /** @return array<string, string> endpoint name => the JSON `data` the public endpoint answered with */
    private function payloads(string $raw): array
    {
        $paths = [
            'live' => "/api/v1/reports/shared/{$raw}/live",
            'live_narrowed' => "/api/v1/reports/shared/{$raw}/live?from=2026-10-05&to=2026-10-12",
            'platform_meta' => "/api/v1/reports/shared/{$raw}/live/platform/meta",
            'platform_snapchat' => "/api/v1/reports/shared/{$raw}/live/platform/snapchat",
            'platform_tiktok' => "/api/v1/reports/shared/{$raw}/live/platform/tiktok",
        ];

        $out = [];
        foreach ($paths as $name => $path) {
            // The envelope carries a per-request id; the payload is what is held to the baseline.
            $data = $this->getJson($path)->assertOk()->json('data');
            // A random support code printed for each open; not a figure, and different on every read by design.
            if (isset($data['report_trace'])) {
                $data['report_trace'] = 'TRACE';
            }
            $out[$name] = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $out;
    }

    public function test_every_public_live_payload_is_byte_identical_to_the_stored_baseline(): void
    {
        [, $raw] = $this->liveLink();
        $now = $this->payloads($raw);

        // The world has to reach the code that was changed, or identity proves nothing.
        $live = json_decode($now['live'], true);
        $this->assertGreaterThan(0, count($live['campaign_rows'] ?? []), 'the world produced no campaign rows');
        $this->assertGreaterThan(0, count($live['platforms'] ?? []), 'the world produced no platform rows');
        $this->assertNotEmpty($live['ads_roster'] ?? [], 'the world produced no content roster');

        if (getenv('WRITE_LIVE_REPORT_BASELINE') === '1') {
            file_put_contents(self::BASELINE, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestSkipped('Baseline written.');
        }

        $stored = json_decode((string) file_get_contents(self::BASELINE), true, flags: JSON_THROW_ON_ERROR);

        foreach ($now as $name => $json) {
            $this->assertSame(
                [],
                array_slice(self::differences(json_decode($stored[$name], true), json_decode($json, true)), 0, 8),
                "payload «{$name}» differs from the stored baseline at these paths (stored != now)",
            );
            $this->assertSame($stored[$name], $json, "payload «{$name}» differs from the stored baseline byte for byte");
        }
        $this->assertSame(array_keys($stored), array_keys($now));
    }

    public function test_two_builds_of_the_same_link_agree(): void
    {
        [, $raw] = $this->liveLink();

        $first = $this->payloads($raw);
        $second = $this->payloads($raw);

        foreach ($first as $name => $json) {
            $this->assertSame([], self::differences(json_decode($json, true), json_decode($second[$name], true)), "«{$name}» is not stable between two reads, so a baseline cannot hold it");
        }
    }

    /** @return list<string> the paths at which two decoded payloads differ */
    private static function differences(mixed $a, mixed $b, string $path = ''): array
    {
        if (is_array($a) && is_array($b)) {
            $out = [];
            foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
                $out = [...$out, ...self::differences($a[$key] ?? null, $b[$key] ?? null, $path.'/'.$key)];
            }

            return $out;
        }

        return $a === $b ? [] : [$path.' : '.json_encode($a).' != '.json_encode($b)];
    }
}
