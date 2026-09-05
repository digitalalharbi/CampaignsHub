<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTENT-PREVIEW-SHAPES-001 — «بعض الصور» do not show a preview, and this is why.
 *
 * `external_creatives.format` was `string NOT NULL DEFAULT 'image'`, and `ImportExternalStructure`
 * wrote `(string) ($creative['format'] ?? 'image')`. So a creative whose type the connector could not
 * map — a Snapchat COMPOSITE, a DEEP_LINK, anything outside the four cases in its `match` — was
 * stored as an IMAGE. Not «unknown»: an image, asserted by us, about an ad the platform never
 * described that way.
 *
 * Everything downstream then behaved correctly on a false premise. `CreativePresenter::kind()` read
 * «image», found no image asset, and reported `unavailable`: «This ad was fetched from the platform,
 * and the platform exposed no asset for it.» Both halves of that sentence are wrong — the platform
 * exposed an asset for a shape we had told ourselves was a still.
 *
 * A format is a claim about an ad. When the platform did not state one, the honest record is that we
 * do not know, and `null` is how a column says so. The default goes with it: a default that fires on
 * absence is the same invention one layer down, and leaving it would let the next writer reintroduce
 * this by omission.
 *
 * Existing rows are NOT rewritten. A row that genuinely is an image and one that was silently called
 * one are indistinguishable now — that is the damage — and guessing which is which would be the same
 * mistake a second time. They correct themselves on the next sync, from the platform's own answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('format')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('format')->nullable(false)->default('image')->change();
        });
    }
};
