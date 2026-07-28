<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical per-request paid-media service selection. This is the source of truth quote/invoice line items
 * derive from — the denormalized external_requests.services jsonb is a convenience mirror only. Each row is one
 * selected service on a request, with its stable service_key + category_key (request.paid_service option keys),
 * optional per-service answers (details jsonb) and an explicit display position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('request_id')->constrained('external_requests')->cascadeOnDelete();
            $table->string('service_key');            // request.paid_service child option key (validated public+active)
            $table->string('category_key')->nullable(); // its parent category option key
            $table->jsonb('details')->nullable();     // optional per-service dynamic answers
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['request_id', 'service_key']);
            $table->index('service_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_services');
    }
};
