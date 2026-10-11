<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SNAP-OCT26-MCP-READONLY — Snap Ads MCP is read-only; the Marketing API is the only write plane.
 *
 * No MCP connector exists in this product today, and this guard keeps the rule from being crossed by
 * one that lands later: nothing under app/ may name an MCP client, server or tool as a path that
 * writes to Snapchat. The day an MCP read connector arrives, this test grows to assert it has no
 * write method — it does not get deleted.
 */
final class SnapchatMcpReadOnlyGuardTest extends TestCase
{
    public function test_no_code_treats_mcp_as_a_write_plane(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // A write through MCP would have to name both; naming MCP beside a write verb is what is refused.
            if (preg_match('/\bmcp\b/i', $source) === 1 && preg_match('/\b(create|update|pause|publish|write|patch|delete)[A-Za-z]*\s*\(/i', $source) === 1) {
                $hits[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        self::assertSame([], $hits, 'MCP named beside a write in: '.implode(', ', $hits));
    }
}
