# Option Management Spec — central Taxonomy & Option Engine

Replaces per-page hardcoded option arrays with one tenant-aware, permissioned, auditable engine. No data loss;
system keys are immutable (only display label/translation/color/active toggle allowed for system options).

## Data model
`taxonomy_definitions` (a classification field):
`key` · `module` · `scope[platform|tenant|client|project|module]` · `field_type[single|multi|hierarchical]` ·
`label_ar` · `label_en` · `description` · `is_system` · `is_active` · `allows_custom_options` · `allows_multiple` ·
`maximum_selections` · `sort_order` · `tenant_id` (null=platform).

`taxonomy_options` (a value):
`key` · `taxonomy_definition_id` · `label_ar` · `label_en` · `description` · `color` · `icon` ·
`parent_option_id` · `sort_order` · `is_default` · `is_active` · `is_system` · `tenant_id` · `metadata` jsonb ·
`usage_count` (derived).

Scoping: platform options visible to all; tenant/client/project options visible only within their owner; a
tenant NEVER sees another tenant's options (global-scope fail-closed).

## Service (TaxonomyService)
`options(key, scope-context)` → effective option set (platform ∪ tenant, active, sorted, hierarchical) ·
`createOption` · `updateOption` (system → label/translation/color/active only) · `reorder` · `setDefault` ·
`deactivate` · `createChild` · `merge(from,into)` (reassign records + soft-retire `from`) ·
`reassign(from,into)` · `usage(option)` (count across bound records).

## Delete protection
A used option cannot be hard-deleted. The API returns its `usage_count` and requires **Merge / Reassign /
Deactivate**. System-sensitive sets (Workflow Statuses, Payment Statuses, System Roles, Security States) keep
their internal key + transition rules; only display label/translation/active may change.

## API (tenant-scoped, permissioned)
`GET /taxonomies` · `GET /taxonomies/{key}/options?scope=…` · `POST /taxonomies/{key}/options` ·
`PATCH /options/{id}` · `POST /options/reorder` · `POST /options/{id}/merge` · `POST /options/{id}/reassign` ·
`POST /options/{id}/deactivate` · `GET /options/{id}/usage`.

## Permissions
`taxonomies.view` · `taxonomies.manage` · `options.create` · `options.update` · `options.reorder` ·
`options.merge` · `options.deactivate` · `options.manage_system_labels`. Without them: user sees + selects
options but no add/manage affordance and the write API is denied. Every mutation writes an Audit entry.

## Seed (canonical, from existing hardcoded lists — no behavior change)
request services/categories/types/objective/priority/status/sla/payment_status/source · client
status/service_level/industry/priority/source/tags · campaign objective/platforms/regions/audiences/
conversion_events/creative_types/tags · integration category/provider · report/alert/content/file categories.
Keys preserved from current values so existing records + reports + filters keep working.

## Migration (safe)
read existing values → map to canonical option keys → preserve unknown values as tenant options →
create legacy mapping → verify counts → switch reads → switch writes. No silent replacement, no deletes.
