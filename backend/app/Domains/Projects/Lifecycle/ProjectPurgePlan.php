<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

/**
 * PROJECT-LIFECYCLE-001 §4 (builder N2) — the table-by-table plan of a permanent delete.
 *
 * EVERY table with a `project_id` column is listed here under exactly one strategy. `ProjectPurgePlanCoverageTest`
 * reads the live schema and fails when a table carries `project_id` and is not in this plan — a new project-owned table
 * cannot be forgotten by the purge (or purged by accident: its strategy must be chosen).
 *
 * | strategy | meaning |
 * |---|---|
 * | PURGE | `DELETE … WHERE tenant_id = ? AND project_id = ?` (child rows hanging off them go by their FK cascade, listed in `CASCADES`) |
 * | DETACH | `UPDATE … SET project_id = NULL WHERE tenant_id = ? AND project_id = ?` — a client/tenant-level record that outlives the project (billing, requests, CRM). Only where NULL means «no project», never «every project» |
 * | KEEP_LOG | never touched: audit, transition and notice-delivery logs, and the rows a kept log hangs off (its FK would cascade) |
 * | NEVER | never touched: shared with other projects or owned by the ad platform side (a connection cascades into the ad-account inventory) |
 *
 * The `projects` row itself is kept as a tombstone (status `deleted`, soft-deleted, `purged_at`): `funding_notices` and
 * `app_notifications` reference it with ON DELETE CASCADE, and they are notice logs.
 */
final class ProjectPurgePlan
{
    public const PURGE = 'purge';

    public const DETACH = 'detach';

    public const KEEP_LOG = 'keep_log';

    public const NEVER = 'never';

    /**
     * Order matters only for readability — every child FK is CASCADE or SET NULL (checked by the coverage test's FK walk).
     * Children first, so a cascade never does work the plan did not name.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        // Measured data and the entities it hangs off.
        'creative_daily_metrics' => self::PURGE,
        'entity_daily_metrics' => self::PURGE,
        'daily_metrics' => self::PURGE,
        'measurement_daily_metrics' => self::PURGE,
        'measurement_dimension_rows' => self::PURGE,
        'audience_breakdown_rows' => self::PURGE,
        'external_ads' => self::PURGE,
        'external_ad_sets' => self::PURGE,
        'external_campaigns' => self::PURGE,
        'external_creatives' => self::PURGE,
        'creative_groups' => self::PURGE,
        'unified_campaigns' => self::PURGE,
        'external_entity_mappings' => self::PURGE,
        'campaign_annotations' => self::PURGE,
        'launch_plans' => self::PURGE,
        'platform_evidences' => self::PURGE,
        'spend_reconciliations' => self::PURGE,
        // Syncs of this project's links (the links themselves are this project's rows, not the account).
        'metric_sync_checkpoints' => self::PURGE,
        'metric_sync_runs' => self::PURGE,
        'integration_sync_runs' => self::PURGE,
        'project_integration_bindings' => self::PURGE,
        // Reports (shares, exports, recipients, versions, annotations cascade).
        'report_attention_decisions' => self::PURGE,
        'report_versions' => self::PURGE,
        'report_schedules' => self::PURGE,
        'report_scope_templates' => self::PURGE,
        'reports' => self::PURGE,
        // Operations configured for the project.
        'alert_events' => self::PURGE,
        'alert_rules' => self::PURGE,
        'automation_rule_actions' => self::PURGE,
        'automation_rules' => self::PURGE,
        'agent_routines' => self::PURGE,
        'agent_threads' => self::PURGE,
        'agent_autonomy_grants' => self::PURGE,
        'client_agent_rules' => self::PURGE,
        'action_proposals' => self::PURGE,
        'ai_provider_credentials' => self::PURGE,
        'tasks' => self::PURGE,
        'notification_recipients' => self::PURGE,
        'external_digest_recipient_projects' => self::PURGE,
        'delivery_packs' => self::PURGE,
        'content_approvals' => self::PURGE,
        'project_memberships' => self::PURGE,
        'project_attribution_setting_versions' => self::PURGE,
        'project_attribution_settings' => self::PURGE,
        // Budgets, limits and spend ledgers of the project.
        'spend_limits' => self::PURGE,
        'funding_spend_days' => self::PURGE,
        'funding_month_summaries' => self::PURGE,
        'funding_payments' => self::PURGE,
        'project_funding' => self::PURGE,
        // Store and tracking data collected for the project.
        'order_touchpoint_links' => self::PURGE,
        'marketing_touchpoints' => self::PURGE,
        'tracking_events' => self::PURGE,
        'commerce_abandoned_carts' => self::PURGE,
        'commerce_orders' => self::PURGE,
        'commerce_customers' => self::PURGE,
        'commerce_products' => self::PURGE,
        'short_links' => self::PURGE,
        'lead_form_submissions' => self::PURGE,
        'lead_forms' => self::PURGE,

        // Client/tenant-level records that outlive the project (NULL = «no project»).
        'invoices' => self::DETACH,
        'quotes' => self::DETACH,
        'external_requests' => self::DETACH,
        'request_conversions' => self::DETACH,
        'message_threads' => self::DETACH,
        'leads' => self::DETACH,

        // Logs — never deleted (rule 7), and the rows a kept log's FK cascades from.
        'project_status_transitions' => self::KEEP_LOG,
        'project_archive_effects' => self::KEEP_LOG,
        'app_notifications' => self::KEEP_LOG,          // → notification_deliveries (CASCADE)
        'funding_notices' => self::KEEP_LOG,
        'conversion_deliveries' => self::KEEP_LOG,
        'conversion_destinations' => self::KEEP_LOG,    // → conversion_deliveries (CASCADE); switched off by the soft delete

        // Shared / ad-platform side — never touched (a connection cascades into the ad-account inventory).
        'integration_credentials' => self::NEVER,
        'provider_connections' => self::NEVER,
    ];

    /**
     * FK-cascade children of PURGE tables, stated so the plan says everything a purge reaches. The coverage test fails if
     * a PURGE table gains a CASCADE child that is not listed here (and none of these may be a log).
     *
     * @var array<string, list<string>>
     */
    public const CASCADES = [
        'alert_events' => ['alert_event_decisions'],
        'alert_rules' => ['alert_events'],
        'commerce_orders' => ['commerce_order_items'],
        'external_ad_sets' => ['external_ads'],
        'external_campaigns' => ['external_ad_sets'],
        'external_creatives' => ['creative_daily_metrics'],
        'lead_forms' => ['lead_form_submissions', 'lead_form_versions'],
        'reports' => ['report_annotations', 'report_exports', 'report_recipients', 'report_shares', 'report_versions'],
        'short_links' => ['short_link_hops'],
        'spend_limits' => ['spend_limit_events'],
    ];

    /** Tables that are logs: never deleted, and no PURGE may cascade into them. */
    public const LOGS = [
        'audit_logs', 'project_status_transitions', 'client_status_transitions', 'project_archive_effects',
        'app_notifications', 'notification_deliveries', 'mail_deliveries', 'report_deliveries', 'funding_notices',
        'conversion_deliveries', 'report_share_access_logs', 'action_log',
    ];

    /** @return list<string> */
    public static function tablesWith(string $strategy): array
    {
        return array_keys(array_filter(self::TABLES, static fn (string $s): bool => $s === $strategy));
    }
}
