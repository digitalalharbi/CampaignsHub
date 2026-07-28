# Paid-Media Services — selector + dynamic request form (engine-driven)

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
