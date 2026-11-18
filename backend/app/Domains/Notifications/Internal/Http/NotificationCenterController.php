<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal\Http;

use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\Internal\EngineKinds;
use App\Domains\Notifications\Internal\Models\NotificationMute;
use App\Domains\Notifications\Internal\Models\NotificationSnooze;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\NotificationInbox;
use App\Domains\Notifications\Support\BellKind;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-V2-INTERNAL-001 — the centre's two personal actions beyond «read»: put one aside (1 hour, tomorrow) and mute one
 * TYPE for one PROJECT (spec §5). Both are per person: they never change what a colleague sees.
 *
 * A person acts only on a notification they may see (`NotificationInbox::visible`, the rule the bell has always used).
 */
final class NotificationCenterController extends Controller
{
    public function __construct(private readonly NotificationInbox $inbox, private readonly WorkspaceSettingsRepository $settings) {}

    public function snooze(Request $request, string $notification): JsonResponse
    {
        $data = $request->validate(['preset' => ['required', 'in:1h,tomorrow']]);
        $row = $this->visible($request, $notification);
        $tenantId = (string) app(TenantContext::class)->tenantId();

        $until = $data['preset'] === '1h'
            ? Carbon::now()->addHour()
            : Carbon::now($this->settings->for($tenantId)->timezone)->addDay()->setTime(8, 0)->setTimezone('UTC');

        NotificationSnooze::withoutGlobalScopes()->updateOrCreate(
            ['notification_id' => $row->getKey(), 'user_id' => $request->user()->id],
            ['tenant_id' => $tenantId, 'snoozed_until' => $until],
        );

        return ApiResponse::success([
            'snoozed_until' => $until->toIso8601String(),
            'unread' => $this->inbox->unreadCount($request->user()),
        ], 'Notification snoozed.');
    }

    public function mute(Request $request, string $notification): JsonResponse
    {
        $row = $this->visible($request, $notification);
        abort_if($row->project_id === null || $row->kind === null, 422, 'This notification has no project and type to mute.');
        $tenantId = (string) app(TenantContext::class)->tenantId();

        // Every name the same fact goes by, so the bell's older kind and the engine's event key are muted together.
        foreach (EngineKinds::aliases((string) $row->kind) as $kind) {
            NotificationMute::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $request->user()->id, 'project_id' => $row->project_id, 'kind' => $kind],
                ['tenant_id' => $tenantId],
            );
        }

        return ApiResponse::success(['unread' => $this->inbox->unreadCount($request->user())], 'Type muted for this project.');
    }

    public function mutes(Request $request): JsonResponse
    {
        $ar = ($request->user()->locale ?? 'ar') !== 'en';
        $rows = NotificationMute::withoutGlobalScopes()->where('tenant_id', app(TenantContext::class)->tenantId())->where('user_id', $request->user()->id)
            ->orderBy('created_at')->get();
        $names = DB::table('projects')->whereIn('id', $rows->pluck('project_id')->all())->pluck('name', 'id');

        // The aliases of one fact are one line for the person.
        $seen = [];
        $out = [];
        foreach ($rows as $m) {
            $key = $m->project_id.'|'.(EngineKinds::eventFor((string) $m->kind) ?? $m->kind);
            if (isset($seen[$key])) {
                $seen[$key][] = (string) $m->getKey();

                continue;
            }
            $seen[$key] = [(string) $m->getKey()];
            $out[$key] = ['project_id' => (string) $m->project_id, 'project_name' => (string) ($names[$m->project_id] ?? ''), 'kind' => (string) $m->kind, 'label' => BellKind::label((string) $m->kind, $ar) ?? (string) $m->kind];
        }
        $items = [];
        foreach ($out as $key => $item) {
            $items[] = $item + ['ids' => $seen[$key]];
        }

        return ApiResponse::success($items, 'Mutes retrieved.');
    }

    public function unmute(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:20'], 'ids.*' => ['uuid']]);
        $n = NotificationMute::withoutGlobalScopes()->where('tenant_id', app(TenantContext::class)->tenantId())->where('user_id', $request->user()->id)
            ->whereIn('id', $data['ids'])->delete();

        return ApiResponse::success(['removed' => $n, 'unread' => $this->inbox->unreadCount($request->user())], 'Mute removed.');
    }

    private function visible(Request $request, string $id): AppNotification
    {
        $row = $this->inbox->visible($request->user())->whereKey($id)->first();
        abort_unless($row instanceof AppNotification, 404);

        return $row;
    }
}
