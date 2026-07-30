<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Influencer & UGC marketing — core schema (INFL-001, ADR 0002).
 *
 * Three tables, because the work has three distinct lifetimes and collapsing them loses information
 * the business actually needs:
 *
 *   influencers      — the roster. Outlives any one campaign; a creator worked with last year is still
 *                      on the roster with their audience figures and past terms.
 *   collaborations   — one agreement, for one client, with one influencer. THIS is where the fee and
 *                      the client live, because the same creator costs different money for different
 *                      clients and must never be visible across them.
 *   deliverables     — the individual pieces of content owed under a collaboration, each with its own
 *                      due date, approval and live URL. A collaboration with one status cannot say
 *                      "two of three posts are live", which is the question everyone asks.
 *
 * Isolation: every table carries `tenant_id`, and a collaboration also carries `client_workspace_id`
 * so the existing client-scope ceiling narrows this domain with no second mechanism.
 *
 * Money: `agreed_fee` is what the CLIENT is billed and `influencer_fee` is what the creator is paid.
 * They are separate columns rather than one plus a margin, because a margin computed from a single
 * figure cannot represent a fee renegotiated on one side only — and because the two must be
 * independently withheld from a client-facing surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->string('handle')->nullable();              // @name, without the platform
            $table->string('primary_platform')->nullable();    // taxonomy: influencer.platform
            $table->string('profile_url')->nullable();
            $table->unsignedBigInteger('followers')->nullable();
            // Engagement rate as a percentage with two decimals (4.25 = 4.25%), never a float ratio:
            // a ratio invites "is 0.0425 four percent or four hundred?" at every reading site.
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->string('tier')->nullable();                // taxonomy: influencer.tier
            $table->json('categories')->nullable();            // taxonomy: influencer.category (multi)
            $table->string('country')->nullable();
            $table->string('language')->nullable();

            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->string('status')->default('active');       // taxonomy: influencer.status
            $table->text('internal_notes')->nullable();        // NEVER client-facing
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'primary_platform']);
        });

        // One roster entry per handle per platform, per tenant — but ONLY when a handle is given.
        // Laravel's schema builder cannot express the predicate, and it is the predicate that matters:
        // without `WHERE handle IS NOT NULL` this reads as if two creators added without a handle
        // would collide. Lowercased because @Name and @name are the same account.
        DB::statement(
            'CREATE UNIQUE INDEX influencers_tenant_handle_platform_unique
             ON influencers (tenant_id, lower(handle), primary_platform)
             WHERE handle IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::create('influencer_collaborations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('influencer_id')->constrained('influencers')->cascadeOnDelete();
            // The client this work is for. The existing client-scope ceiling narrows through this.
            $table->foreignUuid('client_workspace_id')->nullable()
                ->constrained('client_workspaces')->nullOnDelete();
            $table->foreignUuid('campaign_id')->nullable()
                ->constrained('unified_campaigns')->nullOnDelete();

            $table->string('title');
            $table->string('status')->default('draft');        // taxonomy: collaboration.status
            $table->string('currency', 3)->default('SAR');
            // Billed to the client vs paid to the creator — see the class docblock.
            $table->decimal('agreed_fee', 14, 2)->nullable();
            $table->decimal('influencer_fee', 14, 2)->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('brief')->nullable();                 // shared with the creator
            $table->text('internal_notes')->nullable();        // NEVER client- or creator-facing

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_workspace_id']);
        });

        Schema::create('influencer_deliverables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('collaboration_id')->constrained('influencer_collaborations')->cascadeOnDelete();

            $table->string('type');                            // taxonomy: deliverable.type
            $table->string('platform')->nullable();            // taxonomy: influencer.platform
            $table->string('status')->default('pending');      // taxonomy: deliverable.status
            $table->date('due_on')->nullable();

            $table->string('submitted_url')->nullable();       // the creator's draft or live post
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('feedback')->nullable();              // shared with the creator on rejection

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['collaboration_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influencer_deliverables');
        Schema::dropIfExists('influencer_collaborations');
        Schema::dropIfExists('influencers');
    }
};
