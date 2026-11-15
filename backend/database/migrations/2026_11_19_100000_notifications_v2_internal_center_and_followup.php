<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * NOTIF-V2-INTERNAL-001 / NOTIF-V2-FOLLOWUP-001 (Owner spec §5, §6) — additive and reversible: five workspace tables for the
 * notification centre (per-person snooze, per-person mute of one type on one project) and the follow-up assistant (tasks and
 * their history, the morning brief snapshot, the person's brief settings). Nothing existing is altered.
 *
 * Isolation follows the repository convention (CONTRACTS.md §1): every table carries `tenant_id` (FK, cascade) and its model
 * uses `BelongsToTenant`; console code names the tenant explicitly. The history table is append-only by construction (no
 * update or delete path exists in code; the model refuses both).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_user_snoozes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('notification_id');
            $table->timestampTz('snoozed_until');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('notification_id')->references('id')->on('app_notifications')->cascadeOnDelete();
            $table->unique(['notification_id', 'user_id']);
            $table->index(['user_id', 'snoozed_until']);
        });

        Schema::create('notification_mutes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('project_id');
            $table->string('kind', 60);                        // a bell kind or an engine event key
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['user_id', 'project_id', 'kind']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('followup_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('project_id')->nullable();
            $table->string('kind', 40);                        // renew_budget | set_budget | relink_platform | review_held_message | decide_recommendation
            $table->string('reason', 255);                     // the sentence, in the workspace's default language (Arabic); the UI re-renders by `kind`
            $table->jsonb('params')->nullable();               // {days, until, ...} the UI renders the reason from
            $table->string('event_key', 80)->nullable();       // the catalog event that opened it
            $table->string('object_type', 40)->nullable();
            $table->string('object_id', 64)->nullable();
            $table->string('dedup_key', 120);                  // one OPEN task per (tenant, kind, project, object)
            $table->date('due_on')->nullable();
            $table->string('status', 12)->default('open');     // open | snoozed | done | dismissed
            $table->timestampTz('snoozed_until')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_url', 255)->nullable();     // portal-relative; the button to the exact page
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_how', 12)->nullable();      // auto | manual
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'due_on']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['assigned_user_id', 'status']);
        });
        // One open task per fact; a closed one may be followed by a new one when the fact returns.
        DB::statement("create unique index followup_tasks_open_unique on followup_tasks (tenant_id, dedup_key) where status in ('open', 'snoozed')");

        Schema::create('followup_task_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_id');
            $table->string('action', 20);                      // opened | assigned | snoozed | closed | reopened
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete(); // null = the system
            $table->string('actor_label', 120)->nullable();    // snapshot: a name, or «system»
            $table->jsonb('detail')->nullable();               // {to_user, until, reason, how}
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('followup_tasks')->cascadeOnDelete();
            $table->index(['task_id', 'created_at']);
        });

        Schema::create('followup_briefs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('brief_date');                        // the workspace's calendar day
            $table->jsonb('sections');
            $table->string('summary', 500);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id', 'brief_date']);
        });

        Schema::create('followup_user_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('brief_enabled')->default(true);
            $table->string('brief_time', 5)->default('08:00');  // the workspace timezone's clock
            $table->jsonb('brief_channels')->nullable();        // ["in_app","email"]; null = in_app only
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['followup_user_settings', 'followup_briefs', 'followup_task_events', 'followup_tasks', 'notification_mutes', 'notification_user_snoozes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
