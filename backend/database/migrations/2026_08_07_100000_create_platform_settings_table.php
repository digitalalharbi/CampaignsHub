<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGAL-001 — who the operator of this platform legally is.
 *
 * ## Why this is not `public_page_settings`
 *
 * That table is tenant-scoped: it holds what each CUSTOMER publishes on their own public pages. This
 * holds what CampaignsHub itself is — the legal entity behind the privacy policy, the address a data
 * subject writes to, the registration number a regulator asks for. There is exactly one of those, and
 * filing it per tenant would invite a customer's row to be read as the platform's.
 *
 * ## Why every field is nullable
 *
 * Because the honest state of most of them, right now, is that nobody has told the system. A legal
 * name, a commercial registration, a jurisdiction and a DPO contact are business facts an operator
 * supplies; they are not derivable from code and they are exactly the kind of thing that, if
 * defaulted to something plausible, ends up printed on a privacy policy and relied upon. Unset is a
 * fact the interface can show and a reader can act on. A fabricated «CampaignsHub LLC» is neither.
 *
 * The public pages therefore render a field only when it is set, and say plainly that the operator
 * has not published it when it is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * The singleton guard.
             *
             * One row, enforced by the database rather than by everyone remembering to call
             * `first()`. A second row would mean two answers to «who operates this service?», and the
             * one that got rendered would depend on insertion order.
             */
            $table->boolean('is_singleton')->default(true);
            $table->unique('is_singleton');

            // Identity. Bilingual because the policies are published in both languages and a legal
            // name is not translatable by us — the operator supplies each form or leaves it unset.
            $table->string('legal_name_ar', 200)->nullable();
            $table->string('legal_name_en', 200)->nullable();
            $table->string('trading_name', 200)->nullable();
            $table->string('registration_number', 80)->nullable();
            $table->string('tax_number', 80)->nullable();
            $table->string('jurisdiction', 120)->nullable();

            $table->string('address_ar', 400)->nullable();
            $table->string('address_en', 400)->nullable();

            /*
             * Contacts. `contact_email` has the one sensible default in the set because it is the
             * address the product already publishes on the marketing page — it is our own, not a
             * guess about the operator's legal arrangements.
             */
            $table->string('contact_email', 160)->default('info@CampaignsHub.io');
            $table->string('support_email', 160)->nullable();
            $table->string('security_email', 160)->nullable();
            $table->string('privacy_email', 160)->nullable();
            $table->string('phone', 40)->nullable();

            // The person or office a data subject may address. Named separately from support because
            // a privacy request must not sit in a general queue.
            $table->string('dpo_name', 160)->nullable();
            $table->string('dpo_email', 160)->nullable();

            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
