<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AI-PLATFORM-KEY-001 — the platform's AI key and the two meters that count what it is used for.
 *
 * - `platform_ai_keys`: one row per provider, the secret encrypted at rest (the model's `encrypted` cast), never returned.
 * - `ai_messages_platform`: one per assistant reply on the platform key, counted PER PERSON (scope `user:<id>`). Its
 *   `included_per_plan` is the monthly allowance of ONE person on that plan — the operator edits it in the admin console.
 * - `ai_tokens_platform`: the tokens those replies used, for the operator's cost view. Not a cap.
 *
 * Additive; down() removes exactly what up() added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ai_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 16)->unique();
            $table->text('encrypted_secret')->nullable();
            $table->string('last_four', 8)->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('configured_by')->nullable();
            $table->timestamps();
        });

        $now = now();
        $perPerson = ['solo' => 30, 'starter' => 30, 'team' => 100, 'growth' => 100, 'agency_2026' => 300, 'agency' => 300, 'enterprise_2026' => 1000, 'enterprise' => 1000];
        foreach ([
            ['code' => 'ai_messages_platform', 'name' => 'Assistant messages', 'name_ar' => 'رسائل المساعد', 'unit' => 'message', 'included' => $perPerson, 'sort' => 5],
            ['code' => 'ai_tokens_platform', 'name' => 'AI tokens on the platform key', 'name_ar' => 'رموز الذكاء الاصطناعي على مفتاح المنصة', 'unit' => 'token', 'included' => null, 'sort' => 6],
        ] as $m) {
            if (DB::table('usage_meters')->where('code', $m['code'])->exists()) {
                continue;
            }
            DB::table('usage_meters')->insert([
                'id' => (string) Str::uuid(), 'code' => $m['code'], 'name' => $m['name'], 'name_ar' => $m['name_ar'], 'unit' => $m['unit'],
                'funded_by' => 'platform', 'unit_cost' => null, 'currency' => 'SAR', 'charges_credits' => false,
                'included_per_plan' => $m['included'] === null ? null : json_encode($m['included']),
                'is_active' => true, 'sort_order' => $m['sort'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('usage_meters')->whereIn('code', ['ai_messages_platform', 'ai_tokens_platform'])->delete();
        Schema::dropIfExists('platform_ai_keys');
    }
};
