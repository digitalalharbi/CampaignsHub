<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FX-ENGINE-001 — every rate managed from /admin/settings/currency-rates, versioned, attributable. ADDITIVE ONLY.
 *
 * - `currency_rates` keeps its natural key (one LIVE rate per pair and day) and gains nullable columns: the kind of
 *   rate (manual · pegged · automatic), an optional end of validity, a note/reference, who recorded it and which
 *   version is live. Existing rows are labelled from their own `source`; no rate value is touched.
 * - `currency_rate_versions` is APPEND-ONLY: every write — first entry, edit, an automatic quote shadowed by a manual
 *   one — inserts a row and nothing ever updates or deletes one. The live row is a projection of the latest version.
 * - `platform_settings.fx_policy` holds the platform display currency and the free automatic source switch (off).
 * - The workspace default USD→SAR (BUDGET-FX-DEFAULT-001, 3.75) becomes ONE platform pegged row SAR→USD
 *   (0.266666666667, SAMA). `workspace_fx_defaults` is left in place and unread — nothing is dropped.
 *
 * `down()` removes exactly what `up()` added: the new columns, the versions table, the policy column and the one
 * pegged row it inserted (found by its migration note). No original rate row is changed either way.
 */
return new class extends Migration
{
    private const MIGRATED_NOTE = 'migrated: workspace default USD→SAR 3.75 (BUDGET-FX-DEFAULT-001)';

    public function up(): void
    {
        Schema::table('currency_rates', function (Blueprint $table): void {
            $table->string('kind', 16)->nullable()->after('source');
            $table->date('effective_to')->nullable()->after('rate_date');
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_email', 191)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('current_version_id')->nullable();
            $table->index(['base_currency', 'quote_currency', 'kind'], 'currency_rates_pair_kind');
        });

        Schema::create('currency_rate_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('currency_rate_id')->nullable();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 24, 12);
            $table->decimal('previous_rate', 24, 12)->nullable();
            $table->date('rate_date');
            $table->date('effective_to')->nullable();
            $table->string('kind', 16);
            $table->string('source');
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('version');
            // applied — became the live rate · shadowed — recorded, but a manual rate for the same day wins
            $table->string('outcome', 16);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_email', 191)->nullable();
            $table->timestampTz('created_at');
            $table->index(['base_currency', 'quote_currency', 'rate_date'], 'currency_rate_versions_pair_day');
            $table->index('currency_rate_id');
        });

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->jsonb('fx_policy')->nullable();
        });

        // Label existing rows from their own source. Only the NEW column is written.
        DB::table('currency_rates')->whereNull('kind')->where('source', 'like', 'manual%')->update(['kind' => 'manual']);
        DB::table('currency_rates')->whereNull('kind')->where('source', 'like', 'official-peg%')->update(['kind' => 'pegged']);
        DB::table('currency_rates')->whereNull('kind')->update(['kind' => 'automatic']);

        $this->firstVersionOfExistingRates();
        $this->migrateWorkspaceDefault();
    }

    /** History starts complete: every rate already on file gets its version 1, as it stands. */
    private function firstVersionOfExistingRates(): void
    {
        DB::table('currency_rates')->whereNull('current_version_id')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $r) {
                $versionId = (string) Str::uuid();
                DB::table('currency_rate_versions')->insert([
                    'id' => $versionId, 'currency_rate_id' => $r->id, 'base_currency' => $r->base_currency,
                    'quote_currency' => $r->quote_currency, 'rate' => $r->rate, 'previous_rate' => null,
                    'rate_date' => $r->rate_date, 'effective_to' => null, 'kind' => $r->kind, 'source' => $r->source,
                    'note' => null, 'version' => 1, 'outcome' => 'applied', 'recorded_by' => null,
                    'recorded_by_email' => str_starts_with((string) $r->source, 'manual:') ? substr((string) $r->source, 7) : null,
                    'created_at' => $r->updated_at ?? $r->created_at ?? now(),
                ]);
                DB::table('currency_rates')->where('id', $r->id)->update(['current_version_id' => $versionId]);
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('currency_rates')->where('note', self::MIGRATED_NOTE)->pluck('id')->all();
        if ($ids !== []) {
            DB::table('currency_rates')->whereIn('id', $ids)->delete();
        }

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn('fx_policy');
        });

        Schema::dropIfExists('currency_rate_versions');

        Schema::table('currency_rates', function (Blueprint $table): void {
            $table->dropIndex('currency_rates_pair_kind');
            $table->dropColumn(['kind', 'effective_to', 'note', 'recorded_by', 'recorded_by_email', 'version', 'current_version_id']);
        });
    }

    /** One platform pegged SAR→USD row, unless a pegged SAR/USD row is already on file. */
    private function migrateWorkspaceDefault(): void
    {
        $exists = DB::table('currency_rates')->where('kind', 'pegged')
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('base_currency', 'SAR')->where('quote_currency', 'USD'))
                ->orWhere(fn ($p) => $p->where('base_currency', 'USD')->where('quote_currency', 'SAR')))
            ->exists();
        if ($exists) {
            return;
        }

        $rateId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $now = now();
        $rate = round(1 / 3.75, 12);

        DB::table('currency_rates')->insert([
            'id' => $rateId, 'base_currency' => 'SAR', 'quote_currency' => 'USD', 'rate' => $rate,
            // The peg's own date; a pegged rate applies to every date regardless (FxEngine).
            'rate_date' => '1986-06-01', 'source' => 'official-peg:SAMA', 'kind' => 'pegged',
            'note' => self::MIGRATED_NOTE, 'version' => 1, 'current_version_id' => $versionId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('currency_rate_versions')->insert([
            'id' => $versionId, 'currency_rate_id' => $rateId, 'base_currency' => 'SAR', 'quote_currency' => 'USD',
            'rate' => $rate, 'previous_rate' => null, 'rate_date' => '1986-06-01', 'effective_to' => null,
            'kind' => 'pegged', 'source' => 'official-peg:SAMA', 'note' => self::MIGRATED_NOTE, 'version' => 1,
            'outcome' => 'applied', 'recorded_by' => null, 'recorded_by_email' => null, 'created_at' => $now,
        ]);
    }
};
