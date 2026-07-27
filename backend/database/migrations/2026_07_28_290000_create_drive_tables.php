<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Drive content-linking storage. This layer LINKS to Drive content and stores file METADATA only — it
 * never duplicates file bytes. It is honest by construction: a link records the Drive folder id, and files are
 * only ever populated by a provider that reports isConfigured(). Until real Google OAuth credentials are wired
 * the shipped provider is the Null adapter and no file metadata is ever fetched.
 *
 *   - drive_links : a Drive folder linked at a scope (tenant|client|project|campaign). Unique per scope target
 *                   so a scope points at exactly one folder. Tenant-scoped, cascade-deleted with the tenant.
 *   - drive_files : cached file METADATA (Drive File IDs, not bytes) under a link. Upserted idempotently by
 *                   file_id. A file may optionally be attached to a creative/report target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('scope'); // tenant|client|project|campaign
            $table->uuid('scope_id')->nullable();
            $table->string('folder_id'); // the Google Drive folder id — a reference, never file bytes
            $table->string('folder_name');
            $table->uuid('connection_id')->nullable()->index(); // optional ProviderConnection this link resolves through
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'scope', 'scope_id']);
            $table->index(['tenant_id', 'scope']);
        });

        Schema::create('drive_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('drive_link_id');
            $table->string('file_id')->index(); // the Google Drive File ID — we store the reference, not the file
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('thumbnail_link')->nullable();
            $table->string('web_view_link')->nullable();
            $table->timestampTz('modified_time')->nullable();
            $table->string('version')->nullable();
            $table->string('attached_to_type')->nullable(); // e.g. creative|report — the target this file is attached to
            $table->uuid('attached_to_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('drive_link_id')->references('id')->on('drive_links')->cascadeOnDelete();
            $table->unique(['drive_link_id', 'file_id']);
            $table->index(['tenant_id', 'attached_to_type', 'attached_to_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_links');
    }
};
