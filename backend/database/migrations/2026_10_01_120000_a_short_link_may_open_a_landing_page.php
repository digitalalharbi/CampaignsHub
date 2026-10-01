<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SHORT-LINKS-LANDING-001 — a short link may open a PAGE instead of forwarding.
 *
 * ## Why a mode and not a second feature
 *
 * A link in an ad has to lead somewhere a person can read. Forwarding straight to WhatsApp gives a
 * reader nothing to decide from and gives an ad platform nothing to review — the destination is an
 * app handoff with no offer on it, which is the shape of a cloaked link whether or not it is one.
 *
 * So the link gains a MODE rather than the product gaining a second kind of link. `landing_page` is
 * null for every row that exists, and a null mode is the behaviour this product has always had:
 * resolve, count, forward. Nothing already minted changes.
 *
 * `cta_clicks` is the second number this makes possible and the reason the mode is worth having: a
 * visit and a decision are different events, and a page that cannot tell them apart cannot say
 * whether it is working.
 */
return new class extends Migration
{
    /*
     * Minted in the hop's own alphabet — no `0`, `O`, `1`, `l` or `I` — and seven characters, so it
     * is indistinguishable in shape from every other link this product issues.
     */
    private const LANDING_SLUG = 'v7kq3md';

    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            /*
             * The page's own key, not a URL. A column holding an address would let a link point the
             * reader at a page this product does not serve, which is the cloaking risk arriving
             * through the back door.
             */
            $table->string('landing_page')->nullable()->after('destination');
            $table->unsignedBigInteger('cta_clicks')->default(0)->after('clicks');
        });

        /*
         * A NEW link for the page, beside the forwarder — not instead of it.
         *
         * `m5pxgr2` is a forwarder and stays one: it is printed in places this migration cannot see,
         * and changing what an address already in circulation DOES is a different act from adding
         * an address. So the page gets its own slug, minted in the same alphabet and the same shape,
         * because the owner's requirement is that it READS as a short link rather than as a named
         * page somebody could guess at.
         *
         * Cloned from the forwarder's own row so the new link lands in the same workspace and
         * project, and carries the same WhatsApp destination — one fact about where orders go,
         * stored once, with the page's button and the old link both resolving to it.
         *
         * Idempotent: a re-run finds the slug and does nothing.
         */
        $source = DB::table('short_links')->where('slug', 'm5pxgr2')->first();

        if ($source === null || DB::table('short_links')->where('slug', self::LANDING_SLUG)->exists()) {
            return;
        }

        DB::table('short_links')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $source->tenant_id,
            'project_id' => $source->project_id,
            'slug' => self::LANDING_SLUG,
            'kind' => $source->kind,
            'destination' => $source->destination,
            'source_value' => $source->source_value,
            'landing_page' => 'video_request',
            'clicks' => 0,
            'cta_clicks' => 0,
            'is_active' => true,
            'created_by' => $source->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('short_links')->where('slug', self::LANDING_SLUG)->delete();

        Schema::table('short_links', function (Blueprint $table): void {
            $table->dropColumn(['landing_page', 'cta_clicks']);
        });
    }
};
