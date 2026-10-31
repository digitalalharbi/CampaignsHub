# NASEL / Riwaq — landing-page conversion tracking: external changes required

**Status: BLOCKED_EXTERNAL_CREDENTIALS.** CampaignsHub has no access to the nasel.co source, its backend or the GTM container
`GTM-TL4KFDMG`. Nothing below has been implemented by CampaignsHub. It is the specification for the developer responsible for nasel.co and
its tag container. Evidence was read on 2026-10-29 from the public page `https://nasel.co/riwaq` and the public container script, without
executing any tag and without submitting the form.

## What happens today (read from source)

1. The form `#rawaqLeadForm` posts by AJAX to `https://nasel.co/rawaq-request` (`assets/web/js/custom/newsletter.js`).
2. The success handler shows a toast and the modal `#rawaqSuccessModal`. It pushes **nothing** to `dataLayer` and the response is not read
   for any identifier.
3. A GTM Custom HTML tag (container tag 4) listens to jQuery `ajaxSuccess` for a URL containing `rawaq-request`. It generates
   `rwq_<timestamp>_<random>` **in the browser**, stores it in `window.__rwqLeadId` and pushes `{event: 'rawaq_lead', lead_id}`.
4. GTM sends `rawaq_lead` as:
   - Meta: `Purchase` (eventID = lead_id) **and** `Lead` (eventID = lead_id + `_lead`). That is two events for one submission.
   - TikTok: `Purchase` (event_id = lead_id).
   - Snapchat: `PURCHASE` (transaction_id = lead_id).
   - X: `tw-ptvk8-ptvk9` (conversion_id = lead_id).
   - GA4 `G-D6V0R70S8G`: `purchase` (transaction_id = lead_id).
   - Google Ads `AW-18536433479`: conversion label `mRdwCPTu96wdEMeO7oZF`.
   - OpenAI: `lead_created` (event_id = lead_id).
5. The same tag pushes:
   - `form_start` on the first focus inside the form. It goes out as InitiateCheckout (Meta, TikTok), START_CHECKOUT (Snapchat) and
     GA4 `begin_checkout`.
   - `whatsapp_click` / `call_click` / `email_click` on `wa.me`, `tel:` and `mailto:` links. They go out as Contact (Meta, TikTok),
     CUSTOM_EVENT_1/2/3 (Snapchat) and GA4 events of the same names.
6. The page loads `gtag('config', 'G-D6V0R70S8G')` directly **and** the container also carries the Google tag for `G-D6V0R70S8G`.

## Defects and required changes

| # | Defect | Required change (nasel.co / GTM) |
|---|---|---|
| 1 | The lead id is invented in the browser, so no platform event can be matched to the stored request. | `/rawaq-request` stores the request, creates a stable `lead_id` (for example a UUID) in the same transaction and returns it in the success JSON: `{"message": "...", "lead_id": "..."}`. |
| 2 | `rawaq_lead` is derived from `ajaxSuccess`, not from the stored request. | Remove the `ajaxSuccess` listener from tag 4. In the success handler, after the server answered 2xx **with** a `lead_id`, push `{event: 'rawaq_lead', lead_id: res.lead_id}`. No `lead_id`, no event. |
| 3 | A double submit or refresh can create duplicates. | The server accepts an idempotency key, for example a hidden `client_request_id` generated once per form render. A repeat with the same key returns the same `lead_id` and creates nothing. The page keeps a per-tab `sessionStorage` set of pushed lead_ids and never pushes one twice. |
| 4 | Meta receives `Purchase` and `Lead` for one submission, under two different event ids. | Send one standard event to Meta, with `eventID = lead_id`. If both must stay, use the **same** `eventID` for both, and keep the reporting mapping on `Purchase` only. |
| 5 | Every tag falls back to `"rwq_" + Date.now()` when the id is missing. | Remove the fallback. A conversion without the server's `lead_id` is not sent. |
| 6 | GA4 may count `page_view` twice: direct `gtag('config')` plus the container's Google tag for the same ID. | Keep one. Remove the inline `gtag.js` + `config` from the page, or remove the GA4 Google tag from GTM. Confirm in GA4 DebugView that one page load gives one `page_view`. |
| 7 | Server-side confirmation is not available to CampaignsHub. | Optional, later: send each stored request's `lead_id`, timestamp and UTM/click ids (no name, phone or e-mail) to CampaignsHub once an intake exists. CampaignsHub then reports «Confirmed website leads» from it. |

## Acceptance (the evidence CampaignsHub needs before any VERIFIED)

1. One **authorised test submission** marked as a test (for example the name `TEST-CH-<date>`), made by the Owner or the site developer,
   **not** by CampaignsHub.
2. Its `lead_id` in the server's stored request, the same value as `eventID` / `event_id` / `transaction_id` in Meta Events Manager
   (Test Events), TikTok Events Manager, Snapchat Events Manager, GA4 DebugView and the X pixel helper.
3. After the next sync, the landing completion appears once in CampaignsHub's report for its platform and date, and is not counted as a
   native lead.
