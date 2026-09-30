<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §16 — «asked for» and «started» are two different moments.
 *
 * A run row is written when a WORKER picks the job up, so `started_at` has always answered «when did
 * this begin» and nothing has answered «when did somebody ask». The gap between them is the queue,
 * and it is exactly the interval a customer is staring at after pressing «تأكيد وبدء المزامنة»: the
 * product could say «running» or «no run yet» and never «queued for two minutes», which is the true
 * and reassuring answer.
 *
 * Nullable, and it stays nullable: every run recorded before this column existed was asked for at a
 * time nobody wrote down, and backfilling `started_at` into it would invent a measurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_sync_runs', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('metric_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('queued_at');
        });
    }
};
