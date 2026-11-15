<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Http\Controllers;

use App\Domains\Notifications\Engine\Catalog\ConfigEventRegistry;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\External\Content\Footprint;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The platform admin's notifications console (spec §15) — behind the `platform` gate with the rest of `/admin`.
 *
 * Event catalog, plan features (keys, limits, grace period, «keep existing»), the platform text layer (phrasing
 * dictionary, default disclaimer, footprint invitation, templates per workspace type) and platform statistics. It
 * never reads a workspace's recipients, addresses or message content: statistics are counts per workspace.
 */
final class AdminNotificationsController extends Controller
{
    private const TEXT_KEYS = ['phrasing.dictionary', 'disclaimer.default', 'footprint.invitation'];

    public function __construct(
        private readonly EventRegistry $events,
        private readonly WorkspaceTypes $types,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function show(): JsonResponse
    {
        $labels = static fn (string $key): array => ['ar' => (string) trans($key, [], 'ar'), 'en' => (string) trans($key, [], 'en')];
        $catalog = [];
        foreach ($this->events->all() as $def) {
            $catalog[] = [
                'key' => $def->key,
                'scope' => $def->scope->value,
                'priority' => $def->priority->value,
                'channels' => array_map(static fn (Channel $c): string => $c->value, $def->channels),
                'default_roles' => $def->defaultRoles,
                'entitlement' => $def->entitlement?->value,
                'creates_task' => $def->createsTask,
                'internal_only' => $def->internalOnly,
                'enabled' => $def->enabled,
                'version' => $def->version,
                'title' => $labels($def->textKey($def->scope === Scope::External ? Scope::External : Scope::Internal, 'title')),
            ];
        }

        $types = [];
        foreach ($this->types->keys() as $key) {
            $profile = $this->types->profile($key);
            $types[] = [
                'key' => $key,
                'roles' => array_map(static fn (string $r): array => ['key' => $r] + $labels('notifications_v2.roles.'.$r), $profile->roles),
                'client_role' => $profile->clientRole,
                'responsible_role' => $profile->responsibleRole,
                'terms' => array_map($labels, $profile->terminology),
            ];
        }

        return ApiResponse::success([
            'catalog' => $catalog,
            'workspace_types' => $types,
            'entitlement_keys' => array_map(static fn (EntitlementKey $k): array => ['key' => $k->value, 'default' => (bool) ((array) config('notifications_v2.entitlements.defaults', []))[$k->value]], EntitlementKey::cases()),
            'limit_defaults' => (array) config('notifications_v2.entitlements.limits', []),
            'grace_days_default' => (int) config('notifications_v2.entitlements.grace_days', 14),
            'plans' => SubscriptionPlan::query()->orderBy('code')->get(['code', 'name', 'name_ar'])->map(static fn ($p): array => ['code' => $p->code, 'name' => $p->name, 'name_ar' => $p->name_ar])->all(),
            'plan_features' => DB::table('notification_plan_features')->orderBy('key')->get()->map(static fn (object $r): array => [
                'id' => $r->id, 'key' => $r->key, 'plan_code' => $r->plan_code, 'workspace_type' => $r->workspace_type, 'enabled' => (bool) $r->enabled,
                'limits' => $r->limits === null ? null : (is_string($r->limits) ? json_decode($r->limits, true) : $r->limits),
                'grace_days' => (int) $r->grace_days, 'keep_existing' => (bool) $r->keep_existing, 'paid_from' => $r->paid_from,
            ])->all(),
            'texts' => $this->texts(),
        ]);
    }

    public function updateEvent(Request $request, string $eventKey): JsonResponse
    {
        $current = $this->events->get($eventKey);
        abort_if($current === null, 404);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'in:'.implode(',', array_map(static fn (Priority $p): string => $p->value, Priority::cases()))],
            'scope' => ['sometimes', 'in:internal,external,both'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['in:'.implode(',', array_map(static fn (Channel $c): string => $c->value, Channel::cases()))],
            'default_roles' => ['sometimes', 'array'],
            'default_roles.*' => ['array'],
            'default_roles.*.*' => ['string', 'max:40'],
            'creates_task' => ['sometimes', 'boolean'],
        ]);
        // A sensitive operational event can never be moved to the external scope (spec §7); the registry enforces it too.
        abort_if($current->internalOnly && in_array($data['scope'] ?? 'internal', ['external', 'both'], true), 422, 'An internal-only event cannot be external.');

        $row = DB::table('notification_event_catalog')->where('event_key', $eventKey)->first();
        $overrides = $row !== null ? (array) json_decode((string) $row->overrides, true) : [];
        foreach (['priority', 'scope', 'channels', 'default_roles', 'creates_task'] as $field) {
            if (array_key_exists($field, $data)) {
                $overrides[$field] = $data[$field];
            }
        }
        $enabled = (bool) ($data['enabled'] ?? ($row->enabled ?? true));
        $payload = ['overrides' => json_encode($overrides), 'enabled' => $enabled, 'updated_by' => $request->user()?->getKey(), 'updated_at' => now()];
        if ($row === null) {
            DB::table('notification_event_catalog')->insert($payload + ['id' => (string) Str::uuid(), 'event_key' => $eventKey, 'version' => 1, 'created_at' => now()]);
        } else {
            DB::table('notification_event_catalog')->where('event_key', $eventKey)->update($payload + ['version' => (int) $row->version + 1]);
        }
        if ($this->events instanceof ConfigEventRegistry) {
            $this->events->flush();
        }

        return $this->show();
    }

    public function upsertPlanFeature(Request $request): JsonResponse
    {
        $limitKeys = array_keys((array) config('notifications_v2.entitlements.limits', []));
        $data = $request->validate([
            'key' => ['required', 'in:'.implode(',', array_map(static fn (EntitlementKey $k): string => $k->value, EntitlementKey::cases()))],
            'plan_code' => ['nullable', 'string', 'max:60'],
            'workspace_type' => ['nullable', 'string', 'in:'.implode(',', $this->types->keys())],
            'enabled' => ['required', 'boolean'],
            'limits' => ['nullable', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'grace_days' => ['sometimes', 'integer', 'between:0,365'],
            'keep_existing' => ['sometimes', 'boolean'],
            'paid_from' => ['nullable', 'date'],
        ]);
        foreach (array_keys((array) ($data['limits'] ?? [])) as $limit) {
            abort_unless(in_array($limit, $limitKeys, true), 422, 'Unknown limit.');
        }

        $match = DB::table('notification_plan_features')->where('key', $data['key'])
            ->where(static fn ($q) => ($data['plan_code'] ?? null) === null ? $q->whereNull('plan_code') : $q->where('plan_code', $data['plan_code']))
            ->where(static fn ($q) => ($data['workspace_type'] ?? null) === null ? $q->whereNull('workspace_type') : $q->where('workspace_type', $data['workspace_type']));
        $values = [
            'enabled' => $data['enabled'],
            'limits' => array_key_exists('limits', $data) && $data['limits'] !== null ? json_encode($data['limits']) : null,
            'grace_days' => $data['grace_days'] ?? (int) config('notifications_v2.entitlements.grace_days', 14),
            'keep_existing' => $data['keep_existing'] ?? true,
            // Switching a key off for a plan records WHEN it stopped being free: that is what makes grace and «keep existing» apply.
            'paid_from' => $data['enabled'] ? null : ($data['paid_from'] ?? now()),
            'updated_by' => $request->user()?->getKey(),
            'updated_at' => now(),
        ];
        if ($match->exists()) {
            $match->update($values);
        } else {
            DB::table('notification_plan_features')->insert($values + [
                'id' => (string) Str::uuid(), 'key' => $data['key'], 'plan_code' => $data['plan_code'] ?? null, 'workspace_type' => $data['workspace_type'] ?? null, 'created_at' => now(),
            ]);
        }
        $this->forgetGates();

        return $this->show();
    }

    public function deletePlanFeature(string $id): JsonResponse
    {
        DB::table('notification_plan_features')->where('id', $id)->delete();
        $this->forgetGates();

        return $this->show();
    }

    public function updateText(Request $request, string $key): JsonResponse
    {
        $allowed = array_merge(self::TEXT_KEYS, array_map(static fn (string $t): string => 'templates.'.$t, $this->types->keys()));
        abort_unless(in_array($key, $allowed, true), 404);
        $data = $request->validate(['value' => ['required', 'array']]);
        $clean = $this->clean($data['value']);
        $row = DB::table('notification_platform_texts')->where('key', $key)->first();
        if ($row === null) {
            DB::table('notification_platform_texts')->insert(['key' => $key, 'value' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'version' => 1, 'updated_by' => $request->user()?->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('notification_platform_texts')->where('key', $key)->update(['value' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'version' => (int) $row->version + 1, 'updated_by' => $request->user()?->getKey(), 'updated_at' => now()]);
        }

        return $this->show();
    }

    /** Platform statistics (spec §15): counts only — never a recipient, an address or a message. */
    public function stats(): JsonResponse
    {
        $period = now()->format('Y-m');
        $monthStart = now()->startOfMonth();
        $log = static fn () => DB::table('notification_delivery_log')->where('scope', 'external')->where('created_at', '>=', $monthStart);
        $sent = (clone $log())->where('status', 'sent')->count();
        $failed = (clone $log())->where('status', 'failed')->count();
        $unsub = DB::table('notification_subscription_log')->where('action', 'unsubscribed')->where('created_at', '>=', $monthStart)->count();

        $perWorkspace = DB::table('usage_counters')->where('period', $period)->where('metric', 'like', 'notif.%')->get(['tenant_id', 'metric', 'count'])
            ->groupBy('tenant_id');
        $names = Tenant::query()->withoutGlobalScopes()->whereIn('id', $perWorkspace->keys()->all())->get(['id', 'name'])->keyBy('id');
        $rows = [];
        foreach ($perWorkspace as $tenantId => $counts) {
            $tenant = $names->get($tenantId);
            $by = $counts->pluck('count', 'metric');
            $rows[] = [
                'workspace' => $tenant === null ? $tenantId : $tenant->name,
                'plan' => $tenant === null ? null : $this->subscriptions->currentPlan($tenant)?->code,
                'messages' => (int) ($by['notif.external_messages'] ?? 0),
                'recipients' => (int) ($by['notif.external_recipients'] ?? 0),
                'push' => (int) ($by['notif.push_notifications'] ?? 0),
                'charts' => (int) ($by['notif.charts_generated'] ?? 0),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['messages'] <=> $a['messages']);

        // The footprint link is built with the external pipeline; its click table, when it exists, is read as (clicked_at).
        $clicks = Schema::hasTable('notification_footprint_clicks') ? DB::table('notification_footprint_clicks')->where('clicked_at', '>=', $monthStart)->count() : null;
        // Builder Q's per-type counts of the same table (message type only — nothing about the reader).
        $clicksByType = $clicks === null ? [] : Footprint::clicksByType($monthStart->toDateTimeString());
        uksort($clicksByType, static fn (string $a, string $b): int => [$clicksByType[$b], $a] <=> [$clicksByType[$a], $b]);

        return ApiResponse::success([
            'period' => $period,
            'messages_sent' => $sent,
            'messages_failed' => $failed,
            'failure_rate' => ($sent + $failed) > 0 ? round($failed / ($sent + $failed), 4) : null,
            'unsubscribed' => $unsub,
            'unsubscribe_rate' => $sent > 0 ? round($unsub / $sent, 4) : null,
            'footprint_clicks' => $clicks,
            'footprint_clicks_by_type' => $clicksByType,
            'per_workspace' => array_slice($rows, 0, 100),
        ]);
    }

    /** @return array<string, mixed> */
    private function texts(): array
    {
        $rows = DB::table('notification_platform_texts')->get()->mapWithKeys(static fn (object $r): array => [$r->key => is_string($r->value) ? json_decode($r->value, true) : $r->value]);
        $defaults = [
            'phrasing.dictionary' => (array) config('notifications_v2_texts.phrasing'),
            'disclaimer.default' => (array) config('notifications_v2_texts.disclaimer'),
            'footprint.invitation' => (array) config('notifications_v2_texts.footprint.invitation'),
        ];
        $out = [];
        foreach (array_merge(self::TEXT_KEYS, array_map(static fn (string $t): string => 'templates.'.$t, $this->types->keys())) as $key) {
            $out[$key] = ['value' => $rows->get($key, $defaults[$key] ?? new \stdClass), 'customised' => $rows->has($key)];
        }

        return $out;
    }

    private function forgetGates(): void
    {
        app()->forgetScopedInstances();
    }

    /** @return array<mixed> */
    private function clean(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $k => $v) {
            $out[$k] = is_array($v) ? $this->clean($v) : (is_string($v) ? Str::limit(strip_tags($v), 4000, '') : $v);
        }

        return $out;
    }
}
