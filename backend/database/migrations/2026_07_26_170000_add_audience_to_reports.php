<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report is authored for a specific audience — client / internal / executive — which is a durable
 * property of the report (not a view-time toggle), so a client snapshot stays client-safe even if the
 * filtering rules change later. Sharing an internal report externally is blocked at the service layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('audience')->default('client')->after('type'); // client|internal|executive
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
