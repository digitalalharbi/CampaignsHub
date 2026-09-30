import {
  Link2,
  BarChart3, BellRing, Building2, CreditCard, FolderKanban, FolderOpen, Images, Inbox,
  Layers, LayoutDashboard, ListChecks, Megaphone, MessageSquare, Plug, Receipt, Settings, TrendingUp, Users,
} from 'lucide-react'
import type { NavGroup } from './SidebarNav'

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
export const agencyNavGroups: readonly NavGroup[] = [
  {
    key: 'overview',
    ar: 'الرئيسية', en: 'Home', icon: LayoutDashboard,
    leaves: [{ to: '/agency/dashboard', ar: 'الرئيسية', en: 'Home', icon: LayoutDashboard }],
  },
  {
    key: 'clients',
    ar: 'العملاء', en: 'Clients', icon: Building2,
    leaves: [{ to: '/agency/clients', ar: 'العملاء', en: 'Clients', icon: Building2 }],
  },
  {
    /*
     * NAV-IA-001 — ONE projects category, and its children are short.
     *
     * «المشاريع» and «جميع المشاريع» sat side by side as siblings. Two entries whose labels differ
     * by one word read as two versions of the same place, and the longer one reads as «the same
     * thing, but more» — so a reader chooses by guessing. They are not two versions of anything:
     * one is the operational directory of projects, the other is the agency's own portfolio scope.
     *
     * A parent that is a GROUP and not a destination says that: «المشاريع» names the subject, and
     * «القائمة» and «الملخص» name the two ways of looking at it. Short, because a sidebar label is
     * scanned and not read — «نظرة عامة على المشاريع» under a heading that already says المشاريع is
     * the same word twice.
     *
     * PORTFOLIO-SCOPE-001 §36 is unchanged by the rename: the portfolio scope is still ENTERED by
     * its own address, and the page itself still says «جميع المشاريع» where the figures are. What
     * moved is the label on the rail, not the semantics of the scope.
     */
    key: 'projects',
    ar: 'المشاريع', en: 'Projects', icon: FolderKanban,
    leaves: [
      { to: '/agency/projects', ar: 'القائمة', en: 'List', icon: ListChecks },
      { to: '/agency/portfolio', ar: 'الملخص', en: 'Overview', icon: Layers },
    ],
  },
  {
    key: 'campaigns',
    /*
     * What is actually running, and the creative in it. Content sat under «Work» beside requests,
     * which put a design library next to an inbox.
     *
     * NAV-IA-001 — the heading names BOTH, so its first child stops repeating it. «Campaigns →
     * Campaigns, Content» reads as a category with a stray extra item; «Campaigns & content» reads
     * as what it is, and the two children are then the two halves rather than the subject and an
     * afterthought.
     */
    ar: 'الحملات والمحتوى', en: 'Campaigns & content', icon: Megaphone,
    leaves: [
      { to: '/agency/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone },
      { to: '/agency/content', ar: 'المحتويات', en: 'Content', icon: Images },
    ],
  },
  {
    key: 'inbox',
    // The queue: everything waiting on a person. Requests come in from clients, tasks are the
    // agency's own, conversations are the thread with the client, alerts are the system asking for
    // attention. They belong together because they get answered in one sitting.
    ar: 'المهام والطلبات', en: 'Tasks & requests', icon: Inbox,
    leaves: [
      { to: '/agency/requests', ar: 'الطلبات', en: 'Requests', icon: Inbox },
      { to: '/agency/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks },
      { to: '/agency/short-links', ar: 'اختصار الروابط', en: 'Short Links', icon: Link2 },
      { to: '/agency/messages', ar: 'المحادثات', en: 'Conversations', icon: MessageSquare },
      { to: '/agency/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing },
    ],
  },
  {
    key: 'deliverables',
    // What the client receives. Reports was the fourth item inside «Operations»; it is most of what
    // an agency hands over, and it now has a heading that says so.
    ar: 'التقارير والملفات', en: 'Reports & files', icon: BarChart3,
    leaves: [
      /*
       * Analytics leads, because it is what a report is made OF. The agency portal has never had it:
       * the API accepted agency operators on every metrics route, and only the link and the URL were
       * missing, so the reader running media for five clients had no page to open.
       */
      { to: '/agency/analytics', ar: 'التحليلات', en: 'Analytics', icon: TrendingUp },
      { to: '/agency/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3, cap: 'reports.view' },
      { to: '/agency/files', ar: 'الملفات', en: 'Files', icon: FolderOpen },
    ],
  },
  {
    key: 'finance',
    // Two different pots, and confusing them is expensive: Client invoicing is what the agency's
    // CLIENTS pay it, Agency subscription is what the agency pays CampaignsHub. The advertiser
    // portal has only the second, because an advertiser has nobody to invoice.
    ar: 'المالية', en: 'Finance', icon: Receipt,
    leaves: [
      { to: '/agency/billing', ar: 'فواتير العملاء', en: 'Client invoicing', icon: Receipt },
      { to: '/agency/subscriptions', ar: 'اشتراك الوكالة', en: 'Agency subscription', icon: CreditCard },
    ],
  },
  {
    key: 'settings',
    /*
     * Setup, done rarely — so it is one heading at the bottom rather than two top-level entries.
     *
     * «الفريق والنطاقات / Team & scopes» is now «الفريق والصلاحيات / Team & permissions». A «scope»
     * is what the code calls the restriction; a permission is what the person granting it thinks
     * they are granting. The page and the mechanism are unchanged.
     */
    ar: 'الإعدادات', en: 'Settings', icon: Settings,
    leaves: [
      /*
       * CONNECT-001 — connecting the agency's ad accounts.
       *
       * It lives under Settings rather than beside Campaigns because it is done once per account and
       * then not thought about; what an operator opens daily is the campaigns those accounts feed.
       * The page itself was reachable only under `/app` until now, so an agency operator had nowhere
       * to press connect at all — the API had always allowed them.
       */
      { to: '/agency/integrations', ar: 'ربط المنصات', en: 'Platform connections', icon: Plug, cap: 'integrations.manage' },
      { to: '/agency/team', ar: 'الفريق والصلاحيات', en: 'Team & permissions', icon: Users, cap: 'team.manage' },
      { to: '/agency/settings', ar: 'إعدادات الوكالة', en: 'Agency settings', icon: Settings },
    ],
  },
]

export const agencyNavLeafPaths: readonly string[] = agencyNavGroups.flatMap((g) => g.leaves.map((l) => l.to))
