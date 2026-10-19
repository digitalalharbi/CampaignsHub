# AI Agents — building plan, integrated into the existing system

Source: «نظام وكلاء إدارة الحملات — خطة البناء» (Owner, 2026-10-18). This file is the audit and the delivery
order. It adds requirements to `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` (section «AI Agents plan»); it
does not replace or restate anything already there. Status words are the matrix's; nothing here is
VERIFIED until Production shows it.

## 1. Audit — what already exists, so nothing is built twice

| The plan asks for | What the repository already has | Decision |
|---|---|---|
| `metric_snapshots` (daily per campaign / ad set / ad) | `daily_metrics` (campaign grain) and `entity_daily_metrics` (ad set / ad), written by the hourly sweep, with currency truth and bound-account visibility | **Reuse.** No new snapshot table. |
| Hourly data sync, no AI | `SyncAccountMetricsJob` / structure sweeps on the Laravel scheduler | **Reuse.** |
| `agents` (role, prompt, model, tools) | nothing | New, small: six fixed roles in code, per-tenant overrides in one table. |
| AI provider and key | `AIProviderCredential` (OpenAI / Anthropic / Gemini, encrypted, tenant / client / project scoped, monthly budget, `allowed_models`, `allowed_features`) and `AICredentialController` | **Reuse.** Providers stay configurable; an agent asks for a credential with feature `agents`. |
| `client_rules` (per-client goals and decision rules) | `ProjectSpendLimit`s and alert rules cover spend caps and thresholds; no goals/targets table | New table, project-scoped (a client's rules per project and platform), read by every agent before any decision. |
| `agent_threads` / `agent_messages` | `MessageThread` is human-to-human messaging | New tables; not mixed with human messaging. |
| `routines` | Laravel scheduler + `ScheduledWorkInventory` guard + alert evaluator | Table of routine definitions; execution through the existing scheduler and queues; each registered in the inventory. |
| `action_proposals`, `action_log` | `AuditLogger` records who did what; no proposal/approval concept; **no connector can write** (every `AdvertisingConnector` only reads: `listAdAccounts`, `sync*`) | New, and the heart of the safety model (§3). |
| Alerts, urgent notifications | `AlertEvaluator`, alert events, notification centre, email | **Reuse.** WhatsApp approve/reject needs WhatsApp Business credentials: BLOCKED_EXTERNAL_CREDENTIALS. |
| Weekly client reports in the approved format | report engine, live links, delivery pack, weekly delivery schedule | **Reuse.** The Reports Agent drives this engine; the standard notes block becomes a section of it. |
| Navigation into five areas | `appNav.ts`, `agencyNav.ts`, `navGrouping` guard | Re-grouping only; routes and permissions unchanged. |

## 2. Provider capabilities — verified, not assumed

Read before building any executor. «Our grant» is what this repository's OAuth config actually requests today.

| Provider | Write exists in the provider API? | Our grant (`config/ad_platforms.php`) | Executor |
|---|---|---|---|
| Snapchat | Yes. Campaigns `PUT /v1/adaccounts/{id}/campaigns` (full object) and `PATCH …/campaigns/{campaign_id}` (partial); `status` ACTIVE/PAUSED, `daily_budget_micro` ≥ 20 000 000; RESERVED buy model cannot be modified; DELETE exists but is never used. (developers.snap.com/api/marketing-api/Ads-API/campaigns) | scope `snapchat-marketing-api` (the Marketing API scope; whether the stored token can write must be confirmed with one approved, reversible change on a test account) | After confirmation. The Snapchat **MCP** is read-only; the Marketing API is not the MCP. |
| Meta | Yes (`status`, `daily_budget` on campaign / ad set; new objects start paused by policy) | `ads_read`, `ads_management` | Buildable now. |
| Google Ads | Yes via mutate with the developer token; open-source MCP is read-only, the API is not | `adwords` | Needs developer-token access level confirmed. |
| TikTok | Yes via Business API (`campaign/status/update`, budget update) | scopes empty (app-level permission) | Needs the app's «Ad Account Management» permission confirmed. |
| LinkedIn | Write exists | **read only** (`r_ads`, `r_ads_reporting`) | Would need `rw_ads` and re-consent. |
| X Ads | Write exists | read only | Out of scope until connected. |
| OpenAI Ads | Documented create/update | key-based | Out of scope. |

The plan's open question — «does our Snapchat connection hold write permission?» — is answered by the grant above plus one confirmation call; it is recorded as AGENTS-SNAP-WRITE-CONFIRM-001. External MCP servers (Meta, TikTok, Snap, Google) are an Owner-side Phase-0 convenience for the Owner's own accounts; CampaignsHub does not depend on them.

## 3. Safety model — enforced in code

1. **Agents never call a provider write.** An agent can only create an `action_proposal`.
2. **Approval gateway.** A proposal is executed only after a person with the right permission approves it; the executor re-reads the entity, re-checks every rule, then acts. Status path: `pending → approved → executing → executed | failed`, or `rejected | expired | blocked`.
3. **Hard caps** (data, not prose): per project daily-budget ceiling; maximum single change (default +20 %); decreases and pauses are always allowed; **no delete action exists in the vocabulary**; new campaigns are created paused only.
4. **Idempotency.** One proposal per `(tenant, target, action, payload hash)` while open; the executor carries the proposal id as an idempotency key and writes `action_log` before and after the call.
5. **Isolation.** Every tool resolves through the existing bound-account visibility and tenant scope; a proposal's target must belong to an account actively bound to the proposal's project.
6. **Prompt-injection.** Ad copy, comments and web text are passed to the model as quoted data in a labelled block; tool arguments are validated by schema and by the rules above regardless of what the model says; a model that «decides» something outside the vocabulary produces a blocked proposal, never an action.
7. **Full audit.** Every request and response to a provider, and the reason for the decision, is stored (`action_log`, plus the existing audit log).
8. **Trust ladder** (later): automatic execution only for narrowly defined actions, only after a measured month of accepted proposals, switched on per tenant by an owner.

## 4. Delivery order — each batch is useful alone

| Batch | Content | Matrix rows |
|---|---|---|
| B1 | Approval gateway: `action_proposals`, `action_log`, the rule engine (caps, ceilings, vocabulary), propose / approve / reject API, an approvals queue screen. **No provider write yet** — approval of an action whose provider executor is not enabled ends `blocked` with the reason. | AGENTS-APPROVAL-GATEWAY-001 |
| B2 | `client_rules` and the read-only tools over existing data (`list_campaigns`, `get_metrics`, `get_history`, `compare_targets`). | AGENTS-CLIENT-RULES-001, AGENTS-TOOLS-READ-001 |
| B3 | Agent engine: provider-agnostic client over `AIProviderCredential`, tool loop, threads and messages, injection guard; Analyst and Reports first; the Agent Center screen. | AGENTS-ENGINE-001, AGENTS-CENTER-001 |
| B4 | Provider executors, one at a time, each behind its own verification (Meta, Google, Snapchat, TikTok). | AGENTS-EXEC-* |
| B5 | Routines: daily analysis, budget guard, creative review, weekly report; alert channels. | AGENTS-ROUTINES-001, AGENTS-CHANNELS-001 |
| B6 | Navigation in five areas. | NAV-FIVE-AREAS-001 |
| B7 | Creative and Leads agents. | AGENTS-CREATIVE-001, AGENTS-LEADS-001 |
| B8 | Trust ladder. | AGENTS-AUTONOMY-001 |
| B9 | Client-facing agents product and plan. | AGENTS-CLIENT-PRODUCT-001 |
