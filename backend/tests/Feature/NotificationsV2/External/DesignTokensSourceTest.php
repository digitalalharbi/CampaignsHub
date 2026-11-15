<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\External\Design\DesignTokens;
use InvalidArgumentException;
use Tests\TestCase;

/** Spec §11: ONE design-tokens source for the UI and the email, extracted from the UI's own CSS. */
final class DesignTokensSourceTest extends TestCase
{
    private function frontend(string $path): string
    {
        return dirname(base_path()).'/frontend/'.$path;
    }

    public function test_the_backend_copy_is_identical_to_the_ui_module(): void
    {
        $source = $this->frontend('src/lib/designTokens/tokens.json');
        if (! is_file($source)) {
            $this->markTestSkipped('frontend tree not present (backend-only image)');
        }
        $this->assertSame(sha1_file($source), sha1_file(DesignTokens::path()), 'Run: cp frontend/src/lib/designTokens/tokens.json backend/resources/design-tokens/tokens.json');
    }

    public function test_every_colour_matches_the_css_variable_it_was_extracted_from(): void
    {
        $css = $this->frontend('src/styles/tokens.css');
        if (! is_file($css)) {
            $this->markTestSkipped('frontend tree not present (backend-only image)');
        }
        $text = (string) file_get_contents($css);
        $darkAt = (int) strpos($text, ":root[data-theme='dark']");
        $light = $this->vars(substr($text, 0, $darkAt));
        $dark = $this->vars(substr($text, $darkAt));

        foreach (DesignTokens::all()['themes']['light'] as $name => $value) {
            $this->assertSame(strtolower((string) ($light[$name] ?? 'missing')), strtolower((string) $value), "light --{$name}");
        }
        foreach (DesignTokens::all()['themes']['dark'] as $name => $value) {
            $this->assertSame(strtolower((string) ($dark[$name] ?? $light[$name] ?? 'missing')), strtolower((string) $value), "dark --{$name}");
        }
        foreach (DesignTokens::all()['radius'] as $name => $value) {
            $this->assertSame($light[$name] ?? 'missing', $value, "--{$name}");
        }
    }

    public function test_unknown_tokens_throw_and_statuses_are_calm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        foreach (DesignTokens::all()['status'] as $s) {
            $this->assertStringNotContainsString('danger', (string) $s['color']);
        }
        DesignTokens::color('bordr');
    }

    /** @return array<string, string> */
    private function vars(string $block): array
    {
        preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/i', $block, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] ??= trim($row[2]);
        }

        return $out;
    }
}
