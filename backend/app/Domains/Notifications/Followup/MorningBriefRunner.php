<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\Followup\Models\FollowupBrief;
use App\Domains\Notifications\Followup\Models\FollowupUserSettings;
use App\Domains\Notifications\Internal\InternalEvents;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NOTIF-V2-FOLLOWUP-001 — sends each responsible person their morning brief at the time THEY set (default 08:00 in the
 * workspace timezone), once per workspace day, on the work days.
 *
 * - The plan key `followup.morning_brief` is checked here (a scheduled job refuses when it is off — data kept).
 * - The brief is stored as a snapshot (`followup_briefs`) and announced through the shared engine as `followup.morning_brief`,
 *   addressed to that person, in the channels they chose (the in-app centre; email optional), so quiet hours, the plan and the
 *   immutable log apply as for every other delivery.
 * - A client-portal member never receives one (operational content).
 */
final class MorningBriefRunner
{
    public function __construct(
        private readonly EntitlementGate $gate,
        private readonly WorkspaceSettingsRepository $settings,
        private readonly MorningBrief $brief,
        private readonly FollowupTasks $tasks,
        private readonly InternalEvents $events,
        private readonly TenantContext $tenants,
    ) {}

    /** @return int briefs created */
    public function run(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $made = 0;
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;
            if (! $this->gate->allows($tenantId, EntitlementKey::MorningBrief)) {
                continue;
            }
            $before = $this->tenants->tenantId();
            $this->tenants->setTenantId($tenantId);
            try {
                $made += $this->forTenant($tenantId, $now);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $this->tenants->setTenantId($before);
            }
        }

        return $made;
    }

    private function forTenant(string $tenantId, Carbon $now): int
    {
        $ws = $this->settings->for($tenantId);
        $local = $now->copy()->setTimezone($ws->timezone);
        if (! in_array((int) $local->dayOfWeek, $ws->workDays, true) || in_array($local->toDateString(), $ws->holidays, true)) {
            return 0;
        }

        $userIds = DB::table('memberships')->where('tenant_id', $tenantId)->where('status', 'active')->whereIn('portal', ['agency', 'app'])->orderBy('user_id')->pluck('user_id')->unique();
        $made = 0;
        foreach (User::query()->whereIn('id', $userIds)->orderBy('id')->get() as $user) {
            if (! $this->tasks->mayUse($user, $tenantId)) {
                continue;
            }
            $prefs = FollowupUserSettings::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->first();
            // RELEASE-INTEGRATION — until a person chooses, the workspace defaults on builder R's settings page apply
            // (`settings.followup.morning_brief`, `.morning_time`; defaults on / 08:00). A person's own row always wins.
            $wsFollow = (array) ($ws->settings['followup'] ?? []);
            $enabled = $prefs->brief_enabled ?? (bool) ($wsFollow['morning_brief'] ?? true);
            $wsTime = is_string($wsFollow['morning_time'] ?? null) && preg_match('/^\d{2}:\d{2}$/', $wsFollow['morning_time']) === 1 ? $wsFollow['morning_time'] : '08:00';
            $time = (string) ($prefs->brief_time ?? $wsTime);
            if (! $enabled || $local->format('H:i') < $time) {
                continue;
            }
            if (FollowupBrief::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->whereDate('brief_date', $local->toDateString())->exists()) {
                continue;
            }
            $this->make($user, $tenantId, $now, $ws->timezone, $local->toDateString(), (array) ($prefs->brief_channels ?? ['in_app']));
            $made++;
        }

        return $made;
    }

    /** @param list<string> $channels */
    private function make(User $user, string $tenantId, Carbon $now, string $timezone, string $date, array $channels): FollowupBrief
    {
        $locale = ($user->locale ?? 'ar') === 'en' ? 'en' : 'ar';
        $sections = $this->brief->build($user, $tenantId, $now, $timezone);
        $summary = $this->brief->summary($sections, $locale);

        $brief = FollowupBrief::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'user_id' => $user->getKey(), 'brief_date' => $date, 'sections' => $sections, 'summary' => mb_substr($summary, 0, 500),
        ]);
        $this->events->raise('followup.morning_brief', $tenantId, null, [
            'addressee_user_id' => (int) $user->getKey(), 'summary' => $summary, 'channels_only' => array_values(array_intersect($channels, ['in_app', 'email'])) ?: ['in_app'],
            'url' => '/followup?brief='.$date,
        ], null, 'brief', $user->getKey().':'.$date);

        return $brief;
    }
}
