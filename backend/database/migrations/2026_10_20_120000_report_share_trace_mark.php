<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IP-TRACE-001 — an opaque reference per shared link, so a leaked copy can be traced to the link it came from.
 *
 * Random, not derived from anything about the recipient or the report: it identifies a LINK, carries no
 * personal data, and cannot be reversed into one. Existing links are backfilled so a copy of an older link
 * is traceable too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->string('trace_mark', 16)->nullable()->unique();
        });

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        foreach (DB::table('report_shares')->whereNull('trace_mark')->pluck('id') as $id) {
            do {
                $mark = '';
                for ($i = 0; $i < 10; $i++) {
                    $mark .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $mark = substr($mark, 0, 5).'-'.substr($mark, 5);
            } while (DB::table('report_shares')->where('trace_mark', $mark)->exists());

            DB::table('report_shares')->where('id', $id)->update(['trace_mark' => $mark]);
        }
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->dropUnique(['trace_mark']);
            $table->dropColumn('trace_mark');
        });
    }
};
