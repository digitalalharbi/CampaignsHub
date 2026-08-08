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
use Illuminate\Validation\Rule;

/**
 * Per-user notification preferences (channels, categories, quiet hours, frequency, project scope).
 * Each user manages only their own row; the record is tenant-scoped. No permission gate beyond being
 * authenticated — a user always controls their own delivery.
 */
final class NotificationPreferenceController extends Controller
{
    private const CATEGORIES = ['budget', 'performance', 'sync', 'token', 'reports', 'security'];

    private const DEFAULTS_CHANNELS = ['in_app' => true, 'email' => true];

    /**
     * The digests, and their default — MAIL-004.
     *
     * Both default to FALSE. A digest that arrives because nobody turned it off is not a preference,
     * it is a mailing list, and the first scheduled run after a deploy is the worst possible moment
     * to discover the difference.
     */
    /**
     * The rhythms a person can opt into.
     *
     * `alerts` joins the same map rather than getting a column of its own, because it answers the
     * same question — «what may reach my inbox on its own?» — and a row written before it existed
     * has no `alerts` key, which reads as «never asked» exactly as it should.
     */
    private const DIGESTS = ['daily', 'weekly', 'alerts'];

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
            'digests' => $row->digests ?? ['daily' => false, 'weekly' => false],
            'available_digests' => self::DIGESTS,
            // The reader's own clock and language, which is what makes «daily» mean their morning.
            'timezone' => $row->timezone ?? 'Asia/Riyadh',
            'locale' => $row->locale ?? 'ar',
            'digest_hour' => (int) ($row->digest_hour ?? 8),
            'available_timezones' => timezone_identifiers_list(),
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
            'digests' => ['nullable', 'array'],
            'digests.daily' => ['boolean'],
            'digests.weekly' => ['boolean'],
            /*
             * The timezone is validated against PHP's OWN list rather than a regex.
             *
             * A stored identifier this process cannot resolve would throw inside the hourly sweep and
             * abort it — one malformed row silencing every other recipient's digest.
             */
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            'locale' => ['nullable', 'in:ar,en'],
            'digest_hour' => ['nullable', 'integer', 'between:0,23'],
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
                'digests' => isset($data['digests']) ? json_encode($data['digests']) : null,
                'timezone' => $data['timezone'] ?? 'Asia/Riyadh',
                'locale' => $data['locale'] ?? 'ar',
                'digest_hour' => $data['digest_hour'] ?? 8,
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
            foreach (['channels', 'categories', 'quiet_hours', 'project_ids', 'digests'] as $k) {
                $row->{$k} = $row->{$k} !== null ? json_decode($row->{$k}, true) : null;
            }
        }

        return $row ?? (object) [
            'channels' => null, 'categories' => null, 'quiet_hours' => null, 'frequency' => null,
            'project_ids' => null, 'digests' => null, 'timezone' => null, 'locale' => null, 'digest_hour' => null,
        ];
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
