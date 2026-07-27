<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled report delivery: extend report_schedules with timezone/audience/language/formats/recipients and
 * a custom cron, and add report_deliveries — an honest per-recipient/per-format delivery ledger. With no mail
 * provider a delivery is "awaiting_provider_credentials"; it is never "sent" without a confirmed provider ack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->string('timezone')->default('Asia/Riyadh')->after('time');
            $table->string('audience')->default('client')->after('timezone');   // client|internal|executive
            $table->string('language', 8)->default('ar')->after('audience');
            $table->jsonb('formats')->nullable()->after('language');             // ['pdf','xlsx','csv']
            $table->jsonb('recipients')->nullable()->after('formats');           // [{email,name}]
            $table->string('cron')->nullable()->after('recipients');             // custom frequency
        });

        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('schedule_id')->nullable()->index();
            $table->uuid('report_id')->nullable()->index();
            $table->string('channel')->default('email'); // email|whatsapp
            $table->string('recipient');
            $table->string('format')->nullable();        // pdf|xlsx|csv
            $table->string('audience')->default('client');
            // queued|awaiting_credentials|sending|sent|failed|retrying|suppressed
            $table->string('status')->default('awaiting_provider_credentials');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'audience', 'language', 'formats', 'recipients', 'cron']);
        });
    }
};
