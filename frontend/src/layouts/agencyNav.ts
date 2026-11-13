import { BarChart3, BellRing, Bot, Building2, ClipboardList, CreditCard, FolderOpen, Images, Inbox, Layers, LayoutDashboard, Lightbulb, Link2, ListChecks, Megaphone, MessageSquare, Plug, Receipt, RectangleHorizontal, Rocket, Rows3, Send, Settings, ShieldCheck, TrendingUp, Users, Wallet, Workflow } from 'lucide-react'
import type { NavGroup, NavLeaf } from './SidebarNav'

/**
 * The agency portal's navigation (`/agency`), grouped by what somebody came to DO.
 *
 * An agency's question is «how are my clients doing?», not «how is my campaign doing?». Clients is
 * therefore the axis everything else hangs off, and Team & permissions — who may see which client —
 * is a section the advertiser portal has no equivalent of.
 *
 * ## What changed, and why (SIMPLIFY-002)
 *
 * The rail was seven groups over fifteen links, and the GROUPING was the problem rather than the
 * count. Two groups carried almost all of it under names that describe nothing a person came to do:
 *
 * - **«العمل / Work»** held requests, projects, campaigns and content. Every one of those is work.
 * - **«التشغيل / Operations»** held tasks, conversations, files, reports AND alerts — five unrelated
 *   things in a bag whose label is an internal category. Reports, which is most of what an agency
 *   hands its clients, was the fourth item inside it.
 *
 * Somebody looking for last month's report had to know it lived under «Operations». That is a menu
 * organised around the system rather than around its reader.
 *
 * The groups are now named for the job: the clients and their work, the campaigns running, the queue
 * of things waiting on a person, what gets handed over, the money, and the setup. Nothing moved out
 * of reach — **all fifteen destinations are still here and every path is unchanged**, so bookmarks
 * and deep links keep working. What changed is which heading they sit under.
 *
 * Two levels, never three. A group of one renders as a plain link (see `SidebarNav`), so «الرئيسية»
 * is a single entry rather than a disclosure triangle over one item.
 *
 * ## Three leaves state a project capability — TEAM-PROJECT-RBAC-001
 *
 * Reports, platform connections and the team screen are the three whose routes refuse somebody
 * without the matching capability in the project they are looking at. A rail that offers them anyway
 * sends a media buyer to a 403, which reads as a broken product rather than as a boundary.
 *
 * The other leaves state none, and that is a decision rather than an omission: every reader who can
 * reach a project can read its dashboard, its campaigns and its content, so a capability on those
 * would be a filter that never filters. A leaf gains one when its route gains one.
 *
 * `agencyNavLeafPaths` is exported so a test can assert every destination survived the regrouping.
 */
/*
 * NAV-FIVE-AREAS-001 — five areas, named for the five jobs an operator does in a day.
 *
 *   Command Center      — what needs a person now: home, requests, tasks, conversations, alerts.
 *   Campaign management — the things being run: projects, campaigns, content, links, spend limits.
 *   AI agents           — the agents, their proposals waiting for approval, and the recommendations.
 *   Analytics & reports — how it is going and what the client receives.
 *   Business management — clients, money, connections, team and settings.
 *
 * REGROUPED, not redesigned: every destination, address, permission gate (`cap`) and label of the
 * previous rail is here (the navGrouping guard pins the set), so no page, link or bookmark moved.
 * NAV-IA-001 still holds inside the second area — the projects directory and the portfolio overview
 * sit together and are named so neither reads as a version of the other.
 */
/*
 * NAV-TAXONOMY-001 — the same four areas as the advertiser rail plus the agency's own «العملاء», short familiar words,
 * campaign work first in the approved order. Regrouped and renamed, never removed (navGrouping.test.ts): the clients,
 * requests, conversations and invoices stay together because they are one job — serving a client.
 */
/*
 * NAV-HOME-PLANS-001 (Owner master, task 12, 2026-11-13) — the approved structure, two levels:
 *
 *   الرئيسية (one link) · العملاء · الحملات · النتائج · المالية · العمليات, and «الإعدادات» in the account menu.
 *
 * REGROUPED and renamed, never removed: every address and permission gate (`cap`) is unchanged, and the two
 * addresses that left the rail still answer — `/agency/launch` is opened by «حملة جديدة» on the campaigns page and
 * `/agency/portfolio` («ملخص المشاريع») by a link on the home page. They are listed in `agencyFoldedPaths` so the
 * navGrouping guard names them instead of losing them. «المشاريع» stays inside the client (#1022).
 *
 * Three levels in the Owner's outline («النتائج › التحليلات › الأداء») are drawn as two: the group, and the page.
 * «الأداء / المنصات / الموقع / المتاجر» are the tabs of the analytics page, not rail entries.
 */
export const agencyNavGroups: readonly NavGroup[] = [
  {
    key: 'home',
    ar: 'الرئيسية', en: 'Home', icon: LayoutDashboard,
    leaves: [{ to: '/agency/dashboard', ar: 'الرئيسية', en: 'Home', icon: LayoutDashboard }],
  },
  {
    key: 'clients',
    ar: 'العملاء', en: 'Clients', icon: Building2,
    leaves: [
      { to: '/agency/clients', ar: 'العملاء', en: 'Clients', icon: Building2 },
      { to: '/agency/requests', ar: 'الطلبات', en: 'Requests', icon: Inbox },
      { to: '/agency/messages', ar: 'المحادثات', en: 'Conversations', icon: MessageSquare },
    ],
  },
  {
    key: 'campaigns',
    ar: 'الحملات', en: 'Campaigns', icon: Megaphone,
    leaves: [
      { to: '/agency/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone },
      { to: '/agency/ad-groups', ar: 'المجموعات الإعلانية', en: 'Ad groups', icon: Rows3 },
      { to: '/agency/ads', ar: 'الإعلانات', en: 'Ads', icon: RectangleHorizontal },
      { to: '/agency/content', ar: 'المحتويات', en: 'Content', icon: Images },
      { to: '/agency/files', ar: 'الملفات', en: 'Files', icon: FolderOpen },
    ],
  },
  {
    key: 'results',
    ar: 'النتائج', en: 'Results', icon: TrendingUp,
    leaves: [
      { to: '/agency/analytics', ar: 'التحليلات', en: 'Analytics', icon: TrendingUp },
      { to: '/agency/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3, cap: 'reports.view' },
      { to: '/agency/delivery', ar: 'الجدولة', en: 'Scheduling', icon: Send, cap: 'reports.view' },
      { to: '/agency/forms', ar: 'النماذج', en: 'Forms', icon: ClipboardList },
      { to: '/agency/short-links', ar: 'روابط التتبع', en: 'Tracking links', icon: Link2 },
    ],
  },
  {
    key: 'finance',
    ar: 'المالية', en: 'Finance', icon: Wallet,
    leaves: [
      { to: '/agency/spend-limits', ar: 'حدود الإنفاق', en: 'Spend limits', icon: Wallet },
      { to: '/agency/billing', ar: 'الفواتير', en: 'Invoices', icon: Receipt },
    ],
  },
  {
    key: 'operations',
    ar: 'العمليات', en: 'Operations', icon: ListChecks,
    leaves: [
      { to: '/agency/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks },
      { to: '/agency/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing },
      { to: '/agency/recommendations', ar: 'التوصيات', en: 'Recommendations', icon: Lightbulb },
      { to: '/agency/agents/approvals', ar: 'الموافقات', en: 'Approvals', icon: ShieldCheck },
      { to: '/agency/automation', ar: 'الأتمتة', en: 'Automation', icon: Workflow },
      { to: '/agency/agents', ar: 'الوكلاء', en: 'Agents', icon: Bot, end: true },
    ],
  },
]

/** «الإعدادات» — opened from the account menu (and listed in the phone's More sheet), never the rail. */
export const agencySettingsGroup: NavGroup = {
  key: 'settings',
  ar: 'الإعدادات', en: 'Settings', icon: Settings,
  leaves: [
    { to: '/agency/integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug, cap: 'integrations.manage' },
    { to: '/agency/team', ar: 'الفريق', en: 'Team', icon: Users, cap: 'team.manage' },
    { to: '/agency/subscriptions', ar: 'الاشتراك', en: 'Subscription', icon: CreditCard },
    { to: '/agency/settings', ar: 'الإعدادات', en: 'Settings', icon: Settings },
  ],
}

/** Addresses that left the rail on purpose and are opened from a page instead. Each one still answers. */
export const agencyFoldedLeaves: readonly NavLeaf[] = [
  { to: '/agency/launch', ar: 'حملة جديدة', en: 'New campaign', icon: Rocket },
  { to: '/agency/portfolio', ar: 'ملخص المشاريع', en: 'Projects summary', icon: Layers },
  { to: '/agency/projects', ar: 'المشاريع', en: 'Projects', icon: Building2 },
]
export const agencyFoldedPaths: readonly string[] = agencyFoldedLeaves.map((l) => l.to)

/** Everything the rail and the account menu can reach, for the phone's More sheet and the tab title. */
export const agencyAllGroups: readonly NavGroup[] = [
  ...agencyNavGroups,
  agencySettingsGroup,
  { key: 'folded', ar: 'أخرى', en: 'Other', icon: Layers, leaves: [...agencyFoldedLeaves] },
]

export const agencyNavLeafPaths: readonly string[] = [...agencyNavGroups, agencySettingsGroup].flatMap((g) => g.leaves.map((l) => l.to))
