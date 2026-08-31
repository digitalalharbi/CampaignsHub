<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-RUNTIME §7 §8 — the four numbers, and the two words that were one.
 *
 * ## Why a run needs four counts and not one
 *
 * `metrics_upserted = 0` is the end of a sentence nobody can finish. It is equally true of «the
 * provider had no rows», «the provider sent four hundred rows and our parser produced none» and «we
 * parsed four hundred and could not attach a single one to a campaign». Those are three different
 * defects with three different owners, and for the live Snapchat account they were indistinguishable
 * from the outside — which is exactly why the zero survived.
 *
 * So a run now records where the rows stopped:
 *
 *   provider_raw_rows   → data points the platform actually returned
 *   parsed_rows         → rows our connector made of them
 *   mapped_campaign_rows→ rows that named a campaign we had discovered
 *   metrics_upserted    → normalized metric rows written  (already here, unchanged)
 *
 * Nullable on purpose: a run recorded before this migration did not measure them, and 0 would be a
 * claim it never made. Absent means «not measured», which is the truth about the past.
 *
 * ## And `partial` is split, because it was two answers
 *
 * Historic rows are re-labelled from the evidence they already carry — the exact sentence the syncer
 * wrote — not from a guess:
 *
 *   «The provider returned no insight rows for this window.» → no_data
 *   «N record(s) could not be mapped …»                     → partial_mapping
 *
 * `awaiting_credentials` folds into `failed`: the sync vocabulary is the six words in §8, and a
 * platform whose credentials were withdrawn after it was connected is a broken setup, stated in the
 * run's own error text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_sync_runs', function (Blueprint $table) {
            $table->unsignedInteger('provider_raw_rows')->nullable()->after('metrics_upserted');
            $table->unsignedInteger('parsed_rows')->nullable()->after('provider_raw_rows');
            $table->unsignedInteger('mapped_campaign_rows')->nullable()->after('parsed_rows');
        });

        DB::table('metric_sync_runs')
            ->where('status', 'partial')
            ->where('error', 'like', 'The provider returned no insight rows%')
            ->update(['status' => 'no_data']);

        // Everything else that was `partial` said so because rows could not be mapped — that is the
        // only other sentence the syncer ever wrote under this status.
        DB::table('metric_sync_runs')->where('status', 'partial')->update(['status' => 'partial_mapping']);

        DB::table('metric_sync_runs')->where('status', 'awaiting_credentials')->update(['status' => 'failed']);

        // `pending` was the column default and nothing ever wrote it; a row still holding it never ran.
        DB::table('metric_sync_runs')->where('status', 'pending')->update(['status' => 'failed']);

        /*
         * `integration_sync_runs` speaks the same six words, so its history is re-labelled too.
         *
         * Two run tables with two vocabularies is how a screen ends up rendering «partial» from one of
         * them in a colour it decided from the other. There is one list now, in {@see SyncRunStatus}.
         *
         * Structure and commerce runs never wrote the «no insight rows» sentence, so a `partial` there
         * always meant «some of the four reads had problems» — `partial_mapping`, exactly.
         */
        DB::table('integration_sync_runs')->where('status', 'partial')->update(['status' => 'partial_mapping']);
        DB::table('integration_sync_runs')->where('status', 'awaiting_credentials')->update(['status' => 'failed']);
        DB::table('integration_sync_runs')->where('status', 'pending')->update(['status' => 'failed']);
    }

    public function down(): void
    {
        DB::table('metric_sync_runs')->whereIn('status', ['no_data', 'partial_mapping'])->update(['status' => 'partial']);
        DB::table('integration_sync_runs')->whereIn('status', ['no_data', 'partial_mapping'])->update(['status' => 'partial']);

        Schema::table('metric_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['provider_raw_rows', 'parsed_rows', 'mapped_campaign_rows']);
        });
    }
};
