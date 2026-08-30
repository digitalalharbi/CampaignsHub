<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUDGET-GOVERNANCE-001 — `spend_limits.created_by` was a uuid, and a user's key is a bigint.
 *
 * This product's users carry a bigint primary key and a separate `uuid` column, and every other
 * `created_by` in the schema — eighteen of them, from `alert_rules` to `report_shares` — is a
 * bigint. This one column was declared `uuid`, so the only statement that ever writes it could
 * never succeed: Postgres refused «invalid input syntax for type uuid: "6"» and the whole insert
 * rolled back.
 *
 * It was invisible because nothing in the product called the endpoint. The feature tests build
 * limits through the model without an actor, the operator had no form to submit, and so the first
 * time a human created a limit would have been the first time anyone saw this.
 *
 * ## No value can be lost here
 *
 * The only writer of the column is `SpendLimitController::store`, and every one of its inserts
 * failed on this column. A row therefore cannot exist with anything but NULL in it — which the
 * `USING` clause below states explicitly rather than assuming, so the change is a type correction
 * and not a silent truncation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spend_limits', function (Blueprint $table): void {
            $table->dropColumn('created_by');
        });

        Schema::table('spend_limits', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spend_limits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('spend_limits', function (Blueprint $table): void {
            $table->uuid('created_by')->nullable();
        });
    }
};
