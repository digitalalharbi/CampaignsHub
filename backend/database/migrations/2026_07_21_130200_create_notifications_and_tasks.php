<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central, tenant/project/user-scoped notifications (distinct from Laravel's own table).
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('severity')->default('info'); // info|success|warning|critical
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('source')->nullable();
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('action_url')->nullable();
            $table->string('status')->default('unread'); // unread|read|snoozed|resolved
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['user_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('todo'); // backlog|todo|in_progress|waiting_client|blocked|review|completed|cancelled
            $table->string('priority')->default('normal'); // low|normal|high|urgent
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->jsonb('checklist')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('app_notifications');
    }
};
