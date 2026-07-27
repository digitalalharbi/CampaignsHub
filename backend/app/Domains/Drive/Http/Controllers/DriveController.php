<?php

declare(strict_types=1);

namespace App\Domains\Drive\Http\Controllers;

use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Drive\Services\DriveService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant Drive content-linking: link folders at a scope, browse their file metadata, attach files to targets.
 * Tenant isolation comes from the models' global scope (route-model binding 404s cross-tenant). Reads need
 * drive.view; mutations drive.manage.
 *
 * HONESTY: browsing returns an `awaiting_credentials` state (no files) until a real Google OAuth provider is
 * wired — the endpoint never fabricates a live Drive connection.
 */
final class DriveController extends Controller
{
    public function __construct(private readonly DriveService $drive) {}

    public function links(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('drive.view'), 403);

        return ApiResponse::success(
            DriveLink::query()->latest('created_at')->limit(200)->get()->all(),
            'Drive links.',
        );
    }

    public function linkFolder(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('drive.manage'), 403);

        $data = $request->validate([
            'scope' => ['required', 'string', 'in:tenant,client,project,campaign'],
            'scope_id' => ['nullable', 'uuid'],
            'folder_id' => ['required', 'string', 'max:255'],
            'folder_name' => ['required', 'string', 'max:255'],
            'connection_id' => ['nullable', 'uuid'],
        ]);

        $link = $this->drive->linkFolder(
            $data['scope'],
            $data['scope_id'] ?? null,
            $data['folder_id'],
            $data['folder_name'],
            [
                'connection_id' => $data['connection_id'] ?? null,
                'created_by' => $request->user()->id, // guaranteed by the drive.manage guard above
            ],
        );

        return ApiResponse::success($link, 'Drive folder linked.', status: 201);
    }

    public function files(Request $request, DriveLink $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('drive.view'), 403);

        $result = $this->drive->listFiles($link);

        return ApiResponse::success(
            $result['files'],
            'Drive files.',
            meta: ['state' => $result['state'], 'provider' => $result['provider'], 'configured' => $result['configured']],
        );
    }

    public function attachFile(Request $request, DriveFile $file): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('drive.manage'), 403);

        $data = $request->validate([
            'attached_to_type' => ['required', 'string', 'max:64'],
            'attached_to_id' => ['required', 'uuid'],
        ]);

        return ApiResponse::success(
            $this->drive->attachFile($file, $data['attached_to_type'], $data['attached_to_id']),
            'Drive file attached.',
        );
    }

    public function unlink(Request $request, DriveLink $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('drive.manage'), 403);

        $this->drive->unlink($link);

        return ApiResponse::success(null, 'Drive link removed.');
    }
}
