# SaaS structure and request routing — audit (Batch A)

**Unified reference:** `docs/CampaignsHub_MASTER_FINAL_COMPLETE_2026-11-11.md` (sections أ–ط; this is its «دفعة 0»).
**Contract:** `docs/CampaignsHub_SaaS_Structural_Security_And_Routing_Contract_2026-11-11.md` (binding, cumulative; Appendix B of the reference).
**Ledger:** #168, #169.
**Read against:** `origin/main` `8a4112423` (2026-11-11). **Matrix section:** «SaaS structure and request routing 2026-11-11».
**Probes:** `backend/tests/Feature/SaasStructureRoutingContractTest.php` (8 pass, 4 stop at a proven gap).
**Production:** read-only `BEGIN READ ONLY … ROLLBACK` counts and statuses, runs 43040097104, 43040351516, 43042874020,
43042991090 (workflow on the temporary branch `tmp/facts-map`). No write, no message sent, no payment, no personal data
printed. Nothing in this batch changes behaviour.

A status here is one of the contract's seven. `VERIFIED` is used only where the evidence is operational.

## 1. Verdict

1. **Tenant isolation, client isolation, invitations, payment activation and the platform/tenant wall were probed and no
   leak was found.** Every one of those properties has a passing feature test, listed in §6.
2. **The proven P0 is structural, not a leak:** there is no platform-owned service inbox. A request made from the official
   site is filed under whichever tenant is flagged `is_default_portal`, and an unknown host falls to the same tenant.
   Production has no such tenant today, so every «اطلب خدمة» button fails closed and no request has ever been stored.
3. **What is addressed to the platform is not read.** Contact messages, support tickets and data requests have an API and
   no screen; nobody is notified of any of them; the one counter the console shows for registrations counts states that
   do not exist.

## 2. Layers, as built

| Layer | Lives in | Created by | Reaches | Evidence |
|---|---|---|---|---|
| Platform owner | `users.is_platform_admin`, no membership | by hand, never by sign-up | `/admin`, `auth:sanctum` + `platform` | `PlatformAdminFlagTest`, `PortalDistinctnessTest::test_the_platform_owner_lands_in_the_admin_console_and_holds_no_membership`; Production: 2 administrators, 0 memberships between them |
| Solo, advertiser | tenant, `account_type` freelancer, in_house_team, brand or self_serve_company, portal `app` | `/register`, or `POST /admin/tenants` | `/app` | `Portal.php:205-211`; Production: 2 self_serve_company tenants |
| Team, agency | tenant, `account_type` agency, portal `agency` | `/register`, or `POST /admin/tenants` with an owner invitation | `/agency` | Production: 7 agency tenants |
| Agency client | `client_workspaces` row inside the agency tenant, portal `portal` | invitation from the agency, or the older OTP identity taken from a request contact | `/portal`, one client at a time | `ClientPortalIsolationProbeTest`, `ClientSpaceIsolationTest`; Production: 25 client workspaces, 0 portal tokens |
| Creator | `influencers` row per tenant plus a creator membership | granted from the roster | `/influencers/me` | `CreatorPortalTest`; Production: `FEATURE_INFLUENCERS_UGC=false`, 0 rows |

Hierarchy inside a tenant: tenant → client workspace → project → external accounts → campaigns. There is no
platform-level creator pool in this repository: the roster is tenant-scoped (`routes/api/influencers.php:23-75`).

## 3. Every public entry, to its record, its inbox, its notification and its conversion

| Public URL | Frontend route | API | Controller | Model, table | Owner scope today | Inbox | Notification | Conversion |
|---|---|---|---|---|---|---|---|---|
| `/register` | `RegisterPage` | `POST /auth/register` | `RegistrationController@store` | `RegistrationRequest`, `registration_requests` | Platform | `/admin/registrations` | applicant only | decision → `ProvisionWorkspace` → tenant, subscription, owner membership |
| `/#services`, `/services`, `/services/{category}`, «اطلب خدمة» | `RequestIntakePage`, `PaidMediaIntake` at `/requests/new` | `POST /requests` | `PublicRequestController@store` → `PortalTenantResolver` → `RequestIntake` | `ExternalRequest`, `external_requests` | **a tenant**: `portal_domain` match, else the `is_default_portal` tenant, else 404 | that tenant's `/agency/requests` | applicant (`client_notifications`); the team is not told at receipt | `RequestConversionService`: client, project, draft campaign, tasks |
| `/requests/track?token=` | `RequestTrackPage` | `GET /requests/track/{token}` | `PublicRequestController@track` | `RequestAccessToken` (SHA-256, revocable, optional expiry) | the one request | none | none | reply becomes a client-visible comment |
| `/portal/requests/new` | `ClientNewRequestPage` | `POST /client/requests` | `ClientPortalController@store` → `ClientRequestCreator` | `external_requests` with `client_id` | Tenant + client workspace, from the session | `/agency/requests` | in-app to the assignee, `action_url` `/agency/requests/{id}` (`RequestNotifier.php:34`) | same as above |
| `/contact`, the «تواصل معنا» panel on `/login` | `PublicInfoPage` | `POST /contact` | `PublicIntakeController@contact` | `ContactMessage`, `contact_messages` | Platform (no tenant column) | **none**: API `GET /admin/legal/contact-messages` has no consumer | **none** | none |
| `/support` | `PublicInfoPage` | `POST /support/tickets` | `PublicIntakeController@support` | `SupportTicket`, `support_tickets` | Platform, optional `tenant_id` of a signed-in sender | **none**: API only | **none** | none |
| `/data-deletion`, `/data-requests`, the Meta callback | `DataDeletionPage` | `POST /data-deletion`, `/data-requests`, `/webhooks/data-deletion/{provider}` | `DataDeletionController`, `PublicIntakeController@dataRequest` | `DataRequest`, `data_requests` | Platform | **none**: API only | code to the requester only | operator completes by hand |
| `/f/{slug}` | `PublicFormPage` | `POST /public/forms/{slug}/submissions` | `PublicLeadFormController@submit` → `SubmitLeadForm` | `LeadFormSubmission`, `Lead` | Tenant + project, from the form | `/app/leads` (no rail entry; no `/agency/leads` route) | `lead_sla` alert | lead → opportunity |
| `/invite/accept` | `InviteAcceptPage` | `POST /invitations/accept`, `/accept-existing` | `InvitationController` → `InvitationService` | `workspace_invitations` → membership | the inviting tenant | `/agency/team`, `/app/settings/permissions` | invitation mail | membership, or a client-portal user |
| add-on request, signed in | subscriptions page | `POST /subscriptions/addons/{code}/request` | `AddonController@request` | `tenant_addons` | Tenant → platform decision | `/admin/addons` | none found | add-on activated |
| provider webhooks | none | `POST /webhooks/{kind}/{provider}` | `IntegrationWebhookController@receive` | `integration_webhook_events` | resolved from the signed payload | none: API `GET /admin/webhook-events` has no consumer | none | sync, Salla install |
| ad-platform OAuth return | none | `GET /oauth/ads/{provider}/callback` | `AdPlatformOAuthController@callback` | provider connections | tenant and user from the single-use `state` | `/app` or `/agency` integrations | `token_expiring` alerts | accounts bound to a project |
| `/r/{token}` | `PublicReport` | `GET /reports/shared/{token}…` | `PublicReportController` | `report_shares`, access log | the share's tenant | `/agency/reports` | none | outbound, not an intake |

Not present in the code: inbound WhatsApp or Twilio messages (Twilio is Verify only; WhatsApp is an opt-in for outbound
notices). Provider lead ingestion exists as `Integrations/Leads/IngestProviderLeads.php` with no caller outside tests and
no implementer of `ReportsLeads`, so it is not a live path.

### The `#services` section, button by button

Read live on 2026-11-11 from the public catalogue (`GET /public/catalog/paid-media-services`, `GET /requests/meta`):
10 categories, 95 services, 10 request types open, `influencer_ugc` announced as coming soon and not submittable.

| Element | Leads to | Record | Where the Owner sees it |
|---|---|---|---|
| «اطلب خدمة» in the section, the header and the closing block | `/requests/new` | `external_requests` | nowhere: refused at submit in Production |
| «تصفّح كل الخدمات» | `/services` | none, read only | not applicable |
| a category card, then a service | `/requests/new?module=paid-media&services=KEY` | `external_requests`, `request_services` | nowhere: same refusal |
| «علاقات المؤثرين وUGC — قريبًا» | not a link, by design | none | not applicable |
| «ابدأ الآن» | `/register` | `registration_requests` | `/admin/registrations` |
| contact in the footer | `/contact` | `contact_messages` | nowhere: no screen |

## 4. Ownership and RACI

R receives and works it, A approves or decides, C is consulted, I is informed.

| Record type (contract §2) | Required owner | Owner today | R | A | I | Gap |
|---|---|---|---|---|---|---|
| `platform_registration` | Platform | Platform | platform administrator | platform administrator | applicant | administrators are not notified; the console counter is wrong |
| `platform_service_request` | Platform | **does not exist**; such a request becomes a tenant's | nobody | nobody | applicant | P0-1 |
| `tenant_service_request` | one tenant | that tenant, by `portal_domain` | tenant team | tenant manager | applicant | no tenant domain exists in Production; team not told at receipt |
| `client_portal_request` | tenant + client workspace | same | assignee, agency team | agency manager | client | none found |
| `lead_form_submission` | tenant + project | same | form owner or pool | project manager | none | the leads screen is not on any rail |
| `influencer_service_request` | Platform | not openable (`influencer_ugc` is coming soon) | nobody | nobody | none | depends on P0-1 |
| `influencer_nomination`, `collaboration` | tenant, client for a collaboration | same | agency operator | client or manager | creator | portal off |
| contact, support, data request | Platform | Platform, unread | nobody | nobody | nobody | P0-3 |

## 5. Proven gaps, ranked by impact × likelihood

Only what a test or a Production read shows. Nothing here is assumed.

| # | Priority | Gap | Evidence | Impact | Likelihood | Matrix row |
|---|---|---|---|---|---|---|
| 1 | P0 | A service request from the official site has no platform owner. It is filed under the `is_default_portal` tenant and listed in that subscriber's inbox | `SaasStructureRoutingContractTest::test_s01_…` stops after asserting exactly that; `PortalTenantResolver.php:19-40`; `RequestIntake.php:39` | a visitor's contact details and brief delivered to a subscriber agency; the Owner never sees his own service orders | dormant today, certain the day any tenant is flagged default | PLATFORM-SERVICE-INBOX-001 |
| 2 | P0 | The same fallback takes a request arriving on a host that is neither the official site nor any tenant's domain | `test_s10_an_unknown_host_is_refused_rather_than_given_to_the_default_tenant` stops after asserting it; the edge accepts any Host (`infrastructure/nginx/frontend.conf:4`, `server_name _`) | wrong owner without any forgery | same as 1 | PLATFORM-SERVICE-INBOX-001 |
| 3 | P0 | Every «اطلب خدمة» button is a dead end in Production | Production: 9 tenants, 0 with `is_default_portal`, 0 with a `portal_domain`; `external_requests` 0 rows, `request_access_tokens` 0, since launch | every service lead from the site is lost after the visitor verified a phone and an address | happening now | PLATFORM-SERVICE-INBOX-001 |
| 4 | P0 | Platform-addressed messages are stored and never surfaced | `test_a_message_addressed_to_the_platform_tells_a_platform_administrator` stops at 0 notifications; `routes/api/admin.php:342-347` has no frontend consumer; `AdminShell.tsx:61-101` has no entry; Production: 1 contact message `new` since 2026-08-13, 1 support ticket `open` and unassigned since 2026-11-06, no `app_notifications` source and no `mail_deliveries` kind for any of them | enquiries, support and data-subject requests go unanswered; a deletion request has a legal clock | happening now | PLATFORM-INBOX-ATTENTION-001 |
| 5 | P0 | The console's «registrations pending» counter cannot be anything but zero | `test_the_registrations_badge_counts_what_is_actually_waiting_for_review` stops at 0; `PlatformOverviewController.php:179-180` counts `pending_review` and `info_requested`, `AccountState.php:29-30` names it `pending_approval` | an application waiting for a decision is invisible on the home screen | every application under an approval policy | PLATFORM-INBOX-ATTENTION-001 |
| 6 | P1 | The application sees the proxy's address, not the visitor's | no trusted-proxy configuration anywhere in `backend/bootstrap`, `app` or `config`; Production: 2573 of 2573 share access-log rows in 30 days carry a private-range address, 3 distinct values; both stored contact and support rows the same | every per-address throttle is one bucket shared by all visitors (5 contact messages a minute for the whole site); access logs cannot tell viewers apart | happening now | EDGE-CLIENT-IP-001 |
| 7 | P1 | A refused intake leaves no diagnostic record | `PublicRequestController@store` aborts 404 with no log line; contract decision tree asks for one | the dead end in row 3 went unnoticed for three months | happening now | PLATFORM-SERVICE-INBOX-001 |
| 8 | P1 | Leads have no inbox on any rail, and none at all in the agency portal | `router.tsx:513` is under `/app` only; `agencyNav.ts` and `appNav.ts` carry no entry; Production: 1 lead `new` with no owner | a lead from a published form waits unseen | each submission | existing FORMS-HUB rows; Batch E |
| 9 | P2 | The plan is stored twice and the two disagree | `tenants.subscription_plan` against `subscriptions.plan_id`; Production: tenants `agency_2026` 2 against 1 subscription, `enterprise_2026` 1 against 0, `trial` 4 with no such plan | the console's by-plan figures are not the billing truth | present now | Batch C |

### Probed and **not** found

| Suspected | Result | Evidence |
|---|---|---|
| Cross-tenant read or write of a request by id | not reproduced: 11 routes answer 404, nothing changed, no reference or contact in the body | `test_s04_another_tenants_request_is_not_found_on_every_internal_route` |
| `ExternalRequest` has no global tenant scope, so some reader leaks | 26 call sites read; every tenant-facing one is keyed by tenant, by a tenant-derived client list or by a token's own tenant. The cross-tenant readers are deliberate and none returns a row to a tenant: the platform overview's count, the console SLA sweep, the administrator's contact counters (`AuthCodeSwitches.php:241,255`), the backfill and demo-removal commands, and the client sign-in lookups keyed by the caller's own address (`ClientPortalController.php:186,212`, `PasswordResetService.php:126`) | `Internal/RequestsController.php:307`, `RequestActionsController.php:263`, `RequestJourneyController.php:43`, `ClientPortalController.php:1677,1967`, `BillingReach.php:84`, `PlatformOverviewController.php:69`, `Console/EvaluateSla.php:29` |
| A forwarded-host header selects a tenant | not reproduced: the header is not trusted | `test_s10_a_forwarded_host_header_cannot_select_another_tenant` |
| A tracking token reaches another request or its files | not reproduced | `test_a_tracking_token_reaches_its_own_request_only`, `RequestIntakeTest::test_token_is_stored_hashed_not_in_plaintext` |
| `Tenant::first()` or an environment id used as owner in Production | absent: the environment id is honoured outside Production only | `PortalTenantResolver.php:35-40`, `RequestPortalResolverTest` |
| Invitation claimed by another address | refused | `AgencyProvisioningTest::test_the_invitation_cannot_be_claimed_by_the_wrong_person`, `InvitationService.php:219` |
| Replayed or forged payment event activates or charges twice | refused | `PaymentActivationSecurityTest` (20), `PaymentWebhookRefusalTest` (3), `RegistrationDecisionTest::test_replaying_a_waiver_makes_nothing_twice` |
| A tenant session reaches a platform endpoint, or the reverse | refused both ways | `PlatformAccessTest::test_all_three_surfaces_are_closed_to_a_tenant_user`, `LegalIntakeTest::test_a_tenant_user_cannot_read_the_operators_queues`, `test_s05_the_platform_owner_reads_no_tenant_work_without_a_support_visit` |
| A registration provisions two workspaces | not seen | Production: 3 provisioned registrations, 3 distinct tenants; `RegistrationReviewQueueTest::test_an_application_that_already_became_a_workspace_cannot_be_reviewed_again` |

### Blocked on operational evidence

| Item | Why it cannot be closed from here |
|---|---|
| Signed-in isolation walked on Production (S04, S06, S07, S08) | needs two approved accounts in two workspaces; Production has nobody holding two memberships |
| Tenant-domain intake (S03) end to end | no tenant has a `portal_domain`, and the API is served on one host, so the edge mapping of a tenant domain to the API is unproven |
| Live payment webhook (S12) | no gateway has delivered a real event; 1 payment row, `pending` |
| Whether the edge can be told to pass the real client address (row 6) | needs the proxy's configuration on the host, which this batch did not read |

## 6. The 22 scenarios

| # | Status | Evidence | Proving test | Missing |
|---|---|---|---|---|
| S01 | NOT_STARTED | gap 1 and 3 | `SaasStructureRoutingContractTest::test_s01_a_service_request_from_the_official_site_is_platform_owned` (incomplete, proves the gap) | platform owner scope, platform inbox, screen |
| S02 | IMPLEMENTED_NOT_VERIFIED | Production: 16 registrations, 0 service requests | `test_s02_a_registration_is_never_a_service_request_in_any_inbox`, `RegistrationReviewQueueTest` | a signed-in walk of `/admin/registrations` |
| S03 | IMPLEMENTED_NOT_VERIFIED | `PortalTenantResolver.php:23-27` | `test_s03_a_request_on_a_tenants_portal_domain_is_filed_under_that_tenant_only`, `RequestPortalResolverTest::test_resolves_by_matching_portal_domain` | a real tenant domain in Production |
| S04 | IMPLEMENTED_NOT_VERIFIED | 11 routes, 404, nothing leaked | `test_s04_another_tenants_request_is_not_found_on_every_internal_route`, `RequestDashboardTest::test_dashboard_is_tenant_isolated` | Production walk with two workspaces |
| S05 | IMPLEMENTED_NOT_VERIFIED | Production: 2 administrators, 0 memberships; support visits are audited (`support.session.started` 1, `support.refused` 6) | `test_s05_…`, `PlatformConsoleTest::test_a_tenants_detail_shows_access_but_not_its_work`, `SupportAccessTest` (7) | a signed-in walk |
| S06 | IMPLEMENTED_NOT_VERIFIED | probes enumerate every client endpoint | `ClientPortalIsolationProbeTest` (3), `ClientPortalWriteIsolationTest`, `ClientSpaceIsolationTest` (9) | Production walk with two client users |
| S07 | IMPLEMENTED_NOT_VERIFIED | lists, counts and conversion honour the client ceiling | `InternalRequestsClientCeilingTest` (5), `AgencyTeamScopesTest`, `TenantLevelListsHonourProjectReachTest`, `PortfolioScopeTest` | exports and notifications for a two-client manager, walked live |
| S08 | IMPLEMENTED_NOT_VERIFIED | explicit switch, next request scoped | `WorkspaceSwitchIsolationTest` (17), `PortalDistinctnessTest::test_switching_to_someone_elses_membership_is_refused` | nobody in Production holds two memberships |
| S09 | PARTIAL | refuses 404 and writes nothing; Production shows exactly this state | `test_s09_with_no_owner_the_intake_refuses_and_writes_nothing`, `RequestPortalResolverTest::test_fails_closed_when_no_portal_is_configured` | the reason is not logged (gap 7) |
| S10 | PARTIAL | forwarded header ignored; unknown host accepted into the default tenant | `test_s10_a_forwarded_host_header_cannot_select_another_tenant` (pass), `test_s10_an_unknown_host_is_refused_…` (incomplete) | gap 2 |
| S11 | IMPLEMENTED_NOT_VERIFIED | second submit 422, one row, one notice | `test_s11_resubmitting_the_same_verified_form_does_not_open_a_second_request`, `LeadFormsTest::test_one_submission_id_is_one_lead_however_many_times_it_arrives` | none at feature level |
| S12 | IMPLEMENTED_NOT_VERIFIED | forged, replayed, short, wrong-currency and misattributed events settle nothing | `PaymentActivationSecurityTest`, `PaymentWebhookRefusalTest`, `PaymentReconcileTest` | a real gateway event: BLOCKED_EXTERNAL_CREDENTIALS for the live half |
| S13 | IMPLEMENTED_NOT_VERIFIED | address must match and be verified; expired and reused tokens refused | `AgencyProvisioningTest::test_the_invitation_cannot_be_claimed_by_the_wrong_person`, `WorkspaceInvitationTest::test_expired_and_reused_tokens_are_rejected` | none at feature level |
| S14 | IMPLEMENTED_NOT_VERIFIED | files, exports and links addressed by a foreign id are refused | `RequestUploadTest` (7), `ClientFilesActivityTest`, `DriveAndFilesLibraryClientCeilingTest`, `ReportLinkCeilingIsolationTest`, `SharedLinkDownloadTest`, `AShareLinkIsNotAnAssetTest` | open pull request #964 (password links' files) is the known remaining case |
| S15 | IMPLEMENTED_NOT_VERIFIED | a creator sees only their own offers; fee and margin need their own permission | `CreatorPortalTest` (22), `InfluencerPortalTest` (15) | the portal is off in Production |
| S16 | NOT_STARTED | no platform-owned influencer request can exist; the type is not openable | `RequestIntakeTest::test_a_service_that_is_only_announced_still_cannot_be_submitted` | follows S01 |
| S17 | IMPLEMENTED_NOT_VERIFIED | the notice links to the request inside the reader's own portal | `NotificationActionUrlGuardTest::test_no_notification_links_a_reader_out_of_their_own_portal`, `ClientPortalCreateRequestTest::test_a_client_opens_a_request_that_the_agency_inbox_reads_as_its_own` | Production has no client request yet |
| S18 | PARTIAL | delivery states are stored honestly (`awaiting_provider_credentials`, `failed`); the public intake answer hard-codes `email_delivery: awaiting_credentials` whatever the mailer's state (`PublicRequestController.php:168`) | `WorkspaceInvitationTest::test_invite_creates_a_pending_invitation_with_honest_delivery`, `ClientRequestMailTest` | the hard-coded answer |
| S19 | IMPLEMENTED_NOT_VERIFIED | legacy paths redirect inside the same guard | `frontend/src/app/legacyRedirects.test.tsx`, `docs/ROUTE_REDIRECT_MAP.md` | none found |
| S20 | PARTIAL | suspension blocks access and deletes nothing | `SuspendedAccountTest` (6), `PlanMigrationTest`, `PlatformConsoleTest::test_suspending_the_public_portal_tenant_says_so` | no test states that requests and client links survive a downgrade |
| S21 | VERIFIED | Production runs 43040097104 and 43042874020: `external_requests` 0, `request_access_tokens` 0, `request_conversions` 0. There is no historical request whose owner could be ambiguous, so nothing is reassigned and nothing needs to be | none needed | re-read before Batch B deploys |
| S22 | NOT_STARTED | no platform inbox, no assignee, no notice | `test_a_message_addressed_to_the_platform_tells_a_platform_administrator` (incomplete) | follows S01 and gap 4 |

## 7. Production facts used (counts and statuses only)

| Table | Total | By status | Oldest unhandled |
|---|---|---|---|
| `registration_requests` | 16 | rejected 7, mobile_verification_required 3, active 3, approved_awaiting_payment 2, email_verification_required 1 | mobile verification since 2026-08-14 |
| `contact_messages` | 1 | new 1 | 2026-08-13 |
| `support_tickets` | 1 | open 1, unassigned | 2026-11-06 |
| `data_requests` | 0 | | |
| `external_requests` | 0 | | |
| `leads`, `lead_form_submissions` | 1, 1 | new, no owner | 2026-11-10 |
| `workspace_invitations` | 8 | 6 accepted, 2 failed delivery and never accepted | 2026-08-23 |
| `tenants` | 9 | active 8, suspended 1; all `portal_enabled`, none default, none with a domain | |
| `subscriptions` | 3 | active 3: team 2, agency_2026 1 | |
| `subscription_plans` | 8 | active: solo, team, agency_2026 | |
| influencer tables | 0 | portal off | |

## 8. Batches, tied to the matrix

| Batch | Scope | Rows |
|---|---|---|
| A (this) | contract adopted, this audit, the probes | SAAS-STRUCTURE-AUDIT-001 |
| B | platform owner scope for official-site requests decided on the server, deny by default, refusal logged; a platform «الطلبات» screen; administrators notified at receipt; registrations counter; a read surface for contact, support and data requests. Additive, reversible migrations; no historical row touched | PLATFORM-SERVICE-INBOX-001, PLATFORM-INBOX-ATTENTION-001 |
| C | registration, payment and invitation path: one plan truth, verification dead ends | EXEC-REC-016 and the SIGNUP, PAY rows; gap 9 |
| D | agency and client requests, influencer context, leads kept apart from requests | existing REQUEST, INFL, FORMS-HUB rows; gap 8 |
| E | navigation and names once ownership is proven; legacy paths redirected | NAVIGATION rows, ADR 0002 |
| F | S01–S22 live, Lander, deploy, Production smoke | every row above |

Edge trust (gap 6) is its own row, EDGE-CLIENT-IP-001, and needs the host's proxy configuration read before a change.

## 9. What this batch did not do

No Production row was written, reassigned or deleted. No message, invitation or payment was sent. No existing security
test was changed. The temporary branch `tmp/facts-map` on the remote carries only the read-only workflow and is the
Owner's to delete.
