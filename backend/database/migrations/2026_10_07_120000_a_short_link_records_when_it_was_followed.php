<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHORT-LINK-HOPS-001 — a counter answers «how many», and nothing has answered «when».
 *
 * `short_links.clicks` is a single integer incremented on every redirect. It is the right shape for
 * a total and the wrong shape for every question an operator actually asks of a link they put in a
 * campaign: whether it is still being followed, whether it moved when the budget moved, whether
 * yesterday looked like the day before. A number that only goes up cannot say any of that, and a
 * link whose clicks stopped a fortnight ago is indistinguishable from one followed this morning.
 *
 * One row per follow, holding the MOMENT and nothing else.
 *
 * ## What is deliberately not here
 *
 * No IP address, no user agent, no referrer, no cookie, no identifier of any kind for the person
 * who followed the link. The owner ruled tracking CONFIGURATION out of this feature by name, and
 * that ruling is kept: there is nothing to configure, nothing that follows anyone between links,
 * and nothing stored that could identify one visitor or tell two apart. A timestamp is the whole
 * row, because a timestamp is the whole of what the curve needs.
 *
 * ## Why the counter stays
 *
 * `clicks` is not replaced and is not derived from these rows. It remains the authoritative total,
 * for two reasons that both matter: the increment is atomic and these inserts are best-effort, so a
 * dropped insert must never cost a counted click; and every link that existed before this table has
 * a real total and no history at all. Those links have NO curve — not a flat one, not a zero one —
 * and the surfaces say «recorded since» rather than drawing a line through a period nobody measured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_link_hops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('short_link_id');
            $table->timestamp('occurred_at');
            /*
             * No `updated_at`: a follow is an event, not a record that is later corrected. A row
             * here is only ever written once, and a column inviting a second write would be an
             * invitation to rewrite history.
             */
            $table->timestamp('created_at')->nullable();

            $table->foreign('short_link_id')->references('id')->on('short_links')->cascadeOnDelete();

            /* The one query these rows exist for: this link, over this window, in order. */
            $table->index(['short_link_id', 'occurred_at'], 'short_link_hops_link_time_idx');
            /* …and the same question asked of a whole workspace, for the reports section. */
            $table->index(['tenant_id', 'occurred_at'], 'short_link_hops_tenant_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_hops');
    }
};
