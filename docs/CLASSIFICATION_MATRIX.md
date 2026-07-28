# Classification Matrix — canonical taxonomies per module

Each row = one `taxonomy_definition`. field: single|multi|hier. system=immutable key.

| key | module | field | system | canonical options (keys) |
|---|---|---|---|---|
| request.service | requests | single | yes | paid_advertising, influencer_ugc, analytics, tracking, reporting, integrations, consulting, custom |
| request.category | requests | single | no | (per service) new_campaign, existing_management, optimization, account_audit, tracking_setup, reporting, data_integration, consultation |
| request.type | requests | single | no | (per category, tenant-manageable) |
| request.objective | requests | single | no | sales, leads, awareness, traffic, engagement, app_installs, video_views, store_visits, custom |
| request.priority | requests | single | yes | critical, high, medium, low |
| request.status | requests | single | yes | draft, contact_verification, submitted, under_review, waiting_for_information, qualified, proposal_sent, awaiting_client_approval, payment_pending, paid, onboarding, in_progress, client_review, completed, archived, rejected, cancelled, payment_failed, refunded, on_hold |
| request.payment_status | requests | single | yes | none, pending, paid, failed, refunded |
| request.source | requests | single | no | website, referral, direct, campaign, other |
| client.status | clients | single | yes | prospect, onboarding, active, needs_attention, paused, completed, archived |
| client.service_level | clients | single | no | basic, standard, premium, enterprise |
| client.industry | clients | single | no | ecommerce, saas, education, health, real_estate, food, travel, other |
| client.priority | clients | single | yes | critical, high, medium, low |
| client.source | clients | single | no | website, referral, direct, event, other |
| client.tags | clients | multi | no | (tenant-manageable) |
| campaign.objective | campaigns | single | yes | sales, leads, awareness, traffic, engagement, app_installs, video_views, store_visits, custom |
| campaign.platforms | campaigns | multi | no | meta, google, tiktok, snapchat, x, linkedin, microsoft, pinterest |
| campaign.regions | campaigns | multi | no | (tenant-manageable) |
| campaign.audiences | campaigns | multi | no | (tenant-manageable) |
| campaign.conversion_events | campaigns | multi | no | (tenant-manageable) |
| campaign.creative_types | campaigns | multi | no | image, video, carousel, story, collection, text |
| campaign.tags | campaigns | multi | no | (tenant-manageable) |
| integration.category | integrations | single | yes | advertising, analytics, stores, files, messaging, payment, other |
| project.status | projects | single | yes | draft, onboarding, active, paused, completed, archived |

Objective → dependent config (metadata on campaign.objective options):
sales→{kpi:[roas,cpa,revenue],funnel:conversion,template:performance} · leads→{kpi:[cpl,leads,conv_rate]} ·
awareness→{kpi:[reach,impressions,cpm],funnel:awareness,template:brand} · traffic→{kpi:[clicks,ctr,cpc]} ·
video_views→{kpi:[views,vtr,cpv]} · app_installs→{kpi:[installs,cpi]} · engagement→{kpi:[engagements,eng_rate]}.
(ROAS never primary for awareness; Leads never primary for video.)
