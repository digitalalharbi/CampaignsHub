<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * CREATIVE-VIDEO-FRAME-COVER-001 — ONE still frame taken from a film, for a creative whose platform sent no still.
 *
 * Owner, 2026-11-08: «يجب أن تظهر كل المحتويات 100%». A `<video>` first frame is not a cover: iOS Safari paints
 * nothing without a gesture, a signed link lapses within hours, and a PDF cannot rely on it. So the frame is taken
 * on the server and stored like any other cover copy.
 *
 * Safety, each one a rule rather than a habit:
 *  - the host must be a known provider CDN (the cover allowlist plus Snapchat's media host), https only;
 *  - ffmpeg is started with an ARGUMENT ARRAY — the URL never passes through a shell;
 *  - the output is the process's stdout, so nothing is written to disk by this class at all;
 *  - protocols are whitelisted (no `file:`, no `concat:`, no redirects into other schemes);
 *  - a hard timeout, `nice`, a size cap and a JPEG signature check;
 *  - failure reasons are fixed words. The URL, its query string and ffmpeg's stderr (which echoes the URL) are
 *    never returned and never logged.
 *
 * One call is one process, and a queue worker runs one job at a time, so a worker never holds two.
 */
class VideoFrameExtractor
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const TIMEOUT_SECONDS = 25;

    /** The cover allowlist (short-lived provider CDNs) plus the hosts whose films have no still of their own. */
    private const EXTRA_HOSTS = ['sc-cdn.net', 'snapchat.com'];

    /** @return array{0: ?string, 1: ?string} JPEG bytes, or a fixed-word reason */
    public function extract(string $url): array
    {
        if (! self::allowed($url)) {
            return [null, 'frame_host_not_allowed'];
        }

        return $this->grab($url);
    }

    /** @return list<string> every host suffix a film may be framed from */
    public static function hosts(): array
    {
        return [...CoverCache::copyOnlyHosts(), ...self::EXTRA_HOSTS];
    }

    /** Whether a film at this link may be handed to ffmpeg: https, no credentials, a known CDN host. */
    public static function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || preg_match('/[^a-z0-9.-]/', $host) === 1) {
            return false;
        }
        foreach (self::hosts() as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take the frame from an input (a URL that passed {@see allowed()}, or a local path in the integration test).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function grab(string $input): array
    {
        $remote = str_starts_with($input, 'https://');
        $command = ['nice', '-n', '10', 'ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin',
            '-protocol_whitelist', $remote ? 'https,tls,tcp,crypto' : 'file'];
        if ($remote) {
            // Microseconds: give up on a stalled connection well before the process timeout.
            array_push($command, '-rw_timeout', '10000000');
        }
        array_push($command, '-ss', '0.5', '-i', $input, '-frames:v', '1',
            '-vf', 'scale=min(640\,iw):-2', '-q:v', '4', '-f', 'image2pipe', '-c:v', 'mjpeg', 'pipe:1');

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run($command);
        } catch (Throwable $e) {
            // ProcessTimedOutException and a missing binary both land here; neither message is kept (it holds the command).
            return [null, str_contains(strtolower($e::class), 'timedout') ? 'frame_timeout' : 'frame_ffmpeg_failed'];
        }

        $body = $result->output();
        if (! $result->successful()) {
            return [null, 'frame_ffmpeg_failed'];
        }
        if (strlen($body) > self::MAX_BYTES) {
            return [null, 'frame_too_large'];
        }
        if (! str_starts_with($body, "\xFF\xD8\xFF")) {
            // `-ss` past the end of a very short film yields nothing; retry from the start once.
            return $input !== '' && $body === '' ? $this->retryFromStart($command) : [null, 'frame_not_image'];
        }

        return [$body, null];
    }

    /**
     * @param  list<string>  $command
     * @return array{0: ?string, 1: ?string}
     */
    private function retryFromStart(array $command): array
    {
        $at = array_search('-ss', $command, true);
        if ($at === false) {
            return [null, 'frame_not_image'];
        }
        $command[$at + 1] = '0';
        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run($command);
        } catch (Throwable) {
            return [null, 'frame_ffmpeg_failed'];
        }
        $body = $result->output();

        return $result->successful() && str_starts_with($body, "\xFF\xD8\xFF") && strlen($body) <= self::MAX_BYTES
            ? [$body, null]
            : [null, 'frame_not_image'];
    }
}
