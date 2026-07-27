# Files, Audit & Permissions — Integration Map

> For building Files / Activity / Team / Classification / Settings on EXISTING layers. Verified against code.

## Existing File Storage (reuse)
- `app/Domains/Requests/Models/RequestFile.php` → `request_files`: `disk`(default local, PRIVATE),
  `path`, `original_name`, `mime`, `size`, `uploaded_by`, `is_client_visible` (bool, default true),
  `checksum` char(64) sha256, `upload_session_id`. `request_id` nullable (file can precede request).
- Report files: `app/Domains/Reports/Models/ReportExport.php` → `report_exports` (`format,disk,path,size,
  signed_token,expires_at` + provenance). Separate signed-token+expiry model.
- Creatives: `external_creatives` store URL refs (thumbnail/preview/destination), not private-disk binaries.
- **No dedicated client-files model.** Client files today flow in via RequestFile (through conversion).

## Secure Download Flow (pattern to follow — controller/token gated, NOT signed URLs for request files)
- `PublicRequestController::downloadFile($token,$file)` → resolve request by tracking token
  (sha256 in `request_access_tokens`, checks revoked_at/expires_at), then
  `$req->files()->where('is_client_visible',true)->find($file)` → `abort_if(null,404)` (non-revealing) →
  `Storage::disk($disk)->download($path,$original_name)`. Storage path NEVER exposed.
- Internal (staff) downloads must be a separate authenticated+permissioned controller that streams the same
  way but is gated by `clients.manage_files` and client-tenant ownership (client-visible AND internal files).

## Visibility Model
- `is_client_visible` on RequestFile is the switch. Client-facing surfaces filter `is_client_visible=true`;
  internal surfaces show all but never leak `path`/`disk`.

## Audit / Activity Sources (reuse — do not build a new audit engine)
- `app/Domains/Audit/Models/AuditLog.php` (append-only; `const UPDATED_AT=null`): `tenant_id,user_id,action,
  entity_type,entity_id,before,after,ip_address,user_agent,correlation_id,reason`. Written via
  `app/Domains/Audit/AuditLogger.php::log(action,entityType,entityId,before,after,reason,userId,tenantId)`.
- `request_events` (per request; `type,from_status,to_status,actor_id,is_client_visible,message,meta`).
- `request_conversions` ledger (`status,started_by,completed_by,started_at,completed_at,client_id,project_id,campaign_id`).
- **Client Activity timeline** = read model over `audit_logs` (filtered by tenant + entity refs tied to the
  client: the client itself, its projects, campaigns, requests) UNION `request_events` for the client's requests.
  No new activity table; a query/service assembles it from these real events.

## Current Roles and Permissions
- `PermissionSeeder` groups: `clients.{view,create,update,delete}`, `reports.{view,create,export,share,approve}`,
  `requests.{view,view_all,update,assign,change_status,change_priority,comment_internal,comment_client,
  request_information,manage_files,manage_sla,archive,convert}`, plus projects/campaigns/analytics/etc.
- Enforcement: inline `abort_unless($request->user()?->hasPermission('slug'), 403)` per action. No Policy classes yet.
- `hasPermission()` in `HasRoles` trait: platform-admin bypass, else `permissionKeys()->contains($key)`.
- Demo `tenant-owner` role gets ALL permissions (`givePermissionTo(Permission::pluck('key'))`).

## Client Access Model
- `client_workspace_user` pivot: `client_workspace_id,user_id,client_role` (default `client_viewer`;
  existing values `client_admin|client_approver|client_viewer`). `ClientWorkspace::members()` BelongsToMany.
- Spec requires richer client roles (Client Owner/Media Buyer/Analyst/Reporter/Viewer/Custom) + per-project
  restriction + backend enforcement (a user without client access must be denied at API, not just hidden).

## Missing Endpoints (to add, delegating to existing layers)
- clients.update classification; clients settings; clients archive/restore.
- client analytics (delegate MetricsAggregator::forProjects); client reports (delegate report services).
- client files list + secure internal download; client activity timeline read; client team grant/change/remove.
- New permission slugs: `clients.{archive,restore,manage_team,manage_files,manage_settings,view_analytics,
  view_reports,create_reports,share_reports}`.
