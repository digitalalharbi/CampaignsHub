<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-scope AI provider credentials (BYOK). Secrets are stored ENCRYPTED (Laravel encrypted cast)
 * and never returned by the API — only a masked `last_four` is exposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('provider');                    // openai|anthropic|gemini
            $table->string('credential_scope');            // platform|tenant|client|project
            $table->text('encrypted_secret');              // encrypted at rest; never returned
            $table->string('last_four', 8)->nullable();    // safe masked hint
            $table->string('organization_id')->nullable();
            $table->string('project_external_id')->nullable();
            $table->string('status')->default('inactive'); // inactive|active|invalid|disabled
            $table->decimal('monthly_budget', 12, 2)->nullable();
            $table->unsignedBigInteger('monthly_token_limit')->nullable();
            $table->jsonb('allowed_models')->nullable();
            $table->jsonb('allowed_features')->nullable();
            $table->timestampTz('last_health_check_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('rotated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('client_workspace_id')->references('id')->on('client_workspaces')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_credentials');
    }
};
