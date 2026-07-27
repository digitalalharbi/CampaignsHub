<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestAccessToken;
use App\Domains\Requests\Models\RequestComment;
use App\Domains\Requests\Models\RequestEvent;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Services\RequestIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Public (unauthenticated) request endpoints: intake + secure tracking. The tracking view exposes ONLY
 * client-safe fields — never internal notes, assignee, SLA, tenant or internal ids.
 */
final class PublicRequestController
{
    public function __construct(private readonly RequestIntake $intake) {}

    /** GET /api/v1/requests/meta — public catalog for the intake form (active service types). */
    public function meta(): JsonResponse
    {
        $types = RequestType::where('is_active', true)->orderBy('sort')
            ->get(['key', 'module', 'name_ar', 'name_en'])
            ->map(fn (RequestType $t) => [
                'key' => $t->key, 'module' => $t->module, 'name_ar' => $t->name_ar, 'name_en' => $t->name_en,
            ]);

        return response()->json(['data' => ['types' => $types]]);
    }

    /** POST /api/v1/requests — public intake. */
    public function store(Request $request): JsonResponse
    {
        // Honeypot: real users never fill this hidden field; bots do.
        if (filled($request->input('website'))) {
            throw ValidationException::withMessages(['website' => 'Rejected.']);
        }

        $data = $request->validate([
            'type' => ['required', 'string', Rule::exists('request_types', 'key')->where('is_active', true)],
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'contact_email' => ['required', 'email', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]*$/'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'priority' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'metadata' => ['nullable', 'array'],
        ]);

        ['request' => $req, 'token' => $token] = $this->intake->create($data);

        return response()->json([
            'data' => [
                'reference' => $req->reference,
                'type' => $req->type->name_en,
                'status' => 'new',
                'submitted_at' => optional($req->submitted_at)->toIso8601String(),
                'tracking_token' => $token,          // shown once
                'tracking_url' => "/requests/track/{$token}",
                'email_delivery' => 'awaiting_credentials', // honest: mailer not configured
                'next_step' => 'Our team will review your request shortly.',
            ],
        ], 201);
    }

    /** GET /api/v1/requests/track/{token} — client-safe status view. */
    public function track(string $token): JsonResponse
    {
        $record = RequestAccessToken::where('token_hash', hash('sha256', $token))->first();

        abort_if($record === null, 404);
        abort_if($record->revoked_at !== null, 410, 'This tracking link has been revoked.');
        abort_if($record->expires_at !== null && $record->expires_at->isPast(), 410, 'This tracking link has expired.');

        $record->forceFill(['last_used_at' => now()])->save();

        /** @var ExternalRequest $req */
        $req = ExternalRequest::with(['type', 'status'])->findOrFail($record->request_id);

        // Only client-visible events; internal notes and metadata are never exposed here.
        $timeline = $req->events()
            ->where('is_client_visible', true)
            ->orderBy('created_at')
            ->get(['type', 'to_status', 'message', 'created_at'])
            ->map(fn (RequestEvent $e) => [
                'type' => $e->type,
                'status' => $e->to_status,
                'message' => $e->message,
                'at' => optional($e->created_at)->toIso8601String(),
            ]);

        $clientComments = $req->comments()
            ->where('visibility', 'client')
            ->orderBy('created_at')
            ->get(['author_label', 'body', 'created_at'])
            ->map(fn (RequestComment $c) => ['author' => $c->author_label ?? 'Team', 'body' => $c->body, 'at' => optional($c->created_at)->toIso8601String()]);

        return response()->json([
            'data' => [
                'reference' => $req->reference,
                'type' => $req->type->name_en,
                'type_ar' => $req->type->name_ar,
                'status' => $req->status->is_client_visible ? $req->status->key : 'in_progress',
                'status_label' => $req->status->is_client_visible ? $req->status->name_en : 'In progress',
                'submitted_at' => optional($req->submitted_at)->toIso8601String(),
                'updated_at' => optional($req->updated_at)->toIso8601String(),
                'timeline' => $timeline,
                'comments' => $clientComments,
            ],
        ]);
    }
}
