<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

/**
 * What can be MEASURED about a stored file cheaply: images through getimagesize, videos through the bounded MP4 header
 * reader. Everything else stays null. No decoding, no external process.
 */
final class MediaProbe
{
    public function __construct(private readonly Mp4Probe $mp4) {}

    /** @return array{mime: ?string, width: ?int, height: ?int, duration_ms: ?int, size_bytes: ?int, content_hash: ?string} */
    public function probe(string $absolutePath, string $kind): array
    {
        $out = ['mime' => null, 'width' => null, 'height' => null, 'duration_ms' => null, 'size_bytes' => null, 'content_hash' => null];
        if (! is_file($absolutePath)) {
            return $out;
        }
        $out['size_bytes'] = (int) filesize($absolutePath);
        $out['content_hash'] = hash_file('sha256', $absolutePath) ?: null;
        $info = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        if ($info !== false) {
            $mime = finfo_file($info, $absolutePath);
            $out['mime'] = is_string($mime) ? $mime : null;
        }

        if ($kind === 'image') {
            $size = @getimagesize($absolutePath);
            if (is_array($size)) {
                $out['width'] = (int) $size[0];
                $out['height'] = (int) $size[1];
            }
        } elseif ($kind === 'video') {
            $v = $this->mp4->probe($absolutePath);
            $out['width'] = $v['width'];
            $out['height'] = $v['height'];
            $out['duration_ms'] = $v['duration_ms'];
        }

        return $out;
    }
}
