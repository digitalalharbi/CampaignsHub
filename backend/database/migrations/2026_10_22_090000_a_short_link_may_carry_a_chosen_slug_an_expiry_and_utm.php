<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMART-LINK-001 — the Owner's 2026-10-21 correction: a short link MAY carry a chosen slug, an expiry
 * and UTM parameters. Each is optional and off by default; the two-field path is unchanged.
 *
 * `expires_at` — after it the hop sends the reader to the site like a disabled link. `utm` — the
 * parameters applied to the destination at creation, kept so the row can say what was applied.
 * `custom_slug` — whether the slug was chosen rather than minted, for the row and the audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('is_active');
            $table->jsonb('utm')->nullable()->after('expires_at');
            $table->boolean('custom_slug')->default(false)->after('utm');
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->dropColumn(['expires_at', 'utm', 'custom_slug']);
        });
    }
};
