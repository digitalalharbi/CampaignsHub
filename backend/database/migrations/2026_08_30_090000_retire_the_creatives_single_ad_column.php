<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CREATIVE-COLUMN-RETIRE-001 — `external_creatives.external_ad_id` has no legitimate purpose left.
 *
 * It looked like a relation and was not. `creativeFor()` rewrote it on every upsert, so it held
 * whichever ad happened to be imported last — and on the live Snapchat account four ads share each
 * creative. Everything built on it was therefore true of one arbitrary quarter while reading as
 * definite: a drill-down that pointed at one of four ads, a detail page fact labelled «Ad», and a
 * `whereNull` check that asked «has this creative ever been linked» and answered from one row.
 *
 * `external_ads.creative_id` is the canonical relation and always was. Every reader has migrated to
 * it, both writers are gone, and this removes the column rather than leaving a field that invites
 * the next person to trust it.
 *
 * `down()` restores the column but NOT its values, and that is honest rather than lazy: the values
 * were never a fact worth restoring — they are re-derived from the canonical relation by the next
 * structure import, which is where they came from in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_creatives', 'external_ad_id')) {
            return;
        }

        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->dropColumn('external_ad_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_creatives', 'external_ad_id')) {
            return;
        }

        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('external_ad_id')->nullable();
        });
    }
};
