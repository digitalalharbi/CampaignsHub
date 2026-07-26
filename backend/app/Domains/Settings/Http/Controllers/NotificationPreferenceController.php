<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-user notification preferences (channels, categories, quiet hours, frequency, project scope).
 * Each user manages only their own row; the record is tenant-scoped. No permission gate beyond being
 * authenticated — a user always controls their own delivery.
 */
final class NotificationPreferenceController extends Controller
{
    private const CATEGORIES = ['budget', 'performance', 'sync', 'token', 'reports', 'security'];

    private const DEFAULTS_CHANNELS = ['in_app' => true, 'email' => true];

    public function show(Request $request): JsonResponse
    {
        $row = $this->row($request);

        return ApiResponse::success([
            'channels' => $row->channels ?? self::DEFAULTS_CHANNELS,
            'categories' => $row->categories ?? $this->defaultCategories(),
            'quiet_hours' => $row->quiet_hours ?? ['enabled' => false, 'start' => '22:00', 'end' => '08:00'],
            'frequency' => $row->frequency ?? 'realtime',
            'project_ids' => $row->project_ids,
            'available_categories' => self::CATEGORIES,
        ], 'Notification preferences retrieved.');
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $userId = $request->user()->id;

        $data = $request->validate([
            'channels' => ['required', 'array'],
            'channels.in_app' => ['boolean'],
            'channels.email' => ['boolean'],
            'categories' => ['required', 'array'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.enabled' => ['boolean'],
            'quiet_hours.start' => ['nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['nullable', 'date_format:H:i'],
            'frequency' => ['required', 'in:realtime,hourly,daily'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['uuid'],
        ]);

        DB::table('notification_preferences')->updateOrInsert(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            [
                'id' => (string) Str::uuid(),
                'channels' => json_encode($data['channels']),
                'categories' => json_encode($data['categories']),
                'quiet_hours' => isset($data['quiet_hours']) ? json_encode($data['quiet_hours']) : null,
                'frequency' => $data['frequency'],
                'project_ids' => isset($data['project_ids']) ? json_encode($data['project_ids']) : null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->show($request);
    }

    private function row(Request $request): object
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $request->user()->id)->first();
        if ($row) {
            foreach (['channels', 'categories', 'quiet_hours', 'project_ids'] as $k) {
                $row->{$k} = $row->{$k} !== null ? json_decode($row->{$k}, true) : null;
            }
        }

        return $row ?? (object) ['channels' => null, 'categories' => null, 'quiet_hours' => null, 'frequency' => null, 'project_ids' => null];
    }

    /** @return array<string, array<string,bool>> */
    private function defaultCategories(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $c) {
            $out[$c] = ['in_app' => true, 'email' => $c !== 'performance'];
        }

        return $out;
    }
}
