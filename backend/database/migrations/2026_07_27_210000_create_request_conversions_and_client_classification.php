<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional conversion ledger + client classification. A request converts to a client/project/
 * draft-campaign exactly once (unique per request); the idempotency key guards retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_conversions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUlid('external_request_id')->constrained('external_requests')->cascadeOnDelete();
            $table->uuid('client_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('status')->default('pending'); // pending | completed | failed
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->json('failure_context')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'external_request_id']);
            $table->unique(['tenant_id', 'idempotency_key']);
        });

        // Client classification on the existing client workspace.
        Schema::table('client_workspaces', function (Blueprint $table): void {
            $table->string('client_status')->default('active')->after('status'); // prospect|onboarding|active|needs_attention|paused|completed|archived
            $table->string('service_level')->nullable()->after('client_status');  // managed_service|consulting|reporting_only|analytics_only|self_service
            $table->string('industry')->nullable()->after('service_level');        // e_commerce|lead_generation|...
            $table->string('client_source')->nullable()->after('industry');        // e.g. request_portal
            $table->foreignId('owner_id')->nullable()->after('client_source')->constrained('users')->nullOnDelete();
            $table->ulid('source_request_id')->nullable()->after('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_workspaces', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['client_status', 'service_level', 'industry', 'client_source', 'source_request_id']);
        });
        Schema::dropIfExists('request_conversions');
    }
};
