<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Reports\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the last hop: what a client's browser actually receives.
 *
 * ## What this proves that measuring the stored file does not
 *
 * `reports:pdf-facts` opens the file on disk. That is the file the renderer WROTE; it is not
 * necessarily the file the download route SERVES. Between them sit a token lookup, an expiry check, a
 * staleness rule that 409s a renderer-version mismatch, a disk read and a `Content-Disposition` whose
 * filename has to survive a mail server and an operating system. Every one of those has been wrong at
 * least once in this product's history, and none of them is exercised by reading the blob.
 *
 * So this asks the application for the download exactly as a browser would — through the route, with
 * the token — and compares what comes back against the bytes on disk.
 *
 * ## Through the kernel, not over the network
 *
 * A real HTTP round trip would test the edge proxy as well, which is worth knowing and is not what is
 * missing: the edge already answers `/r/{token}` correctly, proven separately. What was never checked
 * is this route's own behaviour on a production box, so the request is dispatched through the
 * application's own kernel — the same middleware, controller and file response a browser reaches,
 * without depending on the container being able to call itself.
 *
 * ## What it prints
 *
 * Assertions. Never the token, never the stored path, never the filename's client-identifying part,
 * and not one byte of the document — only whether each property held.
 */
final class DownloadCheckCommand extends Command
{
    protected $signature = 'reports:download-check {--format=pdf : The export format to check}';

    protected $description
        = 'Fetch the newest completed export through its own download route and compare it with the stored file.';

    public function handle(): int
    {
        $export = ReportExport::withoutGlobalScopes()
            ->where('format', (string) $this->option('format'))
            ->where('status', 'completed')
            ->whereNotNull('path')
            ->whereNotNull('signed_token')
            ->orderByDesc('created_at')
            ->first();

        if ($export === null) {
            $this->error('No completed export with a download token. Render one first.');

            return self::FAILURE;
        }

        if (! Storage::disk((string) $export->disk)->exists((string) $export->path)) {
            $this->error('The export row points at a file this disk does not hold.');

            return self::FAILURE;
        }

        $stored = (string) Storage::disk((string) $export->disk)->get((string) $export->path);

        try {
            /*
             * Built from the ROUTE NAME, so a rename cannot leave this checking an address nobody serves.
             *
             * The name carries the api/version prefix the group applies — `api.v1.reports.download`, not
             * `reports.download`. The first version guessed the short name and the command reported «the
             * download route could not be reached», which reads like a broken route and was a wrong key.
             */
            $url = route('api.v1.reports.download', ['token' => $export->signed_token], false);
            $response = app()->handle(Request::create($url, 'GET'));
        } catch (Throwable $e) {
            $this->error('The download route could not be reached: '.$e->getMessage());

            return self::FAILURE;
        }

        $status = $response->getStatusCode();
        $body = $this->bodyOf($response);
        $disposition = (string) $response->headers->get('Content-Disposition');

        $checks = [
            'http 200' => $status === 200,
            'content type is a pdf' => str_contains((string) $response->headers->get('Content-Type'), 'pdf'),
            'body starts with %PDF' => str_starts_with($body, '%PDF-'),
            'body is not empty' => strlen($body) > 0,
            /*
             * The served bytes ARE the stored bytes.
             *
             * A route that re-rendered, truncated or served a different export would pass every check
             * above. Comparing the digests is the only one that says «the client got this file».
             */
            'served bytes match the stored file' => hash('sha256', $body) === hash('sha256', $stored),
            /*
             * REPORT-TITLE-METADATA-001 — the filename crosses a mail server, a browser and an OS, and
             * the failure is silent. It is transliterated to ASCII on purpose, so a non-ASCII byte here
             * is the defect that test exists for.
             */
            'filename is ascii' => $disposition !== '' && preg_match('/[^\x20-\x7E]/', $disposition) !== 1,
            'filename is not the stored uuid' => $disposition !== '' && ! str_contains($disposition, (string) $export->id),
        ];

        foreach ($checks as $name => $ok) {
            $this->line(sprintf('  %s %s', $ok ? '✅' : '❌', $name));
        }

        $this->newLine();
        $this->line(sprintf('  served bytes      %d', strlen($body)));
        $this->line(sprintf('  stored bytes      %d', strlen($stored)));
        $this->line(sprintf('  http status       %d', $status));

        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));

        if ($failed !== []) {
            $this->newLine();
            $this->error('The download a client would receive failed: '.implode('; ', $failed));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('The download route serves the stored file, as a client would receive it.');

        return self::SUCCESS;
    }

    /** A streamed download has no `getContent()`; both shapes have to be read the same way. */
    private function bodyOf(mixed $response): string
    {
        if (method_exists($response, 'getFile')) {
            $file = $response->getFile();

            return is_object($file) && method_exists($file, 'getPathname')
                ? (string) file_get_contents($file->getPathname())
                : '';
        }

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
