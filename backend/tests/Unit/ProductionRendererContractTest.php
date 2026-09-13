<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the deployment contract cannot promise a renderer it does not install.
 *
 * The owner clicked PDF in Production and no file arrived. Three files disagreed with each other and
 * nothing noticed: the env template enabled nothing, the image installed nothing, and the checklist
 * stated that «the server does not need Node» while the renderer spawns Node on the server.
 *
 * The CI `image` job is the stronger proof — it builds the image and asks it for each binary, which a
 * line in a file cannot fake. These cases guard the other direction: that the three documents do not
 * drift apart again, which is cheap to check and is how the contradiction survived.
 */
final class ProductionRendererContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = \dirname(__DIR__, 3).'/'.$relative;
        self::assertFileExists($path, "{$relative} has moved — this contract must follow it");

        return (string) file_get_contents($path);
    }

    public function test_enabling_the_renderer_requires_the_image_to_install_it(): void
    {
        $env = $this->read('deploy/backend.production.env.example');

        if (! preg_match('/^REPORTS_CHROMIUM_ENABLED=true$/m', $env)) {
            self::markTestSkipped('The production template does not enable the renderer, so it promises nothing.');
        }

        $dockerfile = $this->read('deploy/backend.production.Dockerfile');

        // Each of these is spawned or resolved at runtime by the PDF path.
        foreach (['chromium', 'nodejs', 'python3', 'py3-pikepdf', 'font-noto-arabic', 'playwright-core'] as $needed) {
            self::assertStringContainsString(
                $needed,
                $dockerfile,
                "the template enables the renderer but the image never installs «{$needed}»"
            );
        }
    }

    /**
     * A path that points at a developer's checkout is the same failure as a missing binary: the
     * require throws on a server that has no frontend tree.
     */
    public function test_the_print_runtime_is_addressed_where_the_image_actually_puts_it(): void
    {
        $env = $this->read('deploy/backend.production.env.example');

        if (! preg_match('/^REPORTS_CHROMIUM_ENABLED=true$/m', $env)) {
            self::markTestSkipped('The renderer is not enabled for production.');
        }

        self::assertMatchesRegularExpression(
            '/^REPORTS_REQUIRE_BASE=(?!.*frontend\/package\.json).+$/m',
            $env,
            'REQUIRE_BASE must name a path that exists in the image, not a developer checkout'
        );

        $base = [];
        preg_match('/^REPORTS_REQUIRE_BASE=(.+)$/m', $env, $base);
        $dir = \dirname(trim($base[1] ?? ''));

        self::assertStringContainsString(
            $dir,
            $this->read('deploy/backend.production.Dockerfile'),
            "the image never creates {$dir}, so playwright-core cannot be resolved there"
        );

        // Alpine is musl; Playwright's own download is glibc. The distro browser needs an explicit path.
        self::assertMatchesRegularExpression('/^REPORTS_CHROMIUM_PATH=\/usr\/bin\/chromium/m', $env);
    }

    /**
     * The claim that broke the promise, pinned so it cannot come back: the renderer runs Node ON the
     * server, and a checklist that says otherwise is how a server gets built without it.
     */
    public function test_the_checklist_no_longer_denies_that_the_server_needs_node(): void
    {
        $checklist = $this->read('docs/DEPLOYMENT_CHECKLIST.md');

        self::assertStringNotContainsString(
            'The server serves a built bundle; it does not need Node.',
            $checklist,
            'the checklist still tells an operator the server needs no Node, which is why one was built without it'
        );

        foreach (['python3', 'pikepdf', 'font-noto-arabic'] as $needed) {
            self::assertStringContainsString(
                $needed,
                $checklist,
                "the checklist never mentions «{$needed}», which the PDF path fails closed without"
            );
        }
    }
}
