<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PRICING-SEATS-001 — an ad account is a SEAT, identified by its provider and its real id, never by the connection or project.
 *
 * `ad_account_seats` is one row per (tenant, provider, external id) ever bound; `ad_account_seat_events` is the history that is
 * never deleted (claimed, released, reactivated, replaced). Existing bindings are back-filled as active seats, so nobody's
 * connected accounts change state. The 2026-11 plans gain their 30-day replacement allowance; plans without one (the launch
 * catalogue) keep unlimited replacement — their old behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_account_seats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('provider', 32);
            $table->string('external_id', 191);
            $table->string('status', 16)->default('active');
            $table->timestamp('first_bound_at');
            $table->timestamp('last_bound_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'external_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('ad_account_seat_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('seat_id')->index();
            $table->string('type', 16);
            $table->string('provider', 32);
            $table->string('external_id', 191);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        $now = now();
        DB::table('project_integration_bindings')
            ->join('external_accounts', 'external_accounts.id', '=', 'project_integration_bindings.external_account_id')
            ->where('project_integration_bindings.is_active', true)
            ->where('external_accounts.account_type', 'ad_account')
            ->select('project_integration_bindings.tenant_id', 'external_accounts.provider', 'external_accounts.external_id')
            ->distinct()->orderBy('project_integration_bindings.tenant_id')
            ->get()
            ->each(function ($row) use ($now): void {
                $id = (string) Str::uuid();
                $inserted = DB::table('ad_account_seats')->insertOrIgnore([
                    'id' => $id, 'tenant_id' => $row->tenant_id, 'provider' => $row->provider, 'external_id' => $row->external_id,
                    'status' => 'active', 'first_bound_at' => $now, 'last_bound_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($inserted) {
                    DB::table('ad_account_seat_events')->insert([
                        'tenant_id' => $row->tenant_id, 'seat_id' => $id, 'type' => 'backfilled', 'provider' => $row->provider,
                        'external_id' => $row->external_id, 'occurred_at' => $now,
                    ]);
                }
            });

        foreach (['solo' => 1, 'team' => 3, 'agency_2026' => 10] as $code => $allowance) {
            $plan = DB::table('subscription_plans')->where('code', $code)->first(['id', 'limits']);
            if ($plan === null) {
                continue;
            }
            $limits = (array) json_decode((string) $plan->limits, true);
            if (! array_key_exists('ad_account_replacements', $limits)) {
                $limits['ad_account_replacements'] = $allowance;
                DB::table('subscription_plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_account_seat_events');
        Schema::dropIfExists('ad_account_seats');
    }
};
