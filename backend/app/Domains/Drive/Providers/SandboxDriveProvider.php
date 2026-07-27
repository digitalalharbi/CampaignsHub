<?php

declare(strict_types=1);

namespace App\Domains\Drive\Providers;

/**
 * Fully-functional, OFFLINE Drive adapter that returns a deterministic set of clearly-labelled demo files. It
 * never touches the network and every id is prefixed `sandbox-` so its data can never be mistaken for real
 * Google Drive content. It exists so the content-linking UI is fully exercisable in dev / E2E before real
 * OAuth credentials are provisioned.
 *
 * It reports isConfigured()=true because the demo data is real and usable end-to-end (the same stance as the
 * Connection Center's Sandbox connector). The service labels its state `sandbox_verified`, never
 * `production_verified`.
 */
final class SandboxDriveProvider implements DriveProvider
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @return list<array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}> */
    public function listFolder(string $folderId): array
    {
        return array_map(
            fn (array $spec): array => $this->file($folderId, $spec),
            $this->specs(),
        );
    }

    /** @return array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}|array{} */
    public function fileMetadata(string $fileId): array
    {
        foreach ($this->specs() as $spec) {
            $candidate = 'sandbox-file-'.$spec['n'];
            if ($candidate === $fileId) {
                // Bump the version/modified_time deterministically so refreshFile() has an observable effect.
                $spec['v'] = $spec['v'] + 1;

                return $this->file('sandbox-folder', $spec);
            }
        }

        return [];
    }

    public function authUrl(): string
    {
        return 'https://drive.example.test/sandbox/authorize';
    }

    /**
     * The deterministic demo catalogue. Kept tiny and stable so upserts are idempotent across runs.
     *
     * @return list<array{n: int, name: string, mime: string, size: int, v: int}>
     */
    private function specs(): array
    {
        return [
            ['n' => 1, 'name' => 'Campaign Brief.pdf', 'mime' => 'application/pdf', 'size' => 248_213, 'v' => 3],
            ['n' => 2, 'name' => 'Hero Creative.png', 'mime' => 'image/png', 'size' => 1_048_576, 'v' => 1],
            ['n' => 3, 'name' => 'Q3 Report.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => 82_944, 'v' => 5],
        ];
    }

    /**
     * @param  array{n: int, name: string, mime: string, size: int, v: int}  $spec
     * @return array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}
     */
    private function file(string $folderId, array $spec): array
    {
        $id = 'sandbox-file-'.$spec['n'];

        return [
            'file_id' => $id,
            'name' => $spec['name'],
            'mime' => $spec['mime'],
            'size' => $spec['size'],
            'thumbnail_link' => "https://drive.example.test/sandbox/{$id}/thumbnail.png",
            'web_view_link' => "https://drive.example.test/sandbox/{$folderId}/{$id}/view",
            'modified_time' => '2026-07-2'.$spec['n'].'T10:0'.$spec['n'].':00+00:00',
            'version' => (string) $spec['v'],
        ];
    }
}
