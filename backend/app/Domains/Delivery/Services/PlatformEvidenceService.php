<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Services;

use App\Domains\Delivery\Models\PlatformEvidence;
use App\Models\User;
use App\Support\AdPlatforms;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WD-EVIDENCE-001 — a provider screenshot attached while the Owner reviews a report.
 *
 * Stored on the private disk under the tenant and the client, never under a path a visitor can guess; read
 * back only through an authorised endpoint. It is evidence for the reader's trust and is NEVER an input to
 * a figure: nothing here is parsed, and the structured provider data stays the only source of calculation.
 */
final class PlatformEvidenceService
{
    public const MAX_KB = 10240;

    public const MIMES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];

    public function store(
        string $tenantId,
        string $clientWorkspaceId,
        ?string $projectId,
        string $provider,
        Carbon $from,
        Carbon $to,
        UploadedFile $file,
        ?string $note,
        ?Carbon $capturedAt,
        ?User $by,
        bool $includeInReport = true,
    ): PlatformEvidence {
        $path = $file->storeAs(
            "evidence/{$tenantId}/{$clientWorkspaceId}",
            Str::uuid()->toString().'.'.($file->extension() ?: 'bin'),
            'local',
        );

        return PlatformEvidence::create([
            'tenant_id' => $tenantId,
            'client_workspace_id' => $clientWorkspaceId,
            'project_id' => $projectId,
            'provider' => AdPlatforms::canonical($provider),
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'captured_at' => $capturedAt,
            'uploaded_by' => $by?->id,
            'note' => $note,
            'include_in_report' => $includeInReport,
        ]);
    }

    public function delete(PlatformEvidence $evidence): void
    {
        Storage::disk($evidence->disk)->delete($evidence->path);
        $evidence->delete();
    }
}
