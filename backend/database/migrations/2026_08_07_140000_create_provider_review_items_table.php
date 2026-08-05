<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEW-001 — what the operator has declared about a platform review this application cannot see.
 *
 * Only `declared` requirements land here. The `derived` ones — the redirect URI we will actually
 * send, whether a secret is present, which scopes the connector asks for — are answered from the
 * system itself on every read, and storing them would create a second copy that goes stale the
 * moment a URL changes.
 *
 * Not tenant-scoped: an OAuth app belongs to the platform operator, and its review status is the same
 * fact for every customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_review_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->string('requirement', 60);

            // missing → ready → submitted → approved. `ready` means we have done our part and the
            // platform has not been asked yet, which is a different state from having asked.
            $table->string('status', 20)->default('missing');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'requirement']);
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_review_items');
    }
};
