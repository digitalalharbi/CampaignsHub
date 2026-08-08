<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Support\MailGallery;
use Illuminate\Console\Command;

/**
 * Every email this product can send, rendered to a file — MAIL-008.
 *
 * ## Why a command rather than a route
 *
 * A preview route is a page that has to be authorised, hosted and remembered; this is a person
 * asking «what does the budget alert actually look like in Arabic?» and getting an answer they can
 * open in a browser, forward to a colleague, or paste into a real inbox to see what Gmail does to it.
 *
 * It is also the only way to review the emails at all until SMTP credentials exist. The templates,
 * the scheduler, the preferences and the ledger are complete and tested; REAL SENDING IS
 * `Awaiting Credentials`, and nothing in this product claims otherwise.
 *
 * ## The fixtures live in `MailGallery`
 *
 * They moved there in MAIL-014, when the operator console gained a page that renders the same set.
 * Two callers building their own fixtures is how the gallery an operator opens and the files a
 * developer renders stop being the same product.
 */
final class RenderMailPreviews extends Command
{
    protected $signature = 'notifications:preview
        {--out=storage/app/mail-previews : Where to write the files}
        {--locale= : One language only — `ar` or `en`. Both by default}';

    protected $description = 'Render every email type to HTML, in both languages, for visual review.';

    public function handle(): int
    {
        $dir = (string) $this->option('out');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }

        $locales = $this->option('locale') !== null ? [(string) $this->option('locale')] : ['ar', 'en'];
        $written = 0;

        foreach ($locales as $locale) {
            foreach (MailGallery::messages($locale) as $name => $mailable) {
                $path = sprintf('%s/%s.%s.html', rtrim($dir, '/'), $name, $locale);
                file_put_contents($path, $mailable->render());
                $this->line($path);
                $written++;
            }
        }

        $this->info("{$written} previews written. Real sending remains Awaiting Credentials.");

        return self::SUCCESS;
    }
}
