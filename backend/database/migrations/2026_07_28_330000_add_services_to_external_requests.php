<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid-media service selection on an external request. Additive and nullable — legacy requests carry no
 * services and are never rejected:
 *   - services         : jsonb array of selected `request.paid_service` option keys (engine keys, stable).
 *   - service_details  : jsonb map of optional per-service answers (dynamic intake fields), keyed by service.
 * Existing columns (including the `metadata` dynamic-answer bag) are deliberately left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('external_requests', 'services')) {
                $table->jsonb('services')->nullable()->after('objective'); // request.paid_service option keys
            }
            if (! Schema::hasColumn('external_requests', 'service_details')) {
                $table->jsonb('service_details')->nullable()->after('services'); // optional per-service answers
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            foreach (['services', 'service_details'] as $column) {
                if (Schema::hasColumn('external_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
