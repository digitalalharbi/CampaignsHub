<?php

declare(strict_types=1);

namespace App\Domains\Projects\Access;

/**
 * TEAM-PROJECT-RBAC-001 — the presets, and what each one is FOR.
 *
 * A preset is a starting point, not a ceiling and not a cage: a membership may name extra
 * capabilities explicitly, and a project owner grants them one at a time in front of somebody. What
 * the preset does is make the common case correct by default, because the common case is what
 * actually ships — a system where every safe configuration must be assembled by hand ends up
 * configured once, generously, by whoever was in a hurry.
 *
 * ## The two defaults that matter most
 *
 * **A media buyer gets no lead identity.** They get the count, the cost per lead and everything
 * about the campaign that produced it. A person's name and phone number are not performance data,
 * and the buyer's job does not need them — so `leads.pii.view` is absent here rather than present
 * and unused.
 *
 * **A lead agent sees the leads assigned to them and no others.** The capability grants the SCREEN;
 * which rows appear on it is a separate question the reader answers, and the answer for this preset
 * is «yours». An agent who needs the whole pipeline is a supervisor, and there is a preset for that.
 *
 * ## Existing role names are kept
 *
 * The `project_memberships.role` column already holds `account_manager`, `media_buyer`, `analyst`,
 * `content`, `finance`, `client_admin`, `client_approver`, `client_viewer` and `viewer` in
 * production. Those are mapped rather than renamed: a migration that rewrote live rows to a new
 * vocabulary would be changing people's access as a side effect of a naming decision.
 */
final class ProjectRole
{
    public const OWNER = 'owner';

    public const MARKETING_MANAGER = 'marketing_manager';

    public const MEDIA_BUYER = 'media_buyer';

    public const SALES_MANAGER = 'sales_manager';

    public const LEAD_AGENT = 'lead_agent';

    public const MANAGEMENT_VIEWER = 'management_viewer';

    public const VIEWER = 'viewer';

    /**
     * The presets, each as the exact set it grants.
     *
     * @return array<string, list<string>>
     */
    public static function presets(): array
    {
        $read = [
            ProjectCapability::DASHBOARD_VIEW,
            ProjectCapability::ANALYTICS_VIEW,
            ProjectCapability::CAMPAIGNS_VIEW,
            ProjectCapability::REPORTS_VIEW,
        ];

        return [
            // Everything, because somebody has to be able to grant the rest.
            self::OWNER => ProjectCapability::ALL,

            self::MARKETING_MANAGER => [
                ...$read,
                ProjectCapability::CAMPAIGNS_MANAGE,
                ProjectCapability::LEADS_VIEW,
                ProjectCapability::LEADS_ASSIGN,
                ProjectCapability::TASKS_VIEW,
                ProjectCapability::TASKS_MANAGE,
                ProjectCapability::REPORTS_MANAGE,
                ProjectCapability::BUDGET_VIEW,
                ProjectCapability::BUDGET_MANAGE,
                ProjectCapability::TEAM_MANAGE,
            ],

            /*
             * No `leads.pii.view`. The buyer sees how many leads a campaign produced and what each
             * one cost; a person's name and phone number are not performance data.
             */
            self::MEDIA_BUYER => [
                ...$read,
                ProjectCapability::CAMPAIGNS_MANAGE,
                ProjectCapability::LEADS_VIEW,
                ProjectCapability::TASKS_VIEW,
                ProjectCapability::BUDGET_VIEW,
            ],

            self::SALES_MANAGER => [
                ProjectCapability::DASHBOARD_VIEW,
                ProjectCapability::REPORTS_VIEW,
                ProjectCapability::LEADS_VIEW,
                ProjectCapability::LEADS_PII_VIEW,
                ProjectCapability::LEADS_ASSIGN,
                ProjectCapability::LEADS_UPDATE,
                ProjectCapability::LEADS_EXPORT,
                ProjectCapability::TASKS_VIEW,
                ProjectCapability::TASKS_MANAGE,
            ],

            /*
             * The agent works one lead at a time. They may read the identity of a lead they are
             * responsible for and update it; they may not hand it to somebody else, and they may not
             * take the list out of the product.
             */
            self::LEAD_AGENT => [
                ProjectCapability::LEADS_VIEW,
                ProjectCapability::LEADS_PII_VIEW,
                ProjectCapability::LEADS_UPDATE,
                ProjectCapability::TASKS_VIEW,
            ],

            /*
             * Management reads results. `leads.view` without `leads.pii.view` is deliberate and is
             * the whole reason the two are separate capabilities: the executive question is «how
             * many, how fast, how much did each cost», and none of it needs a phone number.
             */
            self::MANAGEMENT_VIEWER => [
                ...$read,
                ProjectCapability::LEADS_VIEW,
                ProjectCapability::BUDGET_VIEW,
            ],

            self::VIEWER => $read,
        ];
    }

    /**
     * The preset a stored role name resolves to.
     *
     * Unknown names fall to `viewer` rather than to nothing: a membership row whose role was written
     * by an older release still belongs to a real person who can still read the project, and a
     * silent total refusal reads to them as an outage. It never falls the other way.
     */
    public static function preset(?string $role): array
    {
        $presets = self::presets();
        $name = (string) $role;

        if (isset($presets[$name])) {
            return $presets[$name];
        }

        /** The names already in the column, mapped to the nearest preset — never renamed in place. */
        $legacy = [
            'account_manager' => self::MARKETING_MANAGER,
            'analyst' => self::MANAGEMENT_VIEWER,
            'content' => self::VIEWER,
            'finance' => self::MANAGEMENT_VIEWER,
            'client_admin' => self::MANAGEMENT_VIEWER,
            'client_approver' => self::VIEWER,
            'client_viewer' => self::VIEWER,
        ];

        return $presets[$legacy[$name] ?? self::VIEWER];
    }
}
