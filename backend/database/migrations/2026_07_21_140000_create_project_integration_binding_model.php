<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full per-project source-binding model:
 * Tenant → Client Workspace → Project → ProjectIntegrationBinding → ExternalAccount →
 * ProviderConnection → IntegrationCredential (encrypted).
 *
 * A connection can discover many external accounts; an account can bind to several projects (when
 * allowed); detaching a binding never revokes OAuth; revoking a connection disables all its bindings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('provider');
            $table->string('credential_scope');   // tenant_shared|workspace_shared|client_shared|project_only
            $table->string('credential_type');    // oauth|api_key|service_account
            $table->text('encrypted_payload');    // encrypted at rest; NEVER returned by API
            $table->string('status')->default('active');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_rotated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('provider_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('credential_id');
            $table->string('provider');
            $table->string('connection_name');
            $table->string('scope')->default('project_only'); // tenant_shared|workspace_shared|client_shared|project_only
            $table->string('external_owner_id')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->string('status')->default('connected'); // connected|revoked|error|awaiting_credentials
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('last_health_check_at')->nullable();
            $table->timestampTz('last_successful_sync_at')->nullable();
            $table->string('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('credential_id')->references('id')->on('integration_credentials')->cascadeOnDelete();
        });

        Schema::create('external_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('provider_connection_id');
            $table->string('provider');
            $table->string('account_type'); // ad_account|business_account|pixel|dataset|analytics_property|tag_manager_account|tag_manager_container|ecommerce_store|conversion_source
            $table->string('external_id');
            $table->string('parent_external_id')->nullable();
            $table->string('name');
            $table->string('currency', 3)->nullable();
            $table->string('timezone')->nullable();
            $table->string('status')->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('provider_connection_id')->references('id')->on('provider_connections')->cascadeOnDelete();
            $table->unique(['provider_connection_id', 'external_id', 'account_type']);
        });

        Schema::create('project_integration_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id');
            $table->string('provider');
            $table->string('purpose'); // advertising|analytics|tag_management|ecommerce|tracking|conversion_api|reporting
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('sync_enabled')->default(true);
            $table->string('sync_frequency')->default('daily');
            $table->boolean('reporting_enabled')->default(true);
            $table->boolean('campaign_management_enabled')->default(false);
            $table->boolean('tracking_enabled')->default(false);
            $table->jsonb('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            // An account is bound to a project at most once per purpose.
            $table->unique(['project_id', 'external_account_id', 'purpose']);
        });

        Schema::create('integration_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('binding_id')->nullable();
            $table->uuid('provider_connection_id')->nullable();
            $table->string('type')->default('campaigns'); // campaigns|insights|creatives|accounts
            $table->string('status')->default('pending'); // pending|running|success|failed
            $table->unsignedInteger('records')->default(0);
            $table->string('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('binding_id')->references('id')->on('project_integration_bindings')->nullOnDelete();
        });

        Schema::create('external_entity_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('external_account_id');
            $table->string('entity_type'); // campaign|adset|ad|creative|order|product|...
            $table->string('external_id');
            $table->string('internal_type')->nullable();
            $table->uuid('internal_id')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            $table->unique(['external_account_id', 'entity_type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_entity_mappings');
        Schema::dropIfExists('integration_sync_runs');
        Schema::dropIfExists('project_integration_bindings');
        Schema::dropIfExists('external_accounts');
        Schema::dropIfExists('provider_connections');
        Schema::dropIfExists('integration_credentials');
    }
};
