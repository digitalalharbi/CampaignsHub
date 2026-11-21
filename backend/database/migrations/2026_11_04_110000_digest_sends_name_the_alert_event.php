<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL-TEST-SEND-001 (§17.4.5) — which alert a send was for.
 *
 * `period_key` is a hash of the event and the moment it fired, so it can say «this exact trigger was
 * sent» and cannot say «this alert has already reached this person three times today». Additive and
 * nullable: older rows simply do not count towards the ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digest_sends', function (Blueprint $table): void {
            $table->string('alert_event_id', 64)->nullable();
            $table->index(['user_id', 'alert_event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('digest_sends', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'alert_event_id', 'created_at']);
            $table->dropColumn('alert_event_id');
        });
    }
};
