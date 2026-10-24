<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Delivery\Services\PlatformEvidenceService;
use PHPUnit\Framework\TestCase;

/**
 * UPLOAD-SCREENSHOT-001 — the runtime half. The application accepts MAX_KB per file; every layer in front of it must let
 * that much through, or the application's own refusal is never reached and the person reads a bare 413 / «upload failed».
 * Layers: nginx request body → PHP post_max_size → PHP upload_max_filesize → Laravel validation.
 */
final class UploadRuntimeLimitsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    private function megabytes(string $value): int
    {
        $this->assertSame(1, preg_match('/^\s*(\d+)\s*([MG])\s*$/i', $value, $m), "unreadable size «{$value}»");

        return (int) $m[1] * (strtoupper($m[2]) === 'G' ? 1024 : 1);
    }

    public function test_every_layer_admits_more_than_the_application_limit(): void
    {
        $app = (int) ceil(PlatformEvidenceService::MAX_KB / 1024);

        $nginx = (string) file_get_contents($this->root.'/infrastructure/nginx/frontend.conf');
        $this->assertSame(1, preg_match('/^\s*client_max_body_size\s+(\d+[mMgG])\s*;/m', $nginx, $n), 'nginx sets client_max_body_size at server level (default 1m refuses every screenshot)');
        $ini = (string) file_get_contents($this->root.'/backend/docker/php-uploads.ini');
        $this->assertSame(1, preg_match('/^upload_max_filesize\s*=\s*(\S+)/m', $ini, $u));
        $this->assertSame(1, preg_match('/^post_max_size\s*=\s*(\S+)/m', $ini, $p));

        $this->assertGreaterThan($app, $this->megabytes($u[1]), 'PHP per-file limit above the application limit');
        $this->assertGreaterThan($this->megabytes($u[1]), $this->megabytes($p[1]), 'post_max_size above the per-file limit (multipart envelope)');
        $this->assertGreaterThanOrEqual($this->megabytes($p[1]), $this->megabytes($n[1]), 'nginx admits what PHP admits');
    }

    public function test_both_backend_images_install_the_upload_ini(): void
    {
        foreach (['/deploy/backend.production.Dockerfile', '/infrastructure/docker/backend.Dockerfile'] as $file) {
            $this->assertMatchesRegularExpression('#COPY\s+\S*docker/php-uploads\.ini\s+/usr/local/etc/php/conf\.d/#', (string) file_get_contents($this->root.$file), $file);
        }
    }
}
