import {
  BarChart3, BellRing, Building2, CreditCard, FolderKanban, FolderOpen, Images, Inbox,
  LayoutDashboard, ListChecks, Megaphone, MessageSquare, Receipt, Users,
} from 'lucide-react'
import type { NavGroup } from './SidebarNav'

/**
 * The agency portal's navigation (`/agency`), grouped.
 *
 * Shares the SHAPE of the advertiser rail and almost none of its content, which is the point: an
 * agency's question is "how are my clients doing?", not "how is my campaign doing?". So Clients is
 * its own top-level entry rather than one item inside Work, and Team & scopes — who may see which
 * client — is a section the advertiser portal has no equivalent of.
 *
 * What is absent is equally deliberate. No Integrations: connecting an ad account is done inside the
 * client or project it belongs to, and an agency-wide integrations screen would invite connecting an
 * account to nothing in particular. No Subscription: the agency's own plan is billing, and its
 * clients' money is Finance.
 *
 * The same twelve sections the flat rail had, in the same portal, none moved elsewhere.
 */
export const agencyNavGroups: readonly NavGroup[] = [
  {
    key: 'overview',
    ar: 'لوحة الوكالة', en: 'Agency overview', icon: LayoutDashboard,
    leaves: [{ to: '/agency/dashboard', ar: 'لوحة الوكالة', en: 'Agency overview', icon: LayoutDashboard }],
  },
  {
    key: 'clients',
    // Top-level, not nested: for an agency this is the axis everything else hangs off.
    ar: 'العملاء', en: 'Clients', icon: Building2,
    leaves: [{ to: '/agency/clients', ar: 'العملاء', en: 'Clients', icon: Building2 }],
  },
  {
    key: 'work',
    ar: 'العمل', en: 'Work', icon: Megaphone,
    leaves: [
      { to: '/agency/requests', ar: 'الطلبات', en: 'Requests', icon: Inbox },
      { to: '/agency/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban },
      { to: '/agency/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone },
      { to: '/agency/content', ar: 'المحتوى', en: 'Content', icon: Images },
    ],
  },
  {
    key: 'operations',
    ar: 'التشغيل', en: 'Operations', icon: ListChecks,
    leaves: [
      { to: '/agency/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks },
      { to: '/agency/messages', ar: 'المحادثات', en: 'Conversations', icon: MessageSquare },
      { to: '/agency/files', ar: 'الملفات', en: 'Files', icon: FolderOpen },
      { to: '/agency/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3 },
      // An agency watches spend and performance across every client it runs, so this is arguably
      // more its section than the advertiser's. It was reachable only at `/app/alerts`, which an
      // agency has no other reason to visit.
      { to: '/agency/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing },
    ],
  },
  {
    key: 'finance',
    // Two different pots, and confusing them is expensive: Finance is what the agency's CLIENTS pay
    // it, Subscription is what the agency pays CampaignsHub. The advertiser portal has only the
    // second, because an advertiser has nobody to invoice.
    ar: 'المالية', en: 'Finance', icon: Receipt,
    leaves: [
      { to: '/agency/billing', ar: 'فواتير العملاء', en: 'Client invoicing', icon: Receipt },
      { to: '/agency/subscriptions', ar: 'اشتراك الوكالة', en: 'Agency subscription', icon: CreditCard },
    ],
  },
  {
    key: 'team',
    // The agency portal's own thing: who on the team may reach which client.
    ar: 'الفريق والنطاقات', en: 'Team & scopes', icon: Users,
    leaves: [{ to: '/agency/team', ar: 'الفريق والنطاقات', en: 'Team & scopes', icon: Users }],
  },
]

export const agencyNavLeafPaths: readonly string[] = agencyNavGroups.flatMap((g) => g.leaves.map((l) => l.to))
