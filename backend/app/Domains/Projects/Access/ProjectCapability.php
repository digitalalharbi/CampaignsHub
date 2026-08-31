<?php

declare(strict_types=1);

namespace App\Domains\Projects\Access;

/**
 * TEAM-PROJECT-RBAC-001 — what a person may do INSIDE one project.
 *
 * ## Why a second, narrower vocabulary
 *
 * The tenant permission catalogue answers «may this employee see analytics at all». It cannot answer
 * «may this employee see the phone numbers on THIS client's leads», because it has no project in it.
 * A media buyer who legitimately reads every campaign in the agency has no business reading a
 * client's callers, and the two questions were being answered by one word.
 *
 * `project_memberships` has carried `role` and `permissions` columns since the table was created and
 * **nothing has ever read either of them**. Every project member therefore had exactly whatever
 * their tenant role granted, on every project they could reach — which is a permission model that
 * exists in the schema, in the invitation form and in the documentation, and nowhere in the code.
 *
 * ## The rule that makes this security rather than decoration
 *
 * Nothing here hides a control. A menu item that is not drawn is a menu item; the refusal has to
 * happen where the data is, on the server, for every route and every API endpoint, and it has to
 * fail CLOSED — an unknown capability, an expired membership and a user who is not a member all
 * return the same answer, which is no.
 */
final class ProjectCapability
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const ANALYTICS_VIEW = 'analytics.view';

    public const CAMPAIGNS_VIEW = 'campaigns.view';

    public const CAMPAIGNS_MANAGE = 'campaigns.manage';

    public const LEADS_VIEW = 'leads.view';

    /**
     * The name, phone, email and message a person actually gave.
     *
     * Separate from `leads.view` on purpose, and the separation is the point of this whole class: a
     * media buyer optimising a lead-generation campaign needs the COUNT and the cost per lead, and
     * needs no part of the identity. Granting the two together is how a performance dashboard turns
     * into an unaudited copy of a client's contact list.
     */
    public const LEADS_PII_VIEW = 'leads.pii.view';

    public const LEADS_ASSIGN = 'leads.assign';

    public const LEADS_UPDATE = 'leads.update';

    /** Taking the list out of the product entirely — the one action nothing else can undo. */
    public const LEADS_EXPORT = 'leads.export';

    public const TASKS_VIEW = 'tasks.view';

    public const TASKS_MANAGE = 'tasks.manage';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_MANAGE = 'reports.manage';

    public const BUDGET_VIEW = 'budget.view';

    public const BUDGET_MANAGE = 'budget.manage';

    public const TEAM_MANAGE = 'team.manage';

    public const INTEGRATIONS_MANAGE = 'integrations.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    /**
     * Every capability this product recognises.
     *
     * A capability NOT on this list is refused rather than granted, wherever it is asked about. An
     * authorisation system whose unknown case is «allow» is not one.
     *
     * @var list<string>
     */
    public const ALL = [
        self::DASHBOARD_VIEW,
        self::ANALYTICS_VIEW,
        self::CAMPAIGNS_VIEW,
        self::CAMPAIGNS_MANAGE,
        self::LEADS_VIEW,
        self::LEADS_PII_VIEW,
        self::LEADS_ASSIGN,
        self::LEADS_UPDATE,
        self::LEADS_EXPORT,
        self::TASKS_VIEW,
        self::TASKS_MANAGE,
        self::REPORTS_VIEW,
        self::REPORTS_MANAGE,
        self::BUDGET_VIEW,
        self::BUDGET_MANAGE,
        self::TEAM_MANAGE,
        self::INTEGRATIONS_MANAGE,
        self::SETTINGS_MANAGE,
    ];

    public static function exists(string $capability): bool
    {
        return in_array($capability, self::ALL, true);
    }
}
