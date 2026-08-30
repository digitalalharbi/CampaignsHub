<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUDGET-GOVERNANCE-001 — the workspace's OWN spend limits, which are not the platforms'.
 *
 * ## The distinction this table exists to keep
 *
 * `unified_campaigns.total_budget` is the plan set INSIDE the ad platform. Meta enforces it: when a
 * campaign's budget is exhausted, Meta stops delivering. That column is a copy of somebody else's
 * decision, kept so the product can pace against it.
 *
 * A row here is something else entirely: a limit an agency sets for ITSELF — «this client has 10,000
 * SAR across every connected platform this month» — over a scope no single platform can even see,
 * because it spans them. Nothing enforces it. CampaignsHub watches spend against it and says
 * something before it is reached.
 *
 * Collapsing the two would be the worst kind of convenience. An operator who believes a row here
 * stops delivery will not go and pause the campaigns, and the money keeps going out with a green
 * screen in front of it. So the two live in different tables, are read by different code, and every
 * payload that carries one of these says `enforcement: internal_monitoring` in as many words.
 *
 * ## Scope, and why it is one column pair rather than four tables
 *
 * A limit belongs to exactly one of: the whole project across every platform, one platform inside a
 * project, one advertising account, or one campaign. `scope` names which, and `scope_id` carries the
 * identifier that scope needs — a provider key for `platform`, a uuid for `account` and `campaign`,
 * and nothing at all for `project`, whose scope is already the row's `project_id`.
 *
 * ## Period
 *
 * Explicit dates rather than «monthly», because a media plan does not always start on the first, and
 * a limit whose window the product infers is a limit the product can quietly get wrong. A recurring
 * limit is a series of rows, which is also what makes the history readable: last month's limit stays
 * exactly as it was, next to what was actually spent against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spend_limits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();

            // project | platform | account | campaign — what the limit is drawn around.
            $table->string('scope', 16);
            /*
             * The identifier that scope needs, or null for `project`.
             *
             * A string rather than a uuid column: the `platform` scope carries a provider key
             * («meta», «tiktok»), which is not a uuid and never will be. One column that says what it
             * holds beats four nullable ones that each say what it does not.
             */
            $table->string('scope_id', 191)->nullable();

            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);

            $table->date('starts_on');
            $table->date('ends_on');

            /*
             * The percentages at which somebody should hear about it, as whole numbers: [50, 80, 100].
             *
             * A list rather than one number because «tell me at 80 and again at 100» is the actual
             * request, and because a single threshold column would have been extended by a second one
             * called `threshold_2` within the month.
             */
            $table->jsonb('thresholds')->nullable();

            $table->boolean('active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // The read is always «this project's limits, in this window».
            $table->index(['project_id', 'active', 'starts_on', 'ends_on'], 'spend_limits_project_window_idx');
            $table->index(['scope', 'scope_id']);
        });

        /*
         * What was said, and when — so a threshold crossing is a fact with a timestamp rather than a
         * recomputation that may say something different tomorrow.
         *
         * This is the audit half of the requirement. It also carries the dedup: a limit crossing 80%
         * must be announced once, not on every sweep for the rest of the period.
         */
        Schema::create('spend_limit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('spend_limit_id')->constrained('spend_limits')->cascadeOnDelete();

            // The threshold that was crossed, as a whole percentage. 100 is the limit itself.
            $table->unsignedSmallInteger('threshold');
            // The figures at the moment of crossing, kept rather than recomputed later.
            $table->decimal('consumed', 18, 4);
            $table->decimal('limit_amount', 18, 4);
            $table->char('currency', 3);
            $table->timestamp('crossed_at');
            $table->timestamps();

            // One announcement per (limit, threshold). The dedup is the index, not a query.
            $table->unique(['spend_limit_id', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_limit_events');
        Schema::dropIfExists('spend_limits');
    }
};
