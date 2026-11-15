<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIF-V2-ENGINE-001 — the shared notifications engine (docs/notifications-v2/CONTRACTS.md, Owner spec §0, §1, §3, §4).
 *
 * Additive and reversible: new tables only, nothing existing is rewritten or dropped. `down()` drops exactly what `up()`
 * created, in reverse order.
 *
 * ## Tenant isolation
 *
 * The repository isolates tenants in the application layer, not with Postgres row-level-security policies: every
 * tenant-owned row carries `tenant_id`, the model uses `BelongsToTenant` (global `TenantScope` + auto-fill from the
 * request-bound `TenantContext`), and isolation tests prove it. Every tenant-owned table here follows that rule. The
 * two platform tables (`notification_plan_features`, `notification_event_catalog`) hold no tenant data.
 *
 * ## Immutability
 *
 * `notification_delivery_log` and `notification_subscription_log` are the proof of notice. A BEFORE UPDATE OR DELETE
 * trigger refuses any change at the database level. Neither has a foreign key to a tenant, project or recipient, on
 * purpose: a cascade would be a delete, and the proof must outlive what it describes (names are snapshotted).
 *
 * Usage counters reuse the existing `usage_counters` table (tenant, metric, YYYY-MM); no new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Platform: plan features (§1) ────────────────────────────────────────────────────────────────────────
        Schema::create('notification_plan_features', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 80);                       // an EntitlementKey value
            $table->string('plan_code', 60)->nullable();     // null = every plan
            $table->string('workspace_type', 40)->nullable(); // null = every workspace type
            $table->boolean('enabled')->default(true);
            $table->jsonb('limits')->nullable();             // {"external_recipients": 500, ...}; absent = config default
            $table->unsignedSmallInteger('grace_days')->default(14);
            $table->boolean('keep_existing')->default(true); // grandfathering when the key is switched off for a plan
            $table->timestampTz('paid_from')->nullable();    // when the key stopped being free for this plan
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("create unique index notification_plan_features_scope_unique on notification_plan_features (key, coalesce(plan_code, ''), coalesce(workspace_type, ''))");

        // ── Platform: event catalog overrides (§3; defaults live in config/notification_events.php) ──────────────
        Schema::create('notification_event_catalog', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 80)->unique();
            $table->jsonb('overrides');                      // a partial EventDefinition; merged over the config default
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        // ── Workspace: entitlement lifecycle state (§1 grace / grandfathering / view-only) ────────────────────────
        Schema::create('workspace_entitlement_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('key', 80);
            $table->string('mode', 20);                      // grace | grandfathered | view_only
            $table->timestampTz('grace_ends_at')->nullable();
            $table->timestampTz('heads_up_sent_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'key']);
        });

        // ── Workspace: notification settings (§0 layer 2) ───────────────────────────────────────────────────────
        Schema::create('workspace_notification_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('timezone', 64)->nullable();       // null = Asia/Riyadh
            $table->jsonb('work_days')->nullable();           // [0..6], 0 = Sunday; null = profile default
            $table->jsonb('quiet_hours')->nullable();         // {"enabled":bool,"start":"22:00","end":"08:00"}
            $table->jsonb('holidays')->nullable();            // ["2026-12-02", ...]
            $table->jsonb('org_domains')->nullable();         // ["example.com"]
            $table->jsonb('routing')->nullable();             // {event_key: {roles: [...], channels: [...], enabled: bool}}
            $table->jsonb('settings')->nullable();            // the rest of §0 layer 2 (sender, cc/bcc, phrasing, ...)
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // ── Workspace: external recipients (§9) ─────────────────────────────────────────────────────────────────
        Schema::create('notification_external_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('email', 254);
            $table->string('name', 120)->nullable();
            $table->string('salutation', 120)->nullable();    // «اسم المخاطبة», shown literally
            $table->string('role_key', 40)->default('other'); // a WorkspaceTypeProfile role key
            $table->string('language', 5)->default('ar');
            $table->string('display_currency', 3)->nullable(); // null = workspace currency
            $table->boolean('is_role_mailbox')->default(false);
            $table->string('status', 20)->default('pending'); // pending | active | paused | cancelled
            $table->string('consent', 30)->default('none');   // none | double_opt_in | approved_internally
            $table->char('confirmation_token_hash', 64)->nullable();
            $table->timestampTz('confirmation_sent_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('legacy_source', 60)->nullable();  // e.g. external_digest_recipients
            $table->uuid('legacy_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'email']);
            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['tenant_id', 'status']);
        });

        // ── Workspace: subscriptions (§9, §10) ──────────────────────────────────────────────────────────────────
        Schema::create('notification_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->string('status', 20)->default('pending'); // pending | active | paused | cancelled
            $table->string('template_key', 60)->nullable();
            $table->jsonb('digest_types')->nullable();         // ["performance","spend","budgets",...]
            $table->jsonb('event_keys')->nullable();           // external event keys this subscription receives
            $table->string('period_key', 40)->nullable();      // a DigestPeriods key
            $table->string('frequency', 10)->nullable();       // daily | weekly | monthly
            $table->jsonb('weekdays')->nullable();             // [0..6], 0 = Sunday
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->string('send_time', 5)->default('08:00');
            $table->string('timezone', 64)->nullable();        // null = workspace timezone
            $table->string('subject_template', 200)->nullable();
            $table->jsonb('cc')->nullable();
            $table->jsonb('bcc')->nullable();
            $table->boolean('review_mode')->nullable();        // null = inherit (on for client role)
            $table->unsignedSmallInteger('reviews_remaining')->default(3); // first N messages held for review
            $table->jsonb('overrides')->nullable();            // per-subscription overrides of workspace settings
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->string('legacy_source', 60)->nullable();
            $table->uuid('legacy_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('notification_external_recipients')->cascadeOnDelete();
            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'next_run_at']);
        });

        Schema::create('notification_subscription_projects', function (Blueprint $table): void {
            $table->uuid('subscription_id');
            $table->uuid('project_id');
            $table->uuid('tenant_id');

            $table->primary(['subscription_id', 'project_id']);
            $table->foreign('subscription_id')->references('id')->on('notification_subscriptions')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        // ── Workspace: the outbox — mutable work state of a delivery (§4 sending) ─────────────────────────────────
        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reference', 32)->unique();         // human reference, also on the log rows and the message
            $table->string('event_key', 80);
            $table->string('scope', 10);                       // internal | external
            $table->string('channel', 10);                     // in_app | push | email
            $table->string('priority', 10)->default('info');
            $table->string('recipient_kind', 10);              // user | external
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->uuid('recipient_external_id')->nullable();
            $table->uuid('subscription_id')->nullable();
            $table->string('recipient_address', 254)->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('dedup_key', 191)->unique();
            $table->string('group_key', 191)->nullable();
            $table->unsignedSmallInteger('group_count')->default(1);
            $table->jsonb('payload');                          // DeliveryIntent payload (+ grouped objects)
            $table->unsignedInteger('content_version')->default(1);
            $table->string('state', 12)->default('pending');   // pending | claimed | done
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('not_before')->nullable();     // grouping window / quiet hours / back-off
            $table->string('deferred_reason', 40)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['state', 'not_before']);
            $table->index(['tenant_id', 'group_key', 'state']);
        });

        // ── Workspace: the immutable delivery log (§4) ──────────────────────────────────────────────────────────
        Schema::create('notification_delivery_log', function (Blueprint $table): void {
            $table->bigIncrements('seq');
            $table->uuid('id')->unique();
            $table->uuid('tenant_id');
            $table->uuid('delivery_id');                       // notification_outbox.id (kept even after outbox pruning)
            $table->string('reference', 32);
            $table->string('event_key', 80);
            $table->string('scope', 10);
            $table->string('channel', 10);
            $table->string('recipient_kind', 10);
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->uuid('recipient_external_id')->nullable();
            $table->string('recipient_address', 254)->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('project_name', 200)->nullable();   // snapshot
            $table->string('workspace_name', 200)->nullable(); // snapshot
            $table->string('status', 20);                      // a DeliveryStatus value
            $table->string('reason', 120)->nullable();
            $table->unsignedInteger('content_version')->default(1);
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->string('provider_message_id', 190)->nullable();
            $table->timestampTz('provider_accepted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index('delivery_id');
            $table->index('reference');
        });

        // ── Workspace: the immutable subscription log (§4) ──────────────────────────────────────────────────────
        Schema::create('notification_subscription_log', function (Blueprint $table): void {
            $table->bigIncrements('seq');
            $table->uuid('id')->unique();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id')->nullable();
            $table->uuid('subscription_id')->nullable();
            $table->string('action', 40);                      // a SubscriptionLogAction value
            $table->jsonb('detail')->nullable();               // {event_key?, project_id?, from?, to?, count?}
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_label', 120)->nullable();    // snapshot of who (or «system»)
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index('recipient_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                create or replace function notification_log_is_immutable() returns trigger as $$
                begin
                    raise exception 'notification log rows are immutable (% on %)', tg_op, tg_table_name
                        using errcode = 'P0001';
                end;
                $$ language plpgsql;

                create trigger notification_delivery_log_immutable
                    before update or delete on notification_delivery_log
                    for each row execute function notification_log_is_immutable();

                create trigger notification_subscription_log_immutable
                    before update or delete on notification_subscription_log
                    for each row execute function notification_log_is_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                drop trigger if exists notification_subscription_log_immutable on notification_subscription_log;
                drop trigger if exists notification_delivery_log_immutable on notification_delivery_log;
                drop function if exists notification_log_is_immutable();
            SQL);
        }

        Schema::dropIfExists('notification_subscription_log');
        Schema::dropIfExists('notification_delivery_log');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('notification_subscription_projects');
        Schema::dropIfExists('notification_subscriptions');
        Schema::dropIfExists('notification_external_recipients');
        Schema::dropIfExists('workspace_notification_settings');
        Schema::dropIfExists('workspace_entitlement_states');
        Schema::dropIfExists('notification_event_catalog');
        Schema::dropIfExists('notification_plan_features');
    }
};
