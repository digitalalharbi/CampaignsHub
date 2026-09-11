<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHORT-LINKS-001 — a short link can be removed, not only switched off.
 *
 * «Disable» was never the answer to «I made that by mistake»: a disabled link stays in the library
 * forever, and the library is the whole surface this feature has.
 *
 * Soft, deliberately. The click history a link accumulated is the platform's own measurement, and
 * deleting the row would delete the record that the link ever existed along with it. Removing it
 * from the user's library and keeping the audit are different requirements, and a `deleted_at`
 * satisfies both: the model's own scope takes it out of every list AND out of the public hop, which
 * is what «no longer resolves» has to mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
