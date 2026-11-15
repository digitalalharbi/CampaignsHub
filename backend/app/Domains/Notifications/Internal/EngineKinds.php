<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal;

/**
 * NOTIF-V2-INTERNAL-001 — what each internal catalog event is, to the bell and the centre.
 *
 * The in-app sender stores the event key as the row's `kind`. This class gives every such key its filter group and its
 * page, and maps the legacy bell kinds (builder L) to the same events, so one name means one thing wherever it is
 * read: the centre's labels, the type filter, «mute this type», and the follow-up rules.
 *
 * Labels are NOT here: they come from the catalog's own strings (`notifications_v2.events.<key>.internal.title`), the
 * same words the push and the email carry, in the reader's language.
 */
final class EngineKinds
{
    /** event key => [group, page]. A page is portal-relative; `{p}` is `?project=<id>` or nothing. */
    private const EVENTS = [
        'budget.threshold_75' => ['budget', '/budgets{p}'],
        'budget.threshold_90' => ['budget', '/budgets{p}'],
        'budget.threshold_100' => ['budget', '/budgets{p}'],
        'budget.overrun' => ['budget', '/budgets{p}'],
        'budget.changed' => ['budget', '/budgets{p}'],
        'budget.renewed' => ['budget', '/budgets{p}'],
        'spend_limit.reached' => ['budget', '/budgets{p}'],
        'spend_limit.changed' => ['budget', '/budgets{p}'],
        'sync.failed' => ['sync', '/integrations{p}'],
        'connection.expiring' => ['sync', '/integrations{p}'],
        'connection.expired' => ['sync', '/integrations{p}'],
        'data.late' => ['sync', '/integrations{p}'],
        'automation.executed' => ['other', '/agents/approvals'],
        'recommendation.awaiting_approval' => ['other', '/agents/approvals'],
        'campaign.spend_without_results' => ['performance', '/alerts'],
        'campaign.stopped_spending' => ['performance', '/alerts'],
        'campaign.cost_per_result_up' => ['performance', '/alerts'],
        'campaign.return_down' => ['performance', '/alerts'],
        'external.held_for_review' => ['external', '/settings/workspace?tab=notifications&section=external'],
        'external.held_for_privacy' => ['external', '/settings/workspace?tab=notifications&section=external'],
        'external.failed' => ['external', '/settings/workspace?tab=notifications&section=external'],
        'external.recipient_unsubscribed' => ['external', '/settings/workspace?tab=notifications&section=external'],
        'external.recipient_reduced' => ['external', '/settings/workspace?tab=notifications&section=external'],
        'external.recipient_unconfirmed_7d' => ['external', '/settings/workspace?tab=notifications&section=external'],
        'project.created' => ['other', '/projects'],
        'project.archived' => ['other', '/projects'],
        'project.no_budget_for_period' => ['budget', '/budgets{p}'],
        'plan.limit_nearing' => ['other', '/settings/workspace?tab=notifications&section=internal'],
        'plan.entitlement_changed' => ['other', '/settings/workspace?tab=notifications&section=internal'],
        'plan.grace_ending' => ['other', '/settings/workspace?tab=notifications&section=internal'],
        'followup.morning_brief' => ['other', '/followup'],
        'report.rerouted' => ['external', '/settings/workspace?tab=notifications&section=external'],
    ];

    /** legacy bell kind => event key (the bell's older names for the same facts). */
    private const LEGACY = [
        'budget_75' => 'budget.threshold_75',
        'budget_90' => 'budget.threshold_90',
        'budget_100' => 'budget.threshold_100',
        'budget_over' => 'budget.overrun',
        'sync_failed' => 'sync.failed',
        'token_expiry' => 'connection.expiring',
        'no_results' => 'campaign.spend_without_results',
        'not_spending' => 'campaign.stopped_spending',
        'campaign_stopped' => 'campaign.stopped_spending',
        'cost_up' => 'campaign.cost_per_result_up',
        'roas_drop' => 'campaign.return_down',
    ];

    public static function has(?string $kind): bool
    {
        return $kind !== null && isset(self::EVENTS[$kind]);
    }

    public static function group(?string $kind): string
    {
        return self::has($kind) ? self::EVENTS[$kind][0] : 'other';
    }

    public static function path(?string $kind, ?string $projectId): ?string
    {
        if (! self::has($kind)) {
            return null;
        }

        return str_replace('{p}', $projectId === null ? '' : '?project='.$projectId, self::EVENTS[$kind][1]);
    }

    /** The reader's-language title of an event (the catalog's own words), or null when the key is not an event. */
    public static function label(?string $kind, bool $ar): ?string
    {
        if (! self::has($kind)) {
            return null;
        }

        $key = 'notifications_v2.events.'.str_replace('.', '_', (string) $kind).'.internal.title';
        $text = __($key, [], $ar ? 'ar' : 'en');

        return $text === $key ? null : (string) $text;
    }

    /** The catalog event a bell kind stands for, or null. An event key is its own event. */
    public static function eventFor(?string $kind): ?string
    {
        if ($kind === null) {
            return null;
        }

        return isset(self::EVENTS[$kind]) ? $kind : (self::LEGACY[$kind] ?? null);
    }

    /**
     * Every name the same fact goes by: a mute of one of them mutes all of them.
     *
     * @return list<string>
     */
    public static function aliases(string $kind): array
    {
        $event = self::eventFor($kind) ?? $kind;
        $legacy = array_keys(array_filter(self::LEGACY, static fn (string $e): bool => $e === $event));

        return array_values(array_unique([$kind, $event, ...$legacy]));
    }

    /** @return list<string> the event keys of a panel filter group */
    public static function kindsOf(string $group): array
    {
        return array_keys(array_filter(self::EVENTS, static fn (array $e): bool => $e[0] === $group));
    }

    /** @return list<string> */
    public static function events(): array
    {
        return array_keys(self::EVENTS);
    }
}
