<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secure temporary uploads: files can be attached BEFORE the request exists, via an expiring upload
 * session. On intake submit the session's files are associated to the new request; orphaned sessions
 * (never submitted) are cleaned up. Files live on a PRIVATE disk under a UUID name — the storage path
 * is never exposed; downloads go through a policy/token, never a public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_upload_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('token_hash')->unique();      // sha256 of the plaintext upload token
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('created_at')->nullable();
        });

        Schema::table('request_files', function (Blueprint $table): void {
            // A file may belong to a temp session first (request_id null), then to a request on submit.
            $table->ulid('request_id')->nullable()->change();
            $table->foreignUlid('upload_session_id')->nullable()->after('request_id')
                ->constrained('request_upload_sessions')->nullOnDelete();
            $table->string('checksum', 64)->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('request_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('upload_session_id');
            $table->dropColumn('checksum');
        });
        Schema::dropIfExists('request_upload_sessions');
    }
};
