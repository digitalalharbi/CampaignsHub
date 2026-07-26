<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secure, shareable client report links. The raw token is shown once and only its HASH is stored,
 * so a DB leak cannot reconstruct live links. A share can expire, be revoked, be password-protected,
 * hide sensitive figures, and every view/download is logged. A UUID alone is never the security.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('report_id')->index();
            $table->string('token_hash', 64)->unique();      // sha256 of the raw token (raw shown once)
            $table->string('password_hash')->nullable();     // optional bcrypt password gate
            $table->boolean('allow_download')->default(true);
            $table->boolean('hide_spend')->default(false);
            $table->boolean('hide_revenue')->default(false);
            $table->boolean('hide_campaign_names')->default(false);
            $table->boolean('watermark')->default(false);
            $table->jsonb('settings')->nullable();            // future: ip_allowlist, one_time, email_verify
            $table->unsignedInteger('view_count')->default(0);
            $table->timestampTz('last_viewed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
        });

        Schema::create('report_share_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('share_id')->index();
            $table->string('action');          // view | download | denied
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('detail')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->foreign('share_id')->references('id')->on('report_shares')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_share_access_logs');
        Schema::dropIfExists('report_shares');
    }
};
