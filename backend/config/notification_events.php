<?php

declare(strict_types=1);

/*
 * NOTIF-V2-ENGINE-001 — the ONE event catalog, defaults (Owner spec §3, §5, §7, §10). Platform admin overrides live in
 * `notification_event_catalog` and are merged over these by the registry. Format: docs/notifications-v2/CONTRACTS.md §3.
 *
 *   scope               internal | external | both
 *   priority            info | important | critical (internal only; external deliveries ignore it)
 *   default_roles       workspace type => role keys of that type's profile (config/notification_workspace_types.php)
 *   channels            allowed channels; external scope is email only
 *   entitlement         an EntitlementKey value the delivery requires
 *   creates_task        whether the follow-up assistant opens a task for it (§6)
 *   internal_only       sensitive operational event: never external, never to a profile without sees_sensitive_events
 *   preference_type     the existing MessageCatalogue type whose personal switch also governs it (reuse, §0 layer 3)
 *   required_capability a ProjectCapability value the internal recipient must hold on the project
 *   groupable           same-type events in the grouping window collapse into one delivery
 *
 * Titles and bodies: lang/{ar,en}/notifications_v2.php → events.<key with _>.<scope>.{title,body}.
 */

$ops = ['agency' => ['admin', 'project_manager'], 'business' => ['owner', 'marketing'], 'client_portal' => []];
$money = ['agency' => ['admin', 'project_manager', 'finance'], 'business' => ['owner', 'marketing', 'finance'], 'client_portal' => []];
$owner = ['agency' => ['admin'], 'business' => ['owner'], 'client_portal' => []];
// ENGINE-MAIL-VISUAL-001 — Owner 2026-11-28: a project spending with no budget is the workspace management's and finance's to fix
// (record the budget), never the project manager's.
$budgetSetup = ['agency' => ['admin', 'finance'], 'business' => ['owner', 'finance'], 'client_portal' => []];
$everyone = ['agency' => ['admin', 'project_manager', 'finance'], 'business' => ['owner', 'marketing', 'finance'], 'client_portal' => ['account_owner', 'team']];
$ext = ['agency' => ['finance', 'project_manager', 'admin', 'client', 'other'], 'business' => ['owner', 'marketing', 'finance', 'other'], 'client_portal' => ['account_owner', 'team']];
$quiet = ['in_app', 'email'];

$i = static fn (string $priority, array $roles, array $more = []): array => array_merge([
    'scope' => 'internal', 'priority' => $priority, 'default_roles' => $roles, 'channels' => ['in_app', 'push', 'email'],
    'entitlement' => 'notifications.internal',
], $more);

$e = static fn (array $more = []): array => array_merge([
    'scope' => 'external', 'priority' => 'info', 'default_roles' => $ext, 'channels' => ['email'],
    'entitlement' => 'notifications.external', 'groupable' => false,
], $more);

return [
    'grouping_window_minutes' => 10,

    'events' => [
        // ── §5 budgets and limits ──
        'budget.threshold_75' => $i('important', $money, ['preference_type' => 'project_budget', 'required_capability' => 'money.view', 'channels' => $quiet]),
        'budget.threshold_90' => $i('important', $money, ['preference_type' => 'project_budget', 'required_capability' => 'money.view']),
        'budget.threshold_100' => $i('critical', $money, ['preference_type' => 'project_budget', 'required_capability' => 'money.view', 'internal_only' => true, 'creates_task' => true]),
        'budget.overrun' => $i('critical', $money, ['preference_type' => 'project_budget', 'required_capability' => 'money.view', 'internal_only' => true, 'creates_task' => true]),
        'spend_limit.reached' => $i('critical', $money, ['preference_type' => 'internal_spend_limit', 'required_capability' => 'money.view', 'internal_only' => true]),
        'budget.changed' => $i('info', $money, ['preference_type' => 'project_budget', 'required_capability' => 'money.view', 'channels' => $quiet, 'internal_only' => true]),
        'spend_limit.changed' => $i('info', $money, ['preference_type' => 'internal_spend_limit', 'required_capability' => 'money.view', 'channels' => $quiet, 'internal_only' => true]),

        // ── §5 data and connections (sensitive, internal only) ──
        'sync.failed' => $i('important', $ops, ['preference_type' => 'sync_failed', 'internal_only' => true, 'creates_task' => true]),
        'connection.expiring' => $i('important', $ops, ['preference_type' => 'token_expiring', 'internal_only' => true, 'creates_task' => true]),
        'connection.expired' => $i('critical', $ops, ['preference_type' => 'token_expiring', 'internal_only' => true, 'creates_task' => true]),
        'data.late' => $i('info', $ops, ['preference_type' => 'stale_data', 'internal_only' => true, 'channels' => $quiet]),

        // ── §5 automation and recommendations ──
        'automation.executed' => $i('info', $ops, ['internal_only' => true, 'channels' => $quiet]),
        'recommendation.awaiting_approval' => $i('important', $ops, ['creates_task' => true]),

        // ── §5 campaign performance (per project rules) ──
        'campaign.spend_without_results' => $i('important', $ops, ['preference_type' => 'budget_risk', 'internal_only' => true]),
        'campaign.stopped_spending' => $i('important', $ops, ['preference_type' => 'budget_risk', 'internal_only' => true]),
        'campaign.cost_per_result_up' => $i('important', $ops, ['preference_type' => 'budget_risk', 'internal_only' => true, 'channels' => $quiet]),
        'campaign.return_down' => $i('important', $ops, ['preference_type' => 'budget_risk', 'internal_only' => true, 'channels' => $quiet]),

        // ── §5 external messages, as seen internally ──
        'external.held_for_review' => $i('important', $ops, ['creates_task' => true, 'groupable' => false, 'internal_only' => true]),
        'external.held_for_privacy' => $i('important', $ops, ['internal_only' => true]),
        'external.failed' => $i('important', $ops, ['internal_only' => true, 'channels' => $quiet]),
        'external.recipient_unsubscribed' => $i('info', $ops, ['internal_only' => true, 'channels' => $quiet]),
        'external.recipient_reduced' => $i('info', $ops, ['internal_only' => true, 'channels' => $quiet]),
        'external.recipient_unconfirmed_7d' => $i('info', $ops, ['internal_only' => true, 'channels' => $quiet]),
        // NOTIF-V2-EXTERNAL-001 — a held message nobody decided on within the review window was skipped.
        'external.review_skipped' => $i('info', $ops, ['internal_only' => true, 'channels' => $quiet, 'groupable' => false]),

        // ── CONTENT-INTAKE-001 — the content quality gate (internal only; the decision goes to the uploader alone) ──
        'content.submitted' => $i('info', $ops, ['channels' => ['in_app'], 'groupable' => true]),
        'content.decided' => $i('info', $everyone, ['channels' => ['in_app'], 'groupable' => false]),

        // ── §5 projects ──
        'project.created' => $i('info', $everyone, ['channels' => ['in_app']]),
        'project.archived' => $i('info', $everyone, ['channels' => ['in_app']]),
        'project.no_budget_for_period' => $i('important', $budgetSetup, ['required_capability' => 'money.view', 'creates_task' => true, 'internal_only' => true]),

        // ── §1/§5 plan ──
        'plan.limit_nearing' => $i('important', $owner, ['internal_only' => true, 'channels' => $quiet]),
        'plan.entitlement_changed' => $i('info', $owner, ['internal_only' => true, 'channels' => $quiet]),
        'plan.grace_ending' => $i('critical', $owner, ['internal_only' => true, 'channels' => $quiet, 'groupable' => false]),

        // ── §6 follow-up ──
        'followup.morning_brief' => $i('info', $everyone, ['entitlement' => 'followup.morning_brief', 'channels' => $quiet, 'groupable' => false]),

        // ── §10 external (service reports; never alerts) ──
        'digest.periodic' => $e(),
        'target.reached' => $e(),
        'performance.improved' => $e(),
        'record.reached' => $e(),
        'campaign.launched' => $e(['scope' => 'both', 'default_roles' => $ext, 'channels' => ['in_app', 'email']]),
        'budget.renewed' => $e(['scope' => 'both', 'channels' => ['in_app', 'email']]),
        'continuity.planning' => $e(),
        'digest.month_end' => $e(),
        'digest.month_start' => $e(),

        // ── §6 «أعد التوجيه بضغطة»: an internal report a person chose to send to an external recipient (same checks, review, log) ──
        'report.rerouted' => $e(['groupable' => false]),
    ],
];
