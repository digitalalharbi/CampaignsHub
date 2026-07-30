import {
  BarChart3, BellRing, Building2, CreditCard, FolderKanban, FolderOpen, Images, Inbox,
  LayoutDashboard, ListChecks, MessageSquare, Megaphone, Plug, Receipt, Settings, TrendingUp,
} from 'lucide-react'
import type { NavGroup } from './SidebarNav'

/**
 * The advertiser portal's navigation (`/app`), grouped.
 *
 * Sixteen flat entries is a list you scan rather than read. These are the SAME sixteen — nothing was
 * removed, merged away or moved to another portal — arranged by the question the advertiser is
 * asking, under the portal's own purpose: every paid campaign in one place.
 *
 *   Work        — the things being run: requests in, clients, projects, campaigns, creative.
 *   Performance — how they are doing: analytics, reports, and the alerts that interrupt you.
 *   Operations  — what keeps them running: tasks, files, conversations, data sources.
 *   Finance     — money.
 *
 * `appNavLeafPaths` is exported so a test can assert this grouping still contains every path the flat
 * rail did. Grouping must never be how a section quietly disappears.
 */
export const appNavGroups: readonly NavGroup[] = [
  {
    key: 'overview',
    ar: 'لوحة التحكم', en: 'Dashboard', icon: LayoutDashboard,
    leaves: [{ to: '/app/dashboard', ar: 'لوحة التحكم', en: 'Dashboard', icon: LayoutDashboard, ent: 'dashboard' }],
  },
  {
    key: 'work',
    ar: 'العمل', en: 'Work', icon: Megaphone,
    leaves: [
      { to: '/app/requests', ar: 'الطلبات', en: 'Requests', icon: Inbox, ent: 'requests' },
      { to: '/app/clients', ar: 'العملاء', en: 'Clients', icon: Building2, ent: 'clients' },
      { to: '/app/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban, ent: 'projects' },
      { to: '/app/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone, ent: 'campaigns' },
      { to: '/app/content', ar: 'المحتوى', en: 'Content', icon: Images, ent: 'content' },
    ],
  },
  {
    key: 'performance',
    ar: 'الأداء', en: 'Performance', icon: TrendingUp,
    leaves: [
      { to: '/app/analytics', ar: 'التحليلات', en: 'Analytics', icon: TrendingUp, ent: 'analytics' },
      { to: '/app/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3, ent: 'reports' },
      { to: '/app/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing, ent: 'alerts' },
    ],
  },
  {
    key: 'operations',
    ar: 'التشغيل', en: 'Operations', icon: ListChecks,
    leaves: [
      { to: '/app/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks, ent: 'tasks' },
      { to: '/app/messages', ar: 'المحادثات', en: 'Conversations', icon: MessageSquare, ent: 'messaging' },
      { to: '/app/files', ar: 'الملفات', en: 'Files', icon: FolderOpen, ent: 'connections' },
      { to: '/app/integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug, ent: 'connections' },
    ],
  },
  {
    key: 'finance',
    ar: 'المالية', en: 'Finance', icon: Receipt,
    leaves: [
      { to: '/app/billing', ar: 'الفواتير', en: 'Billing', icon: Receipt, ent: 'billing' },
      { to: '/app/subscriptions', ar: 'الاشتراك', en: 'Subscription', icon: CreditCard, ent: 'subscriptions' },
    ],
  },
  {
    key: 'settings',
    // WORKSPACE settings. Personal settings live in the account menu and nowhere else; system
    // settings live in /admin. This entry is the workspace's own.
    ar: 'إعدادات مساحة العمل', en: 'Workspace settings', icon: Settings,
    leaves: [{ to: '/app/settings', ar: 'إعدادات مساحة العمل', en: 'Workspace settings', icon: Settings, ent: 'settings' }],
  },
]

/** Every destination the advertiser rail offers. The flat rail had exactly these. */
export const appNavLeafPaths: readonly string[] = appNavGroups.flatMap((g) => g.leaves.map((l) => l.to))
