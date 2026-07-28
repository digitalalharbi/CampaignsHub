# Paid-Media Services — selector + dynamic request form (engine-driven)

## ⚑ v2 (SUPERSEDES the "click استعرض الخدمات first" flow below) — services visible in the FIRST viewport
The visitor must understand and pick services **without first clicking away**. Redesign the homepage Hero as **two columns** (do NOT regress the existing visual polish, reference-inspired, RTL/LTR, light/dark, mobile-balanced, page not made longer):
- **Main column**: value proposition + Product Preview. Headline stays «كل حملاتك الإعلانية المدفوعة في مكان واحد». New sub-desc: «أدر حملاتك بنفسك، أو اختر خدمة متخصصة في الإدارة والتحسين والتتبع والتحليل والتقارير، وتابع طلبك وتنفيذه من منصة واحدة.»
- **Side card «كيف تريد استخدام CampaignsHub؟»** with 4 usage options: أدير حملاتي بنفسي · أدير حملات عدة عملاء · أحتاج خدمات إعلانية · أحتاج حملة مؤثرين أو UGC. Selecting **«أحتاج خدمات إعلانية»** reveals the services **inline inside the same card** (no navigation yet).
- **Inline services (in first viewport)**: 8 category chips / compact tabs — إدارة الحملات · التحسين والأداء · التدقيق والتحليل · التتبع والقياس · التكاملات · التقارير · الاستراتيجية · الاستشارات. Under them **6–8 popular services only** (e.g. إطلاق حملة جديدة، إدارة حملات قائمة، تحسين الأداء، تدقيق الحساب الإعلاني، ربط البكسل والتتبع، إعداد GA4 وGTM، تحليل الحملات، إنشاء تقرير احترافي) as small cards (icon + name + very short desc + select). Clicking a category swaps the shown services live. Multi-select with selected chips + remove + clear-all; show «الخدمات المختارة: N»; primary button «أكمل تفاصيل طلبك» → `/requests/new?module=paid-media&services=<selected-keys>` (all selections carried, never re-picked).
- **«عرض جميع الخدمات»** opens an in-page **Drawer/Modal** (not a long page, not dozens dumped in the hero): search + category filter + multi-select + service description + custom request.
- **Categories & services come from the Taxonomy engine** (`request.paid_service`), never hardcoded React arrays; authorized users manage (add/edit/translate/reorder/enable-disable/move-category/custom-fields/price) via Settings→Taxonomies, and new services appear on the homepage + intake with **no code change / no rebuild**.
- Acceptance (v2): paid-media services visible immediately · categories clear · 6–8 popular in first viewport · multi-select works · selections persist to intake (no duplicate selection step) · dynamic fields work · all taxonomy-managed · no hardcoded arrays · no dead buttons · desktop/mobile balanced · RTL/LTR · light/dark · Chromium/Firefox/WebKit.

The category→service key lists, `metadata.needs`, persistence, and dynamic-field rules below still apply unchanged.

---


Homepage journey #3 = «أحتاج خدمات إعلانية» / "I need paid-media services", desc «اختر من خدمات الإدارة،
التحسين، التحليل، التتبع، التكاملات، التقارير والاستشارات.», button «استعرض الخدمات» → `/requests/new?module=paid-media`.
It opens a structured selector ("ما الخدمة التي تحتاجها؟"), NOT a generic form. All services come from the central
Taxonomy engine (manageable: enable/disable/reorder/translate/custom), never hardcoded in React.

## Taxonomy: `request.paid_service` (hierarchical, multi-select, tenant-manageable, is_system=false)
Parent categories (option keys) + their service options (keys = slug):
1. launch_manage — new_campaign, existing_management, full_monthly_management, multi_platform_management,
   ad_account_restructure, budget_pacing, seasonal_campaigns, product_launch_campaigns
2. optimization — improve_performance, reduce_cpa_cpl, improve_roas, raise_conversion_rate, audience_targeting,
   budget_allocation, ad_creative_testing, weak_results_analysis, sales_drop_recovery
3. audit_analysis — ad_account_audit, campaign_performance_analysis, customer_journey_analysis, funnel_analysis,
   creative_analysis, platform_comparison, budget_spend_analysis, tracking_attribution_analysis, paid_plan_review
4. measurement_tracking — meta_pixel, tiktok_pixel, snapchat_pixel, google_ads_conversions, ga4, gtm,
   conversion_api, server_side_tracking, store_events, app_events, event_quality_testing, tracking_troubleshoot,
   utm_setup, attribution_setup
5. integrations — ad_accounts, ecommerce_store, salla, zid, shopify, woocommerce, crm, google_analytics,
   google_drive, data_sources, unified_dashboard, sync_error_handling
6. strategy_planning — ad_strategy, media_plan, budget_sizing, platform_selection, campaign_objectives,
   kpi_definition, marketing_funnel, retargeting_plan, acquisition_plan, product_launch_plan
7. reporting_dashboards — weekly_report, monthly_report, executive_report, live_dashboard, custom_report,
   platform_comparison_report, client_reports, report_scheduling
8. creatives — creative_audit, ad_performance_analysis, top_creatives, angles_hooks, creative_testing_plan,
   ugc_suggestions, creative_performance_link, creative_fatigue
9. objective_services — sales, leads, awareness_reach, traffic, engagement, app_installs, video_views,
   store_visits_events, retargeting
10. consulting_training — media_buying_consult, performance_review_session, platform_selection_consult,
    tracking_consult, team_training, pre_launch_review, custom_request

Each option: label_ar/label_en/description/icon/color/metadata (the metadata drives the dynamic form — see below).

## Selector UX (`/requests/new?module=paid-media`)
Drawer/page: title + search + category filter (the 10 groups) + service cards/rows; multi-select → selected
Chips; "+ custom request"; "+ add option" for authorized users (options.create). Do NOT dump ~90 services on the
homepage — only after the click. Uses the existing MultiSelectField / HierarchicalSelect + CreatableSelect.

## Dynamic form (steps adapt to selected services via option `metadata.needs`)
Base flow: Selected services → business objective → platforms → budget → period → existing accounts →
tracking status → files → notes → review & submit. Questions appear/hide per selected service:
- measurement_tracking.* → site URL, platform, GTM, required events, store/app.
- audit_analysis.* / campaign_performance_analysis → period, platforms, previous reports.
- launch_manage.* → budget, objectives, regions, creatives.
- reporting_dashboards.* → period, audience, language, format, data sources.
Never mix service vs campaign-objective vs platform. A request supports MULTIPLE services.

## Persistence + surfacing
Store selected services on the request (jsonb `services` on external_requests, engine keys). Surface the selected
services in: client portal request detail, internal requests dashboard, the quote, and the invoice. Each service
priceable later (standalone or bundle) — keep the keys stable.

## Rules
Engine-managed (enable/disable/reorder/translate/custom); multi-service; no hardcoded FE list; no lost selections
across steps; service ≠ objective ≠ platform; selections visible in portal/dashboard/quote/invoice.
