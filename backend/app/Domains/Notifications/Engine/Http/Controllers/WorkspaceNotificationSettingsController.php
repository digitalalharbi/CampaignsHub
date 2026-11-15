<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Http\Controllers;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\EventDefinition;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The ONE workspace-level notification settings page's data (spec §0, §2, §14): the same component serves an agency,
 * a direct business and a client-portal workspace, so everything type-specific arrives as DATA here — roles, terms,
 * default routing — and nothing in the answer or in the code is tied to a concrete workspace type.
 *
 * Reads and writes `workspace_notification_settings` (builder M's table; one row per workspace, optional). A workspace
 * with no row works on defaults; the first save creates it.
 *
 * ## Writes and the plan
 *
 * The internal part (routing, quiet hours, priorities, follow-up) needs `notifications.internal`; the external part
 * (sender, CC/BCC, phrasing, subject templates, disclaimer, currency, «طلب تواصل») needs `notifications.external`. A key
 * that is view-only or off refuses its own part with 403 and `meta.upgrade`, and leaves the rest of the request
 * applied: settings stay visible and the other part stays editable (spec §1: never break).
 */
final class WorkspaceNotificationSettingsController extends Controller
{
    /** The `settings` json keys the page owns, with the entitlement that edits each (null = always editable). */
    private const SETTING_KEYS = [
        'default_sound' => [EntitlementKey::Internal, 'bool'],
        'priorities' => [EntitlementKey::Internal, 'map'],
        'followup' => [EntitlementKey::Internal, 'map'],
        'grouping_window_minutes' => [EntitlementKey::Internal, 'int'],
        'sender' => [EntitlementKey::External, 'map'],
        'cc_bcc' => [EntitlementKey::External, 'map'],
        'phrasing' => [EntitlementKey::External, 'map'],
        'currency' => [EntitlementKey::External, 'string'],
        'disclaimer' => [EntitlementKey::External, 'map'],
        'subject_templates' => [EntitlementKey::External, 'map'],
        'contact_request' => [EntitlementKey::External, 'bool'],
        'first_run_completed' => [EntitlementKey::Internal, 'bool'],
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkspaceSettingsRepository $settings,
        private readonly WorkspaceTypes $types,
        private readonly EventRegistry $events,
        private readonly EntitlementGate $gate,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return ApiResponse::success($this->payload((string) $this->tenants->tenantId()));
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
        $tenantId = (string) $this->tenants->tenantId();
        $profile = $this->types->profileFor($tenantId);
        $channels = array_map(static fn (Channel $c): string => $c->value, Channel::cases());

        $data = $request->validate([
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'work_days' => ['sometimes', 'array', 'max:7'],
            'work_days.*' => ['integer', 'between:0,6'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['required_with:quiet_hours', 'boolean'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i'],
            'holidays' => ['sometimes', 'array', 'max:120'],
            'holidays.*' => ['date_format:Y-m-d'],
            'org_domains' => ['sometimes', 'array', 'max:20'],
            'org_domains.*' => ['string', 'max:190', 'regex:/^(?!-)[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'routing' => ['sometimes', 'array'],
            'routing.*.roles' => ['sometimes', 'array'],
            'routing.*.roles.*' => ['string', 'in:'.implode(',', $profile->roles)],
            'routing.*.channels' => ['sometimes', 'array'],
            'routing.*.channels.*' => ['string', 'in:'.implode(',', $channels)],
            'routing.*.enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
        ]);

        foreach (array_keys((array) ($data['routing'] ?? [])) as $eventKey) {
            abort_if($this->events->get((string) $eventKey) === null, 422, 'Unknown event.');
        }

        $current = DB::table('workspace_notification_settings')->where('tenant_id', $tenantId)->first();
        $denied = [];
        $patch = [];

        $internalOk = $this->gate->state($tenantId, EntitlementKey::Internal)->canEdit();
        foreach (['quiet_hours', 'work_days', 'holidays', 'routing'] as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }
            if (! $internalOk) {
                $denied[] = EntitlementKey::Internal->value;

                continue;
            }
            $patch[$column] = json_encode($data[$column], JSON_UNESCAPED_UNICODE);
        }
        // Timezone and org domains are shared by both sections: editable while either part is.
        $either = $internalOk || $this->gate->state($tenantId, EntitlementKey::External)->canEdit();
        foreach (['timezone', 'org_domains'] as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }
            if (! $either) {
                $denied[] = EntitlementKey::Internal->value;

                continue;
            }
            $patch[$column] = $column === 'org_domains'
                ? json_encode(array_values(array_unique(array_map(static fn ($d): string => strtolower(trim((string) $d)), $data[$column]))))
                : $data[$column];
        }

        if (array_key_exists('settings', $data)) {
            $merged = $current !== null && $current->settings !== null ? (array) json_decode((string) $current->settings, true) : [];
            $accepted = false;
            foreach ((array) $data['settings'] as $key => $value) {
                if (! isset(self::SETTING_KEYS[$key])) {
                    continue; // a key this page does not own is not stored: no free-form blob
                }
                [$entitlement, $kind] = self::SETTING_KEYS[$key];
                if (! $this->gate->state($tenantId, $entitlement)->canEdit()) {
                    $denied[] = $entitlement->value;

                    continue;
                }
                $merged[$key] = $this->coerce($kind, $value);
                $accepted = true;
            }
            if ($accepted) {
                $patch['settings'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
            }
        }

        if ($patch !== []) {
            $now = now();
            if ($current === null) {
                DB::table('workspace_notification_settings')->insert($patch + ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('workspace_notification_settings')->where('tenant_id', $tenantId)->update($patch + ['version' => (int) $current->version + 1, 'updated_at' => $now]);
            }
            $this->settings->forget($tenantId);
        }

        $payload = $this->payload($tenantId);
        if ($denied !== [] && $patch === []) {
            return ApiResponse::error(null, ['entitlement' => array_values(array_unique($denied))], ['upgrade' => true], 403);
        }

        return ApiResponse::success($payload + ['refused' => array_values(array_unique($denied))]);
    }

    /** The KPI strips of §2: team unread, open follow-up tasks, push failures — and the six external figures. */
    public function kpis(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
        $tenantId = (string) $this->tenants->tenantId();
        $tz = $this->settings->for($tenantId)->timezone;
        $weekAgo = now()->subDays(7);
        $monthStart = now($tz)->startOfMonth()->utc();

        $log = static fn () => DB::table('notification_delivery_log')->where('tenant_id', $tenantId);
        // «Held» counts a message still waiting: a held row with no later terminal row for the same delivery.
        $held = static fn (string $status): int => $log()->where('status', $status)
            ->whereNotExists(static function ($q) use ($tenantId): void {
                $q->selectRaw('1')->from('notification_delivery_log as t')
                    ->whereColumn('t.delivery_id', 'notification_delivery_log.delivery_id')
                    ->where('t.tenant_id', $tenantId)
                    ->whereIn('t.status', ['sent', 'skipped', 'failed']);
            })->count();

        return ApiResponse::success([
            'internal' => [
                'team_unread' => DB::table('app_notifications')->where('tenant_id', $tenantId)->where('status', 'unread')->count(),
                // The follow-up task list ships with the notification centre (builder P); until its table exists the figure is unknown, not zero.
                'open_followup_tasks' => $this->followupOpenTasks($tenantId),
                'push_failures' => $log()->where('scope', 'internal')->where('channel', 'push')->where('status', 'failed')->where('created_at', '>=', $weekAgo)->count(),
            ],
            'external' => [
                'held_for_review' => $held('held_for_review'),
                'held_for_privacy' => $held('held_for_privacy'),
                'sent_this_week' => $log()->where('scope', 'external')->where('status', 'sent')->where('created_at', '>=', $weekAgo)->count(),
                'failed' => $log()->where('scope', 'external')->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count(),
                'awaiting_confirmation' => DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('status', 'pending')->count(),
                'unsubscribed_this_month' => DB::table('notification_subscription_log')->where('tenant_id', $tenantId)->where('action', 'unsubscribed')->where('created_at', '>=', $monthStart)->count(),
            ],
        ]);
    }

    private function followupOpenTasks(string $tenantId): ?int
    {
        foreach (['notification_followup_tasks', 'followup_tasks'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id') && Schema::hasColumn($table, 'status')) {
                return DB::table($table)->where('tenant_id', $tenantId)->where('status', 'open')->count();
            }
        }

        return null;
    }

    private function coerce(string $kind, mixed $value): mixed
    {
        return match ($kind) {
            'bool' => (bool) $value,
            'int' => max(1, min(120, (int) $value)),
            'string' => Str::limit(strip_tags((string) $value), 8, ''),
            default => $this->cleanMap($value),
        };
    }

    /** @return array<string, mixed> */
    private function cleanMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = is_array($v) ? $this->cleanMap($v) : (is_string($v) ? Str::limit(strip_tags($v), 4000, '') : $v);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function payload(string $tenantId): array
    {
        $s = $this->settings->for($tenantId);
        $profile = $this->types->profileFor($tenantId);
        $labels = static fn (string $key): array => ['ar' => (string) trans($key, [], 'ar'), 'en' => (string) trans($key, [], 'en')];
        $row = DB::table('workspace_notification_settings')->where('tenant_id', $tenantId)->first();

        $events = [];
        foreach ($this->events->all() as $def) {
            /** @var EventDefinition $def */
            $route = $s->routeFor($def->key);
            $events[] = [
                'key' => $def->key,
                'scope' => $def->scope->value,
                'priority' => $def->priority->value,
                'channels' => array_map(static fn (Channel $c): string => $c->value, $def->channels),
                'internal_only' => $def->internalOnly,
                'creates_task' => $def->createsTask,
                'enabled' => $def->enabled,
                'default_roles' => $def->rolesFor($profile->key),
                'title' => [
                    'internal' => $def->scope->value === 'external' ? null : $labels($def->textKey(Scope::Internal, 'title')),
                    'external' => $def->scope->value === 'internal' ? null : $labels($def->textKey(Scope::External, 'title')),
                ],
                'route' => [
                    'roles' => array_values($route['roles'] ?? $def->rolesFor($profile->key)),
                    'channels' => array_values($route['channels'] ?? array_map(static fn (Channel $c): string => $c->value, $def->channels)),
                    'enabled' => (bool) ($route['enabled'] ?? true),
                    'customised' => $route !== [],
                ],
            ];
        }

        return [
            'workspace' => [
                'name' => $s->workspaceName,
                'type' => $profile->toArray() + [
                    'role_labels' => array_combine($profile->roles, array_map(static fn (string $r): array => $labels('notifications_v2.roles.'.$r), $profile->roles)),
                    'term_labels' => array_map($labels, $profile->terminology),
                ],
            ],
            'settings' => [
                'timezone' => $s->timezone,
                'work_days' => $s->workDays,
                'quiet_hours' => $s->quietHours,
                'holidays' => $s->holidays,
                'org_domains' => $s->orgDomains,
                'routing' => $s->routing,
                'settings' => $s->settings,
                'version' => (int) ($row->version ?? 0),
            ],
            'events' => $events,
            'first_run' => $row === null || ($s->settings['first_run_completed'] ?? false) !== true,
        ];
    }
}
