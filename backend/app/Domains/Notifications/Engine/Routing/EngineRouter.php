<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Routing;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\QuietHoursPolicy;
use App\Domains\Notifications\Engine\Contracts\Router;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Data\EventDefinition;
use App\Domains\Notifications\Engine\Data\Recipient;
use App\Domains\Notifications\Engine\Data\RoutingDecision;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettings;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\NotificationChoices;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One intent → the (recipient, channel) decisions (spec §3). Every check is made at route time with the CURRENT
 * memberships and settings; the sender re-checks the plan before it sends.
 *
 * Internal, per active member of the workspace:
 *   1. a profile that does not see sensitive events (client portal) never gets an internal-only event;
 *   2. project reach — the existing resolver (`DigestScope::projectIdsFor`: membership ceiling, «all projects», followed
 *      projects); an event with no project goes to the profile's responsible role only;
 *   3. role routing — the workspace's override for the event, else the catalog default for the workspace type;
 *   4. the event's required capability on the project (`ProjectAbilities`, e.g. `money.view`);
 *   5. personal preferences — the existing per-type switch (`NotificationChoices`) for in-app / email, push read as in-app
 *      here (builder L's five push switches stay in `PushTypes`);
 *   6. plan entitlement — the event's key and the channel's key (push / internal) → `blocked_by_plan`;
 *   7. quiet hours / holidays → `notBefore` (critical bypasses).
 *
 * External: active subscriptions whose recipient is active, naming the event and the (non-archived) project → plan
 * entitlement → quiet hours / work days (no bypass). Pending, paused and cancelled recipients get nothing.
 */
final class EngineRouter implements Router
{
    private const INTERNAL_PORTALS = ['agency', 'app', 'influencers', 'portal'];

    public function __construct(
        private readonly EventRegistry $events,
        private readonly WorkspaceSettingsRepository $settings,
        private readonly WorkspaceTypes $types,
        private readonly EntitlementGate $gate,
        private readonly QuietHoursPolicy $quiet,
        private readonly DigestScope $scope,
        private readonly NotificationChoices $choices,
        private readonly ProjectAbilities $abilities,
        private readonly TenantContext $tenants,
    ) {}

    public function route(DeliveryIntent $intent): array
    {
        $event = $this->events->get($intent->eventKey);
        if ($event === null || ! $event->enabled) {
            return [];
        }

        $before = $this->tenants->tenantId();
        $this->tenants->setTenantId($intent->tenantId);
        try {
            $ws = $this->settings->for($intent->tenantId);
            if (($ws->routeFor($event->key)['enabled'] ?? true) === false) {
                return [];
            }

            $decisions = [];
            if ($event->scope->includes(Scope::Internal)) {
                $decisions = $this->internal($intent, $event, $ws);
            }
            if ($event->scope->includes(Scope::External) && ! $event->internalOnly) {
                $decisions = array_merge($decisions, $this->external($intent, $event, $ws));
            }

            return $decisions;
        } finally {
            $this->tenants->setTenantId($before);
        }
    }

    /** @return list<RoutingDecision> */
    private function internal(DeliveryIntent $intent, EventDefinition $event, WorkspaceSettings $ws): array
    {
        $route = $ws->routeFor($event->key);
        $channels = $this->channels($event, Scope::Internal, $route['channels'] ?? null);
        // NOTIF-V2-FOLLOWUP-001 — a person's own choice of channels for their morning brief narrows, never widens, what the workspace allows.
        if (isset($intent->payload['channels_only']) && is_array($intent->payload['channels_only'])) {
            $channels = array_values(array_filter($channels, static fn (Channel $c): bool => in_array($c->value, $intent->payload['channels_only'], true)));
        }
        $eventAllowed = $this->gate->allows($intent->tenantId, $event->entitlement ?? EntitlementKey::Internal)
            && $this->gate->allows($intent->tenantId, EntitlementKey::Internal);
        $addressee = isset($intent->payload['addressee_user_id']) ? (int) $intent->payload['addressee_user_id'] : null;

        $members = DB::table('memberships')->where('tenant_id', $intent->tenantId)->where('status', 'active')
            ->whereIn('portal', self::INTERNAL_PORTALS)->when($addressee !== null, fn ($q) => $q->where('user_id', $addressee))
            ->orderBy('user_id')->pluck('user_id')->unique();

        $out = [];
        foreach (User::query()->whereIn('id', $members)->orderBy('id')->get() as $user) {
            $userId = (int) $user->getKey();
            $profile = $this->types->profileFor($intent->tenantId, $userId);

            if ($event->internalOnly && ! $profile->seesSensitiveEvents) {
                continue;
            }
            if ($intent->projectId !== null && ! in_array($intent->projectId, $this->scope->projectIdsFor($user, $intent->tenantId), true)) {
                continue;
            }

            $role = $profile->roleForMember($this->roleSlug($intent->tenantId, $userId));
            $roles = $intent->projectId === null && $addressee === null
                ? [$profile->responsibleRole]
                : ($route['roles'] ?? $event->rolesFor($profile->key));
            if ($addressee === null && ! in_array($role, $roles, true)) {
                continue;
            }
            if ($event->requiredCapability !== null && $intent->projectId !== null
                && ! $this->abilities->allows($user, $intent->projectId, $event->requiredCapability)) {
                continue;
            }

            $recipient = Recipient::user($userId, $user->email, ($user->locale ?? 'ar') === 'en' ? 'en' : 'ar', $role);

            foreach ($channels as $channel) {
                if ($channel === Channel::Email && trim((string) $user->email) === '') {
                    continue;
                }
                if ($event->preferenceType !== null
                    && ! $this->choices->wants($userId, $intent->tenantId, $event->preferenceType, $channel === Channel::Email ? 'email' : 'in_app')) {
                    continue;
                }

                $out[] = $this->decide($intent, $recipient, Scope::Internal, $event, $channel,
                    $eventAllowed && ($channel !== Channel::Push || $this->gate->allows($intent->tenantId, EntitlementKey::Push)));
            }
        }

        return $out;
    }

    /** @return list<RoutingDecision> */
    private function external(DeliveryIntent $intent, EventDefinition $event, WorkspaceSettings $ws): array
    {
        if ($intent->projectId === null) {
            return [];
        }
        $archived = DB::table('projects')->where('id', $intent->projectId)->where('tenant_id', $intent->tenantId)
            ->where(fn ($q) => $q->where('status', 'archived')->orWhereNotNull('deleted_at'))->exists();
        if ($archived) {
            return [];
        }

        $rows = DB::table('notification_subscriptions as s')
            ->join('notification_external_recipients as r', 'r.id', '=', 's.recipient_id')
            ->join('notification_subscription_projects as p', 'p.subscription_id', '=', 's.id')
            ->where('s.tenant_id', $intent->tenantId)->where('r.tenant_id', $intent->tenantId)->where('p.tenant_id', $intent->tenantId)
            ->where('s.status', 'active')->where('r.status', 'active')->where('p.project_id', $intent->projectId)
            ->when($intent->subscriptionId !== null, fn ($q) => $q->where('s.id', $intent->subscriptionId))
            ->get(['s.id as subscription_id', 's.event_keys', 'r.id as recipient_id', 'r.email', 'r.language', 'r.role_key']);

        $allowed = $this->gate->allows($intent->tenantId, $event->entitlement ?? EntitlementKey::External)
            && $this->gate->allows($intent->tenantId, EntitlementKey::External);
        $profile = $this->types->profileFor($intent->tenantId);

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $keys = $row->event_keys === null ? [] : (array) json_decode((string) $row->event_keys, true);
            if ($intent->subscriptionId === null && ! in_array($event->key, $keys, true)) {
                continue;
            }
            // One message per recipient for one event, even when two of their subscriptions name it.
            if (isset($seen[$row->recipient_id])) {
                continue;
            }
            $seen[$row->recipient_id] = true;
            $role = $profile->hasRole((string) $row->role_key) ? (string) $row->role_key : 'other';
            $recipient = Recipient::external((string) $row->recipient_id, (string) $row->email, (string) $row->language === 'en' ? 'en' : 'ar', $role);

            $out[] = $this->decide($intent, $recipient, Scope::External, $event, Channel::Email, $allowed, (string) $row->subscription_id);
        }

        return $out;
    }

    private function decide(DeliveryIntent $intent, Recipient $recipient, Scope $scope, EventDefinition $event, Channel $channel, bool $allowed, ?string $subscriptionId = null): RoutingDecision
    {
        if (! $allowed) {
            return new RoutingDecision($recipient, $scope, $channel, $event->priority, DeliveryStatus::BlockedByPlan, 'plan:'.($event->entitlement->value ?? $scope->value), subscriptionId: $subscriptionId);
        }

        $until = $this->quiet->deferUntil($intent->tenantId, $recipient, $scope, $event->priority, $channel, $intent->at());

        return new RoutingDecision($recipient, $scope, $channel, $event->priority, notBefore: $until, deferredReason: $until === null ? null : 'quiet_hours', subscriptionId: $subscriptionId);
    }

    /**
     * @param  list<string>|null  $override
     * @return list<Channel>
     */
    private function channels(EventDefinition $event, Scope $scope, ?array $override): array
    {
        $allowed = array_values(array_filter($event->channels, static fn (Channel $c): bool => in_array($c, Channel::forScope($scope), true)));
        if ($override === null) {
            return $allowed;
        }

        return array_values(array_filter($allowed, static fn (Channel $c): bool => in_array($c->value, $override, true)));
    }

    private function roleSlug(string $tenantId, int $userId): ?string
    {
        $slug = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $userId)->where('roles.tenant_id', $tenantId)->orderBy('roles.slug')->value('roles.slug');

        return $slug === null ? null : (string) $slug;
    }
}
