<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHORT-LINKS-001 — a utility a non-technical person finishes in two fields.
 *
 * The columns are deliberately few. Everything the owner ruled out — redirect types, URL parameters,
 * custom slugs, tracking configuration — would each be a column here, and a column is where a
 * setting starts. What is stored is what somebody chose (`kind`, and the one value they typed), what
 * the system derived from it (`destination`, `slug`), and what the platform itself measured
 * (`clicks`).
 *
 * `source_value` is kept beside the derived destination rather than discarded: a WhatsApp link's
 * destination is `https://wa.me/<digits>`, and showing that back to somebody who typed a phone
 * number is showing them our derivation instead of their input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->nullable()->index();

            /*
             * The slug is the product. Unique GLOBALLY rather than per tenant: it is resolved by a
             * stranger with no session, from a URL that carries nothing else, so two tenants holding
             * the same slug would be one link with two destinations.
             */
            $table->string('slug', 32)->unique();

            $table->string('kind', 16);
            $table->text('destination');
            $table->string('source_value', 2048)->nullable();

            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
