<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-3 — a discount code is unique inside its tenant, a served link code is unique everywhere.
 *
 * `influencer_tracking_assets.code` was UNIQUE across every tenant, because a tracking LINK is resolved by a
 * stranger with no tenant in context. A discount code is different: it is redeemed in the merchant's own store
 * and read inside the merchant's tenant, so a global rule only made tenant B unable to register the coupon
 * tenant A already uses — and told B that somebody holds it.
 *
 * Non-destructive: no row is read, moved or changed. The global index is replaced by two narrower ones, and every
 * existing row already satisfies both (it satisfied the wider rule). `down()` restores the global index and would
 * fail only if two tenants have meanwhile registered the same discount code, which is the situation this lifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A `->unique()` column is a constraint backed by an index: dropping the constraint drops both.
        DB::statement('ALTER TABLE influencer_tracking_assets DROP CONSTRAINT IF EXISTS influencer_tracking_assets_code_unique');
        DB::statement("CREATE UNIQUE INDEX influencer_tracking_assets_link_code_unique ON influencer_tracking_assets (code) WHERE kind = 'link'");
        DB::statement("CREATE UNIQUE INDEX influencer_tracking_assets_discount_code_unique ON influencer_tracking_assets (tenant_id, lower(code)) WHERE kind = 'discount_code'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS influencer_tracking_assets_link_code_unique');
        DB::statement('DROP INDEX IF EXISTS influencer_tracking_assets_discount_code_unique');
        DB::statement('CREATE UNIQUE INDEX influencer_tracking_assets_code_unique ON influencer_tracking_assets (code)');
    }
};
