# Modules & Classifications Audit — CampaignsHub (honest, code-level)

> Item 1 of the "lift every module to command-center quality" phase. Labels: مكتمل ومختبر · مكتمل جزئيًا · واجهة فقط · بيانات تجريبية فقط · يحتاج إعادة تصميم · يحتاج Backend · يحتاج ربطًا فعليًا · بانتظار بيانات اعتماد · غير منفذ. A page/cards ≠ complete. Evidence = frontend `features/<m>` (+ line counts) and backend `app/Domains/<M>`.

## Headline gaps (build targets)
- **Ad Contents / Creatives module = غير منفذ** — no `features/*content*|*creativ*` dir at all. Must be BUILT (grid/table/performance/comparison/needs-attention + creative detail), fed by creative discovery after real/sandbox sync. Also the shared "top creatives" component to reuse in dashboard/analytics/campaign/reports/marketing.
- **Tasks = غير منفذ (as a page)** — backend `app/Domains/Tasks` exists; no dedicated frontend page. Build tasks center (from alerts→create-task, per-entity tasks).
- **Files = غير منفذ (standalone)** — only `clients/TabFiles.tsx`; no unified Files module.
- **Finance = مكتمل جزئيًا / يحتاج إعادة تصميم** — `features/billing/{InvoicesPage(666),QuotesPage,PaymentsPage}` are separate lists; NO unified Finance overview center (totals: quotes/accepted/issued/paid/outstanding/overdue/budgets/ad-spend) and no unified status vocab surfacing.
- **Project Integrations = يحتاج إعادة تصميم** — must be rebuilt around the 6 REAL ad platforms (Snapchat/TikTok/Meta/Google Ads/X/LinkedIn) with a «المنصات المرتبطة» section per project; NOT a generic "Sandbox/Connection" card. "Sandbox" may appear ONLY as a dev «وضع تجريبي — لا توجد بيانات حقيقية» tag, never as the primary connection state.

## Module table
| Module | Path | Label | Evidence | Gap to command-center quality |
|---|---|---|---|---|
| Dashboard | features/dashboard/DashboardPage (284) | مكتمل جزئيًا (demo) | platforms/freshness/budget/campaigns, KPIs, donut, comparison | unified filter bar + objective KPIs + saved views (see CAMPAIGN audit item 4) |
| Campaigns | features/campaigns (9 tsx, Detail 599) | مكتمل جزئيًا | list cards/table, detail page | view-modes/comparison/needs-attention; taxonomy chips; details depth |
| Analytics | features/analytics/AnalyticsPage (356) | مكتمل جزئيًا (demo) | charts/hooks | align to SAME unified/normalized source + filters + objective KPIs |
| Reports | features/reports/ReportsPage (480) | مكتمل جزئيًا | builder engine-fed, GenerateReportJob | consume the SAME unified metrics as dashboard; not a divergent query |
| **Alerts** | features/alerts/AlertsPage (482) | **يحتاج إعادة تصميم** | rule tab + list | full alert center: categories (perf/budget/sync/connection/content/report/task/system) + severity + status workflow (جديد→قيد المراجعة→مهمة→مؤجل→محلول→مغلق) + filters + rich alert card (metric/current/reference/platform/campaign/proposed action) + actions (open campaign/create task/assign/snooze/resolve/close); keep Alerts≠Notifications |
| **Messages** | features/messaging/ThreadsPage (271) | **يحتاج إعادة تصميم** | threads | unified inbox: category tabs + thread list + context panel (linked client/request/project/invoice) + attach/assign/link/convert-to-task/archive |
| **Finance** | features/billing/{Invoices(666),Quotes,Payments} | **يحتاج إعادة تصميم** | 3 separate lists | one Finance center: overview KPIs + quotes/invoices/payments/outstanding/budgets/ad-spend + status vocab + detail (client/request/services/items/tax/discount/total/paid/remaining/history/messages/payments); never show Paid/Sent unless real |
| **Ad Contents/Creatives** | — | **غير منفذ** | no dir | BUILD full module (see headline) |
| **Tasks** | backend only | **غير منفذ (page)** | app/Domains/Tasks | build tasks center + entity linkage |
| **Files** | clients/TabFiles only | **غير منفذ (standalone)** | — | unified Files module (optional per priority) |
| Requests | features/requests (7 tsx, Detail 1045) | مكتمل جزئيًا | rich detail + intake | align statuses to taxonomy; relate to campaign |
| Clients | features/clients/ClientCommandCenterPage (346, 10 tsx) | مكتمل جزئيًا | command center + tabs | related-entities links (projects→platforms→campaigns→finance) |
| Projects | features/projects/ProjectIntegrationsPage (572) | يحتاج إعادة تصميم | integrations-centric | «المنصات المرتبطة» around 6 real platforms; project overview |
| Integrations | features/integrations/IntegrationsPage | يحتاج اعتمادات المنصات | **Done (INTEG-RUNTIME §1 §2).** `features/connections/*` and the parallel connector framework are deleted. The page renders the 6 real ad platforms, the 2 stores, and every discovered account with the project it feeds |
| Subscriptions | features/subscriptions (240) | مكتمل جزئيًا | plan page, no filters | fine for now |
| CRM/Leads | features/crm/LeadsPage (135) | واجهة فقط / thin | list | lift if in scope |
| Notifications | (dropdown, no page) | مكتمل جزئيًا | — | keep separate from Alerts |
| Settings | features/settings/SettingsPage (68) + tabs | مكتمل جزئيًا | tabs | fine |
| Branding | features/branding (371) | مكتمل جزئيًا | — | fine |

## Classifications (Taxonomy)
30 definitions, uniquely namespaced, no duplication (`TaxonomyEngineSeeder`). Manageable options must stay engine-fed — audit each redesigned module to ensure NO hardcoded React arrays for manageable classifications (alerts categories/severity/status, message categories, finance statuses, content types, integration categories, project platform states → prefer taxonomy where user-manageable; keep enum-backed system states as system keys).

## Honest-state rule (integrations)
Connection states allowed: غير مربوط · بانتظار بيانات الاعتماد · جاهز للربط · قيد الاتصال · متصل · قيد المزامنة · متزامن · صلاحية منتهية · صلاحيات ناقصة · فشل الربط · بيانات قديمة · وضع تجريبي. NEVER show متصل/متزامن/Paid/Sent/Live/جاهز للاستخدام without a real op. "Sandbox" only as an explicit dev «وضع تجريبي — لا توجد بيانات حقيقية» tag.

## Execution order (this phase, mandatory)
1 Modules audit (this doc) → 2 Alerts redesign → 3 Messages redesign → 4 Finance redesign → 5 Ad Contents/Creatives (build) → 6 Project Integrations redesign (6 real platforms) → 7 `docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md` → 8–13 Meta/Google/TikTok/Snapchat/X/LinkedIn remediation (real OAuth/sync where creds exist; else «جاهز ويحتاج بيانات اعتماد» + sandbox flow) → 14 cross-module relationships → 15 demo-data upgrade (interlinked, math-consistent, labeled «بيانات تجريبية») → 16 visual review → 17 full regression → 18 clean install → 19 handover. No delivery before 1–17.

## Acceptance per module (do not close on a page alone)
Professional UI · interlinked data · working filters/search · correct classifications · complete detail page · working actions · permissions · tenant isolation · loading/empty/error · RTL/LTR · light/dark · desktop/mobile · backend+frontend tests · E2E · live browser review.
