<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Enums\Scope;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * NOTIF-V2-ENGINE-001 — «no emoji anywhere» (Owner spec, header and §16): every notification string, template, catalog
 * entry and the bell / push / PWA sources are scanned, plus every catalog title and body as rendered in both languages.
 *
 * Emoji = pictographs (U+1F000–1FAFF), the emoji variation selector (U+FE0F), keycaps (U+20E3), misc symbols and dingbats
 * (U+2600–27BF), and the emoji-presentation arrows/stars/clocks (U+2B00–2BFF, U+231A–231B, U+23E9–23FA). The typographic
 * status marks the mail design already uses — check (U+2713/2714) and cross (U+2715/2716/2717/2718) — are glyphs, not
 * emoji, and stay allowed; the rule exists to keep them the only exception.
 */
final class NoEmojiInNotificationsTest extends TestCase
{
    private const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{FE0F}\x{20E3}\x{2B00}-\x{2BFF}\x{231A}\x{231B}\x{23E9}-\x{23FA}]|(?![\x{2713}-\x{2718}])[\x{2600}-\x{27BF}]/u';

    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(base_path());
        $paths = [
            base_path('app/Domains/Notifications'),
            base_path('resources/views/mail'),
            base_path('config/notification_events.php'),
            base_path('config/notification_workspace_types.php'),
            base_path('config/notifications_v2.php'),
            base_path('config/webpush.php'),
            $root.'/frontend/src/features/notifications',
            $root.'/frontend/src/lib/notifications',
            $root.'/frontend/public/sw.js',
            $root.'/frontend/public/manifest.webmanifest',
            $root.'/frontend/public/offline.html',
        ];
        foreach (['ar', 'en'] as $lang) {
            foreach (glob(base_path("lang/{$lang}/*.php")) ?: [] as $file) {
                if (str_contains(basename($file), 'notification')) {
                    $paths[] = $file;
                }
            }
        }

        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                    if (preg_match('/\.(php|ts|tsx|js|json|html|webmanifest)$/', $f->getFilename()) === 1) {
                        $files[] = $f->getPathname();
                    }
                }
            }
        }

        return $files;
    }

    public function test_no_notification_source_or_template_contains_an_emoji(): void
    {
        $files = $this->files();
        $this->assertGreaterThan(50, count($files), 'the scan must actually cover the notification sources');

        $found = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                if (preg_match(self::EMOJI, $line, $m) === 1) {
                    $found[] = str_replace(dirname(base_path()).'/', '', $file).':'.($n + 1).' U+'.strtoupper(dechex(mb_ord($m[0])));
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_every_catalog_title_and_body_is_emoji_free_in_both_languages(): void
    {
        foreach (app(EventRegistry::class)->all() as $event) {
            foreach ([Scope::Internal, Scope::External] as $scope) {
                foreach (['ar', 'en'] as $locale) {
                    foreach (['title', 'body'] as $part) {
                        $this->assertDoesNotMatchRegularExpression(self::EMOJI, (string) __($event->textKey($scope, $part), [], $locale), $event->key);
                    }
                }
            }
        }
    }

    public function test_the_scanner_catches_an_emoji_and_allows_the_status_glyphs(): void
    {
        foreach (["\u{1F680}", "\u{26A0}\u{FE0F}", "\u{2705}", "\u{2B50}", "\u{1F1F8}\u{1F1E6}", "\u{2764}"] as $emoji) {
            $this->assertMatchesRegularExpression(self::EMOJI, 'نص '.$emoji, bin2hex($emoji));
        }
        foreach (["\u{2713}", "\u{2715}", '!', '–', 'تكفي حتى 2026-11-20'] as $allowed) {
            $this->assertDoesNotMatchRegularExpression(self::EMOJI, $allowed);
        }
    }
}
