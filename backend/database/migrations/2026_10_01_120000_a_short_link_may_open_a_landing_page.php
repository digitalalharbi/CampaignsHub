<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
         * The one link the owner named — flipped here rather than by hand on the server.
         *
         * A deploy that carries the page and leaves the row untouched ships a feature nobody can
         * reach, and an operator editing production by hand leaves no record of what changed. Scoped
         * to the exact slug and idempotent: a re-run sets the same value.
         */
        DB::table('short_links')
            ->where('slug', 'm5pxgr2')
            ->update(['landing_page' => 'video_request']);
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->dropColumn(['landing_page', 'cta_clicks']);
        });
    }
};
