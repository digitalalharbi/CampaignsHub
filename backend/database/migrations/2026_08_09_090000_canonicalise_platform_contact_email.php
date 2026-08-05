<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own address is `info@campaignshub.io`, all lower case (IDENTITY-PROD-001).
 *
 * It shipped as `info@CampaignsHub.io`. A mail domain is case-insensitive, so nothing was
 * undeliverable — but this address is PRINTED, on the privacy policy, the terms, the contact page
 * and every legal surface the platform owns, and it is compared as a string in code and in tests.
 * Mixed case in a published address reads as a typo, which is the last impression a legal page
 * should give, and two spellings of one address is one spelling too many.
 *
 * This is a separate migration rather than an edit to the one that created the column, because that
 * one may already have run. Rewriting it would leave any installed database holding the old value
 * with nothing to correct it, and the fresh-install and upgrade paths would disagree — exactly the
 * kind of drift the upgrade path exists to prevent.
 *
 * Only the ORIGINAL DEFAULT is rewritten. An operator who has since typed their own address into
 * `/admin/settings` has made a decision, and a migration is not the place to overrule it.
 */
return new class extends Migration
{
    private const WAS = 'info@CampaignsHub.io';

    private const NOW = 'info@campaignshub.io';

    public function up(): void
    {
        Schema::table('platform_settings', function ($table): void {
            $table->string('contact_email', 160)->default(self::NOW)->change();
        });

        DB::table('platform_settings')->where('contact_email', self::WAS)->update(['contact_email' => self::NOW]);
    }

    public function down(): void
    {
        Schema::table('platform_settings', function ($table): void {
            $table->string('contact_email', 160)->default(self::WAS)->change();
        });

        DB::table('platform_settings')->where('contact_email', self::NOW)->update(['contact_email' => self::WAS]);
    }
};
