<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * CONTENT-STUDIO-001 batch 8 — where the studio keeps what it makes (additive).
 *
 *   - brand_kits        : one per project — logo, font pair, role colours, tone, visual style, forbidden words.
 *   - layout_families   : the seven system families (copied verbatim from the frontend JSON) plus any added later.
 *   - music_tracks      : royalty-free tracks with BPM, beat markers and their licence.
 *   - creative_concepts : one idea saved in several sizes (`_9x16`, `_4x5`, `_1x1`).
 *   - studio_sessions   : one opening of the studio on one or more source assets.
 *   - studio_outputs    : a saved output; the file itself is an ordinary library asset (content_intake_items).
 *   - design_tokens     : the tokens a concept actually used, and whether they came from the brand kit or the picture.
 *   - readiness_scores  : the pre-launch score of a saved output, its checks and whether a hard check failed.
 *
 * Every row is tenant- and project-scoped. The server stores; it renders and encodes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->string('logo_disk')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('font_display')->nullable();
            $table->string('font_text')->nullable();
            $table->jsonb('colors')->nullable(); // text_on_dark, text_on_light, accent, surface, surface_text
            $table->string('tone', 300)->nullable();
            $table->string('visual_style', 300)->nullable();
            $table->jsonb('forbidden_words')->nullable();
            $table->text('source_url')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique('project_id');
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('layout_families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index(); // null = a system family, available to every workspace
            $table->string('key');
            $table->unsignedInteger('version');
            $table->jsonb('definition');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'key', 'version']);
        });
        // Postgres treats NULLs as distinct in a unique index; system families need their own guard.
        DB::statement('CREATE UNIQUE INDEX layout_families_system_key_version_unique ON layout_families (key, version) WHERE tenant_id IS NULL');

        Schema::create('music_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index(); // null = the shared royalty-free library
            $table->string('title');
            $table->string('disk');
            $table->string('path');
            $table->unsignedInteger('duration_ms');
            $table->decimal('bpm', 6, 2)->nullable();
            $table->jsonb('beat_markers')->nullable(); // milliseconds
            $table->string('license_name');
            $table->text('license_url')->nullable();
            $table->string('license_file_path')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('creative_concepts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('studio_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('source_item_ids')->nullable(); // content_intake_items the session opened on
            $table->string('stage')->default('media');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('design_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->uuid('concept_id')->nullable();
            $table->jsonb('tokens');
            $table->string('source'); // brand_kit|derived
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('concept_id')->references('id')->on('creative_concepts')->nullOnDelete();
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('studio_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->uuid('session_id');
            $table->uuid('concept_id')->nullable();
            $table->uuid('design_tokens_id')->nullable();
            $table->uuid('intake_item_id'); // the saved file is an ordinary library asset
            $table->uuid('source_item_id')->nullable();
            $table->string('kind'); // video|image
            $table->string('aspect'); // 9x16|4x5|1x1|191x100
            $table->string('stage')->default('evaluate');
            $table->string('family_key');
            $table->unsignedInteger('family_version');
            $table->jsonb('params')->nullable();
            $table->string('provider')->nullable(); // the AI provider when one generated part of it
            $table->boolean('ai_generated')->default(false);
            $table->smallInteger('rating')->nullable(); // 1 like, -1 dislike
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('has_audio')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('studio_sessions')->cascadeOnDelete();
            $table->foreign('concept_id')->references('id')->on('creative_concepts')->nullOnDelete();
            $table->foreign('design_tokens_id')->references('id')->on('design_tokens')->nullOnDelete();
            $table->foreign('intake_item_id')->references('id')->on('content_intake_items')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id']);
            $table->index(['project_id', 'family_key']);
        });

        Schema::create('readiness_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->uuid('studio_output_id');
            $table->unsignedSmallInteger('score');
            $table->boolean('hard_fail')->default(false);
            $table->jsonb('checks');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('studio_output_id')->references('id')->on('studio_outputs')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id']);
        });

        $this->seedSystemFamilies();
    }

    public function down(): void
    {
        Schema::dropIfExists('readiness_scores');
        Schema::dropIfExists('studio_outputs');
        Schema::dropIfExists('design_tokens');
        Schema::dropIfExists('studio_sessions');
        Schema::dropIfExists('creative_concepts');
        Schema::dropIfExists('music_tracks');
        Schema::dropIfExists('layout_families');
        Schema::dropIfExists('brand_kits');
    }

    /**
     * The seven system families, read from the backend copy of the frontend JSON. `StudioFamiliesMirrorTest` fails if
     * the two copies drift, so the server always holds what the browser renders.
     */
    private function seedSystemFamilies(): void
    {
        $now = Carbon::now();
        foreach (glob(database_path('data/studio_families/*.json')) ?: [] as $file) {
            $definition = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            DB::table('layout_families')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'key' => (string) $definition['key'],
                'version' => (int) $definition['version'],
                'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
