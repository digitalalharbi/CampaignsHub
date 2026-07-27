<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External Request Portal — core schema. An external (public) request is captured with a secure
 * tracking token, flows through a status lifecycle with an event timeline, carries internal vs
 * client-visible comments/files, and can later convert into a client/project/campaign.
 *
 * Tenant model: external intake is public (tenant_id resolved from the portal's target tenant), so
 * ExternalRequest is scoped explicitly in internal controllers rather than via a global TenantScope,
 * which would hide public token lookups. Isolation is asserted by tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();          // e.g. paid_campaign_launch
            $table->string('module');                 // paid_media | analytics | tracking | reporting | integration | consulting | influencer_marketing | custom
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('request_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();           // new | under_review | waiting_client | qualified | approved | in_progress | completed | rejected | cancelled | archived
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('pauses_sla')->default(false);   // e.g. waiting_client
            $table->boolean('is_client_visible')->default(true);
        });

        Schema::create('external_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('reference')->unique();       // REQ-YYYY-XXXXXX
            $table->string('module')->default('paid_media');
            $table->foreignId('type_id')->constrained('request_types');
            $table->foreignId('status_id')->constrained('request_statuses');
            $table->string('priority')->default('medium'); // critical|high|medium|low
            $table->string('source')->default('public_portal');

            $table->uuid('client_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('campaign_id')->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone', 32)->nullable();
            $table->string('company_name')->nullable();
            $table->text('objective')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->string('currency', 8)->default('SAR');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestampTz('sla_due_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();

            $table->boolean('is_external')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->json('metadata')->nullable();        // dynamic per-type answers (form tables land next)
            $table->timestampsTz();

            $table->index(['tenant_id', 'status_id']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('request_id')->constrained('external_requests')->cascadeOnDelete();
            $table->string('type');                      // created | status_changed | assigned | comment | file | info_requested | converted
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_client_visible')->default(false);
            $table->string('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('request_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('request_id')->constrained('external_requests')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_label')->nullable();  // for client-submitted comments (no user)
            $table->string('visibility')->default('internal'); // internal | client
            $table->text('body');
            $table->timestampsTz();
            $table->index(['request_id', 'visibility']);
        });

        Schema::create('request_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('request_id')->constrained('external_requests')->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_client_visible')->default(true);
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('request_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('request_id')->constrained('external_requests')->cascadeOnDelete();
            $table->string('token_hash')->unique();      // sha-256 of the plaintext token (shown once)
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_access_tokens');
        Schema::dropIfExists('request_files');
        Schema::dropIfExists('request_comments');
        Schema::dropIfExists('request_events');
        Schema::dropIfExists('external_requests');
        Schema::dropIfExists('request_statuses');
        Schema::dropIfExists('request_types');
    }
};
