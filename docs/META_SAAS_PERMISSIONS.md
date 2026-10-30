# Meta SaaS app — permission traceability (App Review, 2026-10-30)

App «CampaignsHub Ads Integration», App ID 2604968763258530. This covers the seven requests on the App Review screen.
Sources:
- code on `main` (`ec176e58`);
- Meta's permissions reference (developers.facebook.com/docs/permissions, «Dependencies»);
- the Marketing API authorization page;
- Production evidence from the SaaS round trip on 2026-10-30.

A granted permission is not Meta's approval. Nothing below says App Review, Business Verification or Advanced Access has been granted.

## Matrix

| Request | Product function | Graph API calls (code) | Where in CampaignsHub | Live evidence 2026-10-30 | Verdict |
|---|---|---|---|---|---|
| `ads_read` | Account discovery, campaign/ad set/ad structure, daily insights sync, reports, live client links | `me/adaccounts`, `{act}/campaigns`, `{act}/adsets`, ads, `{act}/insights` (`MetaConnector`, `AccountMetricsSyncer`, `MetaCandidateRoundTrip`) | Integrations → connect Meta → accounts → project binding; Analytics; Reports | **LIVE VERIFIED**: granted in `/me/permissions`; the round trip's real 7-day insights read returned ok; 1 account discovered, bound and synced. Daily metrics rows: none yet; zero-delivery account vs defect not yet determined | **KEEP** (core) |
| `ads_management` | Campaign management: pause, resume, rename, budget, bid strategy, schedule, targeting, placements, archive, duplicate (copies land PAUSED), bind an existing creative | `POST /{id}`, `/{id}/copies` (`MetaWriteAdapter` via `CampaignWriteService`); routes `campaigns/{id}/pause`, `campaigns/{id}/provider-writes` behind `campaigns.manage`; capability `advertising_management` is default_on | Campaigns → a campaign → actions; AI Agents executor (propose-only, Owner approval per action) | **Granted** (in `/me/permissions`). **No write performed**: every write needs separate Owner authorisation | **KEEP**: a shipped, default-on feature. The screencast needs one authorised, reversible write on a test ad account (pause, then resume a campaign that has no budget running) |
| `business_management` | Discovery of ad accounts reached through a Business Manager (owned and client/partner accounts). Agencies whose clients share accounts as partners need it (the «Henka» case) | `me/businesses`, `{business}/owned_ad_accounts`, `{business}/client_ad_accounts` (`MetaConnector` lines 172–200); without it discovery warns and returns direct accounts only | Integrations → connect Meta → account list (business-shared accounts) | **NOT granted**: the configuration `1138211818567426` does not include it (granted: ads_management, ads_read, public_profile). The SaaS app's requested scopes are `ads_read, ads_management` only | **KEEP**: required for agency use. **Owner action:** add it to the Facebook Login for Business configuration and to the requested scopes on /admin/settings/integrations/meta-saas, then re-run the round trip |
| `pages_show_list` | No direct product use: CampaignsHub calls no Page endpoint (`me/accounts`, Page posts); creatives are bound by existing `creative_id` | none | none | Not granted, not used | **DEPENDENCY**: Meta lists it as a dependency of `ads_management` and `business_management`. Keep it only while those stay. The reviewer note must say so, not invent a Page feature |
| `pages_read_engagement` | No direct product use | none | none | Not granted, not used | **DEPENDENCY**: listed by Meta for `ads_management` and `business_management`. Same handling as `pages_show_list` |
| `public_profile` | Identity of the person who consented (connection «authorised by») | Implicit in Facebook Login | Integrations: «صرّح به …» | **Granted** | **KEEP**: Meta grants it to all apps automatically |
| Marketing API Access Tier | Not an OAuth permission: the API rate-limit tier. Limited access is the development default; Full access needs App Review | n/a | n/a | Not readable from CampaignsHub; check App Dashboard → Marketing API → Access level | **KEEP** in the submission: Full access is needed for unlimited client ad accounts. **Eligibility (Meta):** at least 500 Marketing API calls in the last 15 days, and an error rate under 15% over the last 500 calls. The SaaS app has made only a handful of calls; daily test-workspace syncs build the count |

**Nothing to remove.** Every request is either used by the product or is a dependency Meta declares for a used permission. Removing `pages_*` would break the submission of `ads_management` and `business_management`.

## Not yet LIVE VERIFIED
- **Daily metrics:** match DailyMetric against Ads Manager for the test account. It needs an account with delivery, or proof that zero is correct.
- **Report:** create and export one in the test workspace.
- **Disconnect:** do it last.
- **`business_management` discovery:** after the configuration change.
- **An `ads_management` write:** only with written Owner authorisation.
- **The deletion callback:** not verified until Meta signs a real call.
- **Business Verification, App Review, Advanced Access, Marketing API tier:** Meta-side only; no evidence in hand.

## Screencasts (3 videos)

Record in English UI (`EN` toggle), signed in as the test-workspace user, on the test ad account only. Show the browser address bar throughout.

1. **Login + `ads_read` (+ `public_profile`)**, about 2–3 min.
   1. campaignshub.io/login → Integrations → «Connect Meta».
   2. The Meta consent dialog, showing the app name and the permissions.
   3. Return to CampaignsHub: «Connected», authorised by the user's name (public_profile).
   4. The discovered ad account, bound to «Meta SaaS Test — Project».
   5. Campaigns list read from Meta.
   6. Analytics with spend, impressions and results for a date range.
   7. Create a report and export it.

   Caption: *"CampaignsHub reads the advertiser's own ad accounts, campaigns and insights (ads_read) to show performance dashboards and client reports. It never reads accounts the user did not grant."*
2. **`business_management` (+ `pages_show_list`, `pages_read_engagement` as dependencies)**, about 1–2 min.
   1. Connect with a Facebook user who has a Business Manager with an owned account and a client (partner) ad account.
   2. In the consent dialog, the business is selected.
   3. In CampaignsHub's account list, the business-shared accounts appear with the business as parent.
   4. Bind one to a project.

   Caption: *"Agencies manage client ad accounts shared through Business Manager. CampaignsHub reads the businesses the user granted and their owned and client ad accounts, only to list them for selection. It uses no Page data; the Page permissions are requested only as Meta-declared dependencies."*
3. **`ads_management`**, about 1–2 min. Needs the Owner's written authorisation first.
   1. Pick a test campaign with no active spend → «Pause» in CampaignsHub.
   2. Ads Manager shows it Paused.
   3. «Resume» in CampaignsHub → Ads Manager shows it Active again (or leave it paused if it should not deliver).
   4. Show the confirmation dialog and the audit entry.

   Caption: *"Advertisers manage campaign delivery and budgets from CampaignsHub. Every change is explicit and confirmed by the user, and duplicates are created paused."*

No video is needed for Marketing API Access Tier.

## Submit for Review: NO-GO until all of these hold
1. `business_management` is added to the configuration and the requested scopes, and the round trip shows it granted.
2. Videos 1–3 are recorded, video 3 after a written authorisation for one reversible write.
3. Daily metrics are matched with Ads Manager, or zero is proven correct, and a report is exported.
4. Business Verification is complete (Meta requires it for Advanced Access).
5. The Marketing API tier eligibility is met (500 calls in 15 days, under 15% errors), or the tier request is deferred.
6. The data-deletion URL `https://api.campaignshub.io/api/v1/webhooks/data-deletion/meta-saas` and App Domains `campaignshub.io` and `api.campaignshub.io` are set in Basic settings.
