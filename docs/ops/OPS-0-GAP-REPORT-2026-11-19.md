# OPS-0 — gap report against `origin/main` (2026-11-19)

Blueprint: `CampaignsHub_Agency_Operations_Creative_Launch_Blueprint_2026-11-19.md`. Read from Git (`origin/main` at `59211d7a7`), not from docs or CI. Nothing below is claimed about Production.

## What already exists (do not rebuild)
| Blueprint need | Where it is |
|---|---|
| Public request intake with dynamic fields per chosen service (brief / tracking / content groups) | `frontend/src/features/requests/PaidMediaIntake.tsx`, `paidMediaFields.ts`, `PublicRequestController` |
| Secure tracking link for the client (hashed token, expiry, revocation) | `RequestAccessToken`, `GET /api/v1/requests/track/{token}` |
| Private file uploads for a request | `RequestUploadSession`, `UploadController` |
| Request stages draft → … → completed with an allowed-transition map | `Requests/Journey/RequestStage.php`, `StageStatusMap.php` |
| Internal actions: assign, change status, request information, convert, raise quote, archive | `RequestActionsController` |
| One-shot conversion request → client → project → draft campaign + setup tasks, idempotent, audited | `RequestConversionService` |
| Client portal: requests, approvals, quotes, files, invoices | `frontend/src/features/requests/portal/*` |
| Tasks | `Domains/Tasks` (`TaskSources`) |

## Proven gaps (grep over the files above finds no trace)
1. The brief form had **no completion indicator and no autosave** — a long brief on a phone was lost with the tab. *(closed in OPS-1, this batch)*
2. A **PM-initiated brief link** for a prospect or existing client (the intake is a visitor flow; `request_information` asks for more, it does not issue a brief).
3. **Brief approval with dated versions and a change-lock** after approval (A01 «موافقات وتاريخ تغييرات»).
4. A **«مركز المشاريع»** screen with the four stage counters and one row per project (the stage map exists; the screen does not).

## Chosen: OPS-1, smallest proven gap
Gap 1: completion bar + autosave on this device. Pure functions (`briefProgress.ts`, `briefDraft.ts`), no schema change, no new endpoint, no new system. Gaps 2–4 stay open for the next OPS-1 batches.

## Not started (kept in the matrix)
OPS-2 accounts per platform · OPS-3 visual content gate (builds on CONTENT-LIBRARY-V2-001, #1173) · OPS-4 launch readiness · OPS-5 action-owner notifications · OPS-6 mobile/security/telemetry.
