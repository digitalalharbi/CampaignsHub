<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Support\MailGallery;
use Illuminate\Console\Command;

/**
 * One email, one language, one file — EMAIL-RTL-001.
 *
 * `notifications:preview` writes the whole gallery; this is the quick loop for the person changing a
 * single template and wanting to open the Arabic version in a browser. It renders placeholder data
 * only (the gallery's fixtures) and never sends anything. The tokens in the output are filler.
 */
final class MailPreviewCommand extends Command
{
    /** Short names for the messages people ask for by what they do, mapped to gallery keys. */
    private const ALIASES = [
        'verification' => 'account-email-verification',
        'password-reset' => 'account-password-reset',
        'invite' => 'account-member-setup',
        'invitation' => 'account-invitation',
        'owner-invitation' => 'account-owner-invitation',
        'sign-in-code' => 'account-sign-in-code',
        'security' => 'account-security-sign-in',
    ];

    protected $signature = 'mail:preview
        {mailable : A gallery key or alias (verification, password-reset, invite, invitation, owner-invitation, sign-in-code, security)}
        {--locale=ar : ar or en}
        {--out= : Output file; defaults to storage/app/mail-previews/<mailable>.<locale>.html}';

    protected $description = 'Render one email with placeholder data to an HTML file for visual review.';

    public function handle(): int
    {
        $name = (string) $this->argument('mailable');
        $key = self::ALIASES[$name] ?? $name;
        $locale = (string) $this->option('locale');

        if (! in_array($locale, ['ar', 'en'], true)) {
            $this->error('--locale must be ar or en.');

            return self::FAILURE;
        }

        $html = MailGallery::render($key, $locale);
        if ($html === null) {
            $this->error("Unknown mailable '{$name}'. Known: ".implode(', ', array_keys(self::ALIASES)).', '.implode(', ', MailGallery::keys()));

            return self::FAILURE;
        }

        $out = (string) $this->option('out');
        $path = $out !== '' ? $out : storage_path("app/mail-previews/{$key}.{$locale}.html");
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }

        file_put_contents($path, $html);
        $this->line($path);

        return self::SUCCESS;
    }
}
