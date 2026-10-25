<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-LINK-COPY-001 — a published link can be copied again without minting a second one.
 *
 * A link's token is stored one-way (`token_hash`), so an existing link's address cannot be shown again. The alias is a SECOND way into
 * the SAME share row: `alias_hash` (sha256, unique) is what the public route looks up, and `alias_cipher` is the raw alias encrypted
 * with the application key so the operator can copy it. A link made from now on uses its own token as its alias, so «copy» returns the
 * exact URL that was sent; an older link gets an alias on first copy, and its original URL keeps working untouched. Both resolve to
 * one share: one expiry, one password, one set of restrictions, one audit history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->string('alias_hash', 64)->nullable()->unique();
            $table->text('alias_cipher')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->dropUnique(['alias_hash']);
            $table->dropColumn(['alias_hash', 'alias_cipher']);
        });
    }
};
