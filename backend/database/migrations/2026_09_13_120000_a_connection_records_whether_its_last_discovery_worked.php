<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GADS-STALE-PICKER-001 — a connection remembers whether its LAST discovery worked.
 *
 * The owner saw one screen say two things: the Google card «0 ad accounts» beside a banner offering
 * «1 account available — Finish selecting accounts», and the button opened a connection that answered
 * «Item not found».
 *
 * The banner's count came from `ConnectionWizardState`, which counted every `ExternalAccount` row that
 * had ever been written for the connection. Nothing recorded that the LATEST discovery had been
 * refused, so rows from an earlier, permitted discovery were offered as currently selectable — while
 * the card counted a differently-scoped query and answered zero.
 *
 * Three columns, because three different facts:
 *
 *   `last_discovery_attempted_at`  a discovery ran, whatever came of it
 *   `last_discovery_succeeded_at`  the provider answered with a list
 *   `discovery_blocked_reason`     why the latest attempt did not produce one
 *
 * Deliberately NOT one nullable «ok» flag: «never attempted» and «attempted and refused» send an
 * operator to different places, and a boolean cannot hold both. The accounts themselves are untouched
 * — a refused discovery must not unbind work somebody already did, so what changes is what is COUNTED
 * as currently available, never what exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->timestamp('last_discovery_attempted_at')->nullable()->after('last_health_check_at');
            $table->timestamp('last_discovery_succeeded_at')->nullable()->after('last_discovery_attempted_at');
            $table->string('discovery_blocked_reason', 64)->nullable()->after('last_discovery_succeeded_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn(['last_discovery_attempted_at', 'last_discovery_succeeded_at', 'discovery_blocked_reason']);
        });
    }
};
