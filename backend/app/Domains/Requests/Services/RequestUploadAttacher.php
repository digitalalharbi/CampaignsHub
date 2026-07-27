<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestUploadSession;

/**
 * Attaches the files of a temporary upload session to a request as CLIENT-VISIBLE, then retires the session.
 * Shared by the public tracking reply and the client-portal reply so the logic lives in one place.
 */
final class RequestUploadAttacher
{
    public function attach(ExternalRequest $request, string $uploadToken): void
    {
        $session = RequestUploadSession::where('token_hash', hash('sha256', $uploadToken))->first();
        if ($session === null) {
            return;
        }
        $session->files()->whereNull('request_id')->update([
            'request_id' => $request->id,
            'upload_session_id' => null,
            'is_client_visible' => true,
        ]);
        $session->delete();
    }
}
