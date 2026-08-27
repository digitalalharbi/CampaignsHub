<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL-SCHEDULE-001 — the weekly and monthly digests may choose when they arrive.
 *
 * `digest_hour` and `timezone` were already the recipient's own, and correctly so: the whole design
 * of `SendDailyDigests` is that it runs hourly and asks each person whether it is currently their
 * chosen hour where they are. The DAY was not theirs. The weekly went out `isMonday()` and the
 * monthly on `day === 1`, hard-coded, so an agency that reviews on Sunday and an advertiser whose
 * month closes on the 25th both received a report on somebody else's schedule.
 *
 * ## Defaults preserve today's behaviour exactly
 *
 * `digest_weekday` defaults to 1 (Monday, ISO-8601) and `digest_monthday` to 1. Every existing row
 * therefore keeps the schedule it already had, and nobody's mail moves because a column appeared.
 *
 * ## Why a day number rather than a cron string
 *
 * The command already reasons in the recipient's local calendar — `$local->dayOfWeekIso`,
 * `$local->day`. A cron expression would need its own parser, its own timezone semantics and its own
 * failure mode, to express the two questions this actually asks.
 *
 * `digest_monthday` is deliberately capped at 28 rather than 31. A monthly report set for the 30th
 * would simply never arrive in February, and silently — a schedule that skips a month without saying
 * so is worse than one that lands a day early.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->unsignedTinyInteger('digest_weekday')->default(1)->after('frequency');   // ISO-8601: 1 = Monday
            $table->unsignedTinyInteger('digest_monthday')->default(1)->after('digest_weekday');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['digest_weekday', 'digest_monthday']);
        });
    }
};
