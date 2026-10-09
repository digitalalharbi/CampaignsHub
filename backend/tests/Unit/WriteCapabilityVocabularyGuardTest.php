<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Management\WriteCapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * CAMPAIGN-MGMT-DOMAIN-001 — one vocabulary for provider writes, server and interface alike.
 *
 * The registry is the server's declaration of what can be written; the interface's labels and status
 * names are read from `writeControl.ts`. If either side invents a capability or a status the other
 * does not know, the gate silently fails closed for it — which is safe, and also a bug that would
 * surface only as a missing label. This guard reads the interface source and holds the two together.
 */
final class WriteCapabilityVocabularyGuardTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3).'/frontend/src/features/campaigns/writeControl.ts';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_every_registry_capability_has_an_interface_label_and_nothing_else_does(): void
    {
        preg_match('/CAPABILITY_LABELS[^{]*\{(.*?)\n\}/s', $this->source(), $m);
        self::assertNotEmpty($m[1] ?? '', 'CAPABILITY_LABELS not found');
        preg_match_all('/^\s*([a-z_]+): \{ ar:/m', $m[1], $keys);

        self::assertSame(array_keys(WriteCapabilityRegistry::CAPABILITIES), $keys[1], 'interface capabilities differ from the registry, in content or order');
    }

    public function test_every_status_the_registry_can_report_has_an_interface_name(): void
    {
        preg_match('/WRITE_STATUS_LABELS[^{]*\{(.*?)\n\}/s', $this->source(), $m);
        preg_match_all('/^\s*([a-z_]+): \{ ar:/m', $m[1] ?? '', $keys);

        self::assertSame([
            WriteCapabilityRegistry::NOT_IMPLEMENTED,
            WriteCapabilityRegistry::IMPLEMENTED_NOT_VERIFIED,
            WriteCapabilityRegistry::AWAITING_CREDENTIALS,
            WriteCapabilityRegistry::VERIFIED,
        ], $keys[1]);

        preg_match("/type WriteStatus = (.*)\n/", $this->source(), $t);
        preg_match_all("/'([a-z_]+)'/", $t[1] ?? '', $union);
        self::assertSame($keys[1], $union[1], 'the WriteStatus union and its labels disagree');
    }
}
