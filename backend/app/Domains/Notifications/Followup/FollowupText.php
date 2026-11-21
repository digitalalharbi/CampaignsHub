<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

/** NOTIF-V2-FOLLOWUP-001 — the words of a task, re-rendered in the reader's language from its kind and parameters. */
final class FollowupText
{
    /** @param array<string, mixed> $params */
    public static function reason(string $kind, array $params, string $locale): string
    {
        $key = $kind === 'renew_budget' && ! empty($params['over']) ? 'renew_budget_over' : $kind;
        $replace = array_filter($params, static fn ($v): bool => is_scalar($v));

        return (string) __('notifications_v2.followup.reasons.'.$key, $replace, $locale);
    }

    public static function kind(string $kind, string $locale): string
    {
        return (string) __('notifications_v2.followup.kinds.'.$kind, [], $locale);
    }

    public static function action(string $action, string $locale): string
    {
        return (string) __('notifications_v2.followup.history.'.$action, [], $locale);
    }

    /** The exact page the task's button opens, portal-relative. */
    public static function url(string $kind, ?string $projectId): string
    {
        $q = $projectId === null ? '' : '?project='.$projectId;

        return match ($kind) {
            'renew_budget', 'set_budget' => '/budgets'.$q,
            'relink_platform' => '/integrations'.$q,
            'review_held_message' => '/settings/workspace?tab=notifications&section=external',
            'decide_recommendation' => '/agents/approvals',
            default => '/followup',
        };
    }
}
