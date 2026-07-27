<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers;

use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Models\RequestUploadSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Secure temporary uploads for the public intake form. Files are attached to an EXPIRING session before
 * the request exists, stored on a private disk under a UUID name (path never exposed), and associated to
 * the request on submit. Every operation requires the session's plaintext token (hashed at rest).
 */
final class UploadController
{
    /** POST /api/v1/requests/uploads/start — create an expiring upload session, return its token once. */
    public function start(): JsonResponse
    {
        $plain = Str::random(48);
        $session = RequestUploadSession::create([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours((int) config('requests.uploads.session_ttl_hours', 24)),
            'created_at' => now(),
        ]);

        return response()->json(['data' => ['upload_token' => $plain, 'expires_at' => $session->expires_at->toIso8601String()]], 201);
    }

    /** POST /api/v1/requests/uploads — upload one file into a session. */
    public function store(Request $request): JsonResponse
    {
        $session = $this->session($request);

        $maxKb = (int) config('requests.uploads.max_size_kb', 10240);
        $mimes = (array) config('requests.uploads.allowed_mimes', []);
        $request->validate([
            'file' => ['required', 'file', "max:$maxKb", 'mimetypes:'.implode(',', $mimes)],
        ]);

        // Enforce a per-session file cap.
        $max = (int) config('requests.uploads.max_files', 8);
        abort_if($session->files()->count() >= $max, 422, "A maximum of $max files is allowed.");

        $file = $request->file('file');
        $disk = (string) config('requests.uploads.disk', 'local');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $storedName = (string) Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $ext);
        // Private path, namespaced by session; never returned to the client.
        $path = $file->storeAs("requests/tmp/{$session->id}", $storedName, $disk);

        $record = RequestFile::create([
            'upload_session_id' => $session->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->sanitizeName((string) $file->getClientOriginalName()),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'is_client_visible' => true,
            'created_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $record->id,
            'original_name' => $record->original_name,
            'size' => $record->size,
            'mime' => $record->mime,
        ]], 201);
    }

    /** DELETE /api/v1/requests/uploads/{file} — remove a not-yet-submitted file from its session. */
    public function destroy(Request $request, int $file): JsonResponse
    {
        $session = $this->session($request);

        /** @var RequestFile|null $record */
        $record = $session->files()->whereNull('request_id')->find($file);
        abort_if($record === null, 404); // isolation: cannot touch another session's / a submitted file

        Storage::disk($record->disk)->delete($record->path);
        $record->delete();

        return response()->json(['data' => ['status' => 'deleted']]);
    }

    /** Resolve the session from its token, enforcing existence + expiry (non-revealing). */
    private function session(Request $request): RequestUploadSession
    {
        $token = (string) $request->input('upload_token', $request->header('X-Upload-Token', ''));
        abort_if($token === '', 422, 'Missing upload token.');

        $session = RequestUploadSession::where('token_hash', hash('sha256', $token))->first();
        abort_if($session === null, 404);
        if ($session->expires_at->isPast()) {
            throw ValidationException::withMessages(['upload_token' => 'This upload session has expired.']);
        }

        return $session;
    }

    private function sanitizeName(string $name): string
    {
        $name = basename($name);                       // strip any path components
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? $name; // control chars
        $name = preg_replace('/[\/\\\\]+/', '_', $name) ?? $name;

        return Str::limit(trim($name), 180, '');
    }
}
