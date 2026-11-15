<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Status\AccountDayCoverage;
use App\Domains\Integrations\Status\LinkHealth;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LIFECYCLE-6 (Owner spec 2026-11-15 §6) — the data-arrival alert «بيانات أمس ما وصلت من المنصة», judged per
 * (project, ad account) by {@see AccountDayCoverage}, for live projects only.
 *
 * Used by the alert sweep ({@see AlertEvaluator}: what to raise, when to close) and by the ops command
 * `alerts:reevaluate-data-arrival` (which open alerts were false), so the two can never disagree.
 */
final class DataArrivalJudge
{
    /** The rule types whose «data did not arrive» verdict this class owns. */
    public const TYPES = ['sync_failure', 'sync_outage'];

    public function __construct(private readonly AccountDayCoverage $coverage) {}

    /**
     * `sync_failure` breaches: one per live (project, ad account) whose verdict is an alert.
     *
     * @param  bool  $tokensWatched  a refused link is the `token_expiry` card when the tenant watches tokens — never a second card
     * @return list<array<string,mixed>>
     */
    public function breaches(AlertRule $rule, Carbon $now, bool $tokensWatched): array
    {
        $bindings = $this->coverage->liveBindings((string) $rule->tenant_id, $rule->project_id !== null ? (string) $rule->project_id : null);
        $out = [];
        foreach ($this->coverage->judge($bindings, $now, AccountDayCoverage::settleHours((array) ($rule->threshold ?? []))) as $v) {
            if (! in_array($v['verdict'], AccountDayCoverage::ALERTING, true)) {
                continue;
            }
            if ($v['verdict'] === AccountDayCoverage::LINK_EXPIRED && $tokensWatched) {
                continue;
            }
            $out[] = $this->breach($v);
        }

        return $out;
    }

    /**
     * A project-level outage (`sync_outage`) keeps only the platforms whose live accounts have an alerting verdict.
     *
     * @param  list<array<string,mixed>>  $sources
     * @return array{missing: list<array<string,mixed>>, reading: ?array<string,mixed>}
     */
    public function projectSources(string $tenantId, string $projectId, array $sources, Carbon $now, ?int $settleHours = null): array
    {
        // Live bindings only (an archived/deleted project has none), every platform judged in one pass.
        $verdicts = $this->coverage->judge($this->coverage->liveBindings($tenantId, $projectId), $now, $settleHours);
        $missing = [];
        $reading = null;
        foreach ($sources as $source) {
            $provider = (string) ($source['provider'] ?? '');
            $bad = array_values(array_filter($verdicts, static fn (array $v): bool => $v['provider'] === $provider && in_array($v['verdict'], AccountDayCoverage::ALERTING, true)));
            if ($bad !== []) {
                $missing[] = $source;
                $reading ??= $bad[0];
            }
        }

        return ['missing' => $missing, 'reading' => $reading];
    }

    /** Whether {@see closeReason()} decides this event (a data-arrival event, or any link event of a project that is not live). */
    /** @param  bool|null  $projectLive  whether the event's project is live, when the caller already read it */
    public function handles(AlertEvent $event, ?bool $projectLive = null): bool
    {
        if (in_array((string) $event->type, self::TYPES, true)) {
            return true;
        }

        return $event->project_id !== null && in_array((string) $event->type, LinkHealth::TYPES, true)
            && ! ($projectLive ?? LiveProjects::isLive((string) $event->project_id));
    }

    /**
     * Why an open data-arrival event is no longer true, on proof — or null to keep it open.
     *
     * `project_not_live` (archived/deleted project) · `link_removed` (the account is no longer actively bound, or the
     * connection was removed) · `no_live_binding` (the connection feeds no live project) · `day_covered` (a successful
     * sync covered the required day) · `awaiting_first_sync` (nothing alerting; an account has simply never synced) ·
     * `superseded_by_account` (a legacy connection-level event whose accounts now carry their own open events).
     */
    public function closeReason(AlertEvent $event, Carbon $now, ?int $settleHours = null, ?bool $projectLive = null): ?string
    {
        $tenantId = (string) $event->tenant_id;
        if ($event->project_id !== null && ! ($projectLive ?? LiveProjects::isLive((string) $event->project_id))) {
            return 'project_not_live';
        }
        $settle = $settleHours ?? $this->settleFor($event);

        if ($event->entity_type === ExternalAccount::class && $event->entity_id !== null) {
            $bindings = $this->coverage->liveBindings($tenantId, $event->project_id !== null ? (string) $event->project_id : null, null, null, (string) $event->entity_id);

            return $bindings === [] ? 'link_removed' : self::quiet($this->coverage->judge($bindings, $now, $settle));
        }

        if ($event->entity_type === ProviderConnection::class && $event->entity_id !== null) {
            $status = DB::table('provider_connections')->where('id', $event->entity_id)->value('status');
            if ($status === null || in_array((string) $status, ['disconnected', 'disabled', 'revoked'], true)) {
                return 'link_removed';
            }
            $bindings = $this->coverage->liveBindings($tenantId, null, (string) $event->entity_id);
            if ($bindings === []) {
                return 'no_live_binding';
            }
            $verdicts = $this->coverage->judge($bindings, $now, $settle);
            $quiet = self::quiet($verdicts);
            if ($quiet !== null) {
                return $quiet;
            }
            // Its alerting accounts already speak for themselves, one event each: the connection-level copy is the old shape.
            $alerting = array_values(array_unique(array_map(static fn (array $v): string => $v['account_id'],
                array_filter($verdicts, static fn (array $v): bool => in_array($v['verdict'], AccountDayCoverage::ALERTING, true)))));
            $covered = AlertEvent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('type', $event->type)
                ->where('entity_type', ExternalAccount::class)->whereIn('entity_id', $alerting)->whereIn('status', ['open', 'snoozed'])->exists();

            return $covered ? 'superseded_by_account' : null;
        }

        if ($event->entity_type === Project::class && $event->entity_id !== null) {
            $providers = array_values(array_filter(array_map(static fn ($s): string => (string) (((array) $s)['provider'] ?? ''), (array) (((array) $event->context)['sources'] ?? []))));
            $bindings = [];
            foreach ($providers === [] ? [null] : $providers as $provider) {
                array_push($bindings, ...$this->coverage->liveBindings($tenantId, (string) $event->entity_id, null, $provider));
            }

            return $bindings === [] ? 'no_live_binding' : self::quiet($this->coverage->judge($bindings, $now, $settle));
        }

        return null;
    }

    /** @param  list<array<string,mixed>>  $verdicts */
    private static function quiet(array $verdicts): ?string
    {
        $kinds = array_unique(array_column($verdicts, 'verdict'));
        if (array_intersect($kinds, AccountDayCoverage::ALERTING) !== []) {
            return null;
        }

        return in_array(AccountDayCoverage::COVERED, $kinds, true) ? 'day_covered' : 'awaiting_first_sync';
    }

    private function settleFor(AlertEvent $event): int
    {
        $threshold = $event->rule_id !== null ? AlertRule::withoutGlobalScopes()->whereKey($event->rule_id)->value('threshold') : null;
        $threshold = is_string($threshold) ? json_decode($threshold, true) : $threshold;

        return AccountDayCoverage::settleHours(is_array($threshold) ? $threshold : null);
    }

    /**
     * @param  array<string,mixed>  $v  one verdict of {@see AccountDayCoverage::judge()}
     * @return array<string,mixed>
     */
    private function breach(array $v): array
    {
        $account = trim($v['account_name'].($v['account_external_id'] !== '' ? ' ('.$v['account_external_id'].')' : ''));
        $context = [
            'provider' => $v['provider'],
            'connection_id' => $v['connection_id'],
            'link_state' => 'not_synced',
            'coverage_rule' => 'account_day',
            'verdict' => $v['verdict'],
            'required_day' => $v['required_day'],
            'last_day' => $v['last_day'],
            'missing_days' => $v['missing_days'],
            'project_name' => $v['project_name'],
            'account_name' => $v['account_name'],
            'account_external_id' => $v['account_external_id'],
            'timezone' => $v['timezone'],
            'settle_hours' => $v['settle_hours'],
            'last_success' => $v['last_success'],
            'last_failed_at' => $v['last_failed_at'],
            'reason_ar' => $v['reason_ar'],
            'reason_en' => $v['reason_en'],
            'title_ar' => LinkHealth::text('not_synced', true),
            'message_ar' => trim($v['project_name'].' · '.$account.' — '.$v['reason_ar']),
        ];
        $breach = [
            'entity_type' => ExternalAccount::class,
            'entity_id' => $v['account_id'],
            'project_id' => $v['project_id'],
            'title' => LinkHealth::text('not_synced', false),
            'message' => trim($v['project_name'].' · '.$account.' — '.$v['reason_en']),
            'context' => $context,
        ];
        if ($v['verdict'] === AccountDayCoverage::NO_COVERAGE && ($v['missing_days'] ?? 0) >= LinkHealth::ESCALATE_AFTER_DAYS) {
            $breach['severity'] = 'critical';
        }

        return $breach;
    }
}
