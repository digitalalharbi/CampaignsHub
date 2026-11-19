<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

/**
 * A bounded MP4/MOV header reader — duration and frame size WITHOUT decoding and without ffmpeg (the VPS has one
 * vCPU). It walks top-level box headers with seeks, reads only the `moov` box (refused above 16 MB), and reads
 * `mvhd` (duration) and the largest `tkhd` (width x height). Anything it cannot read is returned as null — an
 * unreadable header is «not measured», never a guessed number.
 */
final class Mp4Probe
{
    private const MAX_MOOV_BYTES = 16 * 1024 * 1024;

    /** @return array{duration_ms: ?int, width: ?int, height: ?int} */
    public function probe(string $absolutePath): array
    {
        $none = ['duration_ms' => null, 'width' => null, 'height' => null];
        $h = @fopen($absolutePath, 'rb');
        if ($h === false) {
            return $none;
        }

        try {
            $size = (int) filesize($absolutePath);
            $pos = 0;
            $guard = 0;
            while ($pos + 8 <= $size && $guard++ < 4096) {
                fseek($h, $pos);
                $head = fread($h, 16);
                if ($head === false || strlen($head) < 8) {
                    break;
                }
                $len = (int) unpack('N', substr($head, 0, 4))[1];
                $type = substr($head, 4, 4);
                $header = 8;
                if ($len === 1 && strlen($head) >= 16) {
                    $hi = (int) unpack('N', substr($head, 8, 4))[1];
                    $lo = (int) unpack('N', substr($head, 12, 4))[1];
                    $len = ($hi << 32) | $lo;
                    $header = 16;
                } elseif ($len === 0) {
                    $len = $size - $pos;
                }
                if ($len < $header) {
                    break;
                }
                if ($type === 'moov') {
                    if ($len > self::MAX_MOOV_BYTES) {
                        return $none;
                    }
                    fseek($h, $pos + $header);
                    $body = (string) fread($h, $len - $header);

                    return $this->moov($body);
                }
                $pos += $len;
            }
        } finally {
            fclose($h);
        }

        return $none;
    }

    /** @return array{duration_ms: ?int, width: ?int, height: ?int} */
    private function moov(string $body): array
    {
        $duration = null;
        $best = [0, 0];
        foreach ($this->boxes($body) as [$type, $payload]) {
            if ($type === 'mvhd') {
                $duration = $this->mvhd($payload);
            } elseif ($type === 'trak') {
                foreach ($this->boxes($payload) as [$t2, $p2]) {
                    if ($t2 === 'tkhd') {
                        $wh = $this->tkhd($p2);
                        if ($wh !== null && $wh[0] * $wh[1] > $best[0] * $best[1]) {
                            $best = $wh;
                        }
                    }
                }
            }
        }

        return [
            'duration_ms' => $duration,
            'width' => $best[0] > 0 ? $best[0] : null,
            'height' => $best[1] > 0 ? $best[1] : null,
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    private function boxes(string $data): array
    {
        $out = [];
        $pos = 0;
        $n = strlen($data);
        while ($pos + 8 <= $n && count($out) < 256) {
            $len = (int) unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            if ($len < 8 || $pos + $len > $n) {
                break;
            }
            $out[] = [$type, substr($data, $pos + 8, $len - 8)];
            $pos += $len;
        }

        return $out;
    }

    private function mvhd(string $p): ?int
    {
        $version = ord($p[0] ?? "\0");
        if ($version === 1 && strlen($p) >= 32) {
            $scale = (int) unpack('N', substr($p, 20, 4))[1];
            $d = unpack('N2', substr($p, 24, 8));
            $dur = ((int) $d[1] << 32) | (int) $d[2];
        } elseif ($version === 0 && strlen($p) >= 20) {
            $scale = (int) unpack('N', substr($p, 12, 4))[1];
            $dur = (int) unpack('N', substr($p, 16, 4))[1];
        } else {
            return null;
        }

        return $scale > 0 ? (int) round($dur * 1000 / $scale) : null;
    }

    /** @return array{0: int, 1: int}|null */
    private function tkhd(string $p): ?array
    {
        $version = ord($p[0] ?? "\0");
        $offset = $version === 1 ? 88 : 76;
        if (strlen($p) < $offset + 8) {
            return null;
        }
        $w = (int) unpack('N', substr($p, $offset, 4))[1] >> 16;
        $h = (int) unpack('N', substr($p, $offset + 4, 4))[1] >> 16;

        return [$w, $h];
    }
}
