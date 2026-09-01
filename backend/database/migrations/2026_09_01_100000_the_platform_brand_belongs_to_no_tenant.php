<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BRANDING-HIERARCHY-001 — the last link of the fallback chain was not a link.
 *
 * The chain every surface documents is client → agency → **CampaignsHub**, and `BrandingSpec::SCOPES`
 * opens with `platform`. But `BrandingAsset` uses `BelongsToTenant`, so a platform-scope row lives
 * INSIDE one tenant and can never answer for another. The final fallback was unreachable by
 * construction for every tenant but the one holding the row — and since any tenant with
 * `branding.manage` could write one, the scope meant two different things depending on who asked.
 *
 * A platform brand is the PRODUCT's own mark. It belongs to no tenant, so `tenant_id` becomes
 * nullable and platform rows carry NULL.
 *
 * ## Why a second unique index
 *
 * Postgres treats NULLs as distinct in a unique index, so the existing
 * `(tenant_id, scope, scope_id, kind, theme)` constraint stops constraining the moment `tenant_id`
 * is NULL — «one file per slot», the whole point of that index, would silently become «as many as
 * you like». The partial index below restores it for exactly the rows the first one can no longer
 * see. `scope_id` is nullable too and is COALESCEd, for the same reason one layer down.
 *
 * ## The existing rows
 *
 * Any platform-scope row written under a tenant before this is moved to NULL. There is at most a
 * handful — the scope was unreachable, so nothing depended on one — and leaving them tenant-owned
 * would keep exactly the defect this migration exists to remove. `down()` cannot restore which
 * tenant they were under, and says so rather than inventing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE branding_assets ALTER COLUMN tenant_id DROP NOT NULL');
        DB::statement('ALTER TABLE branding_settings ALTER COLUMN tenant_id DROP NOT NULL');

        DB::statement(
            'CREATE UNIQUE INDEX branding_assets_platform_slot_unique ON branding_assets '
            ."(scope, COALESCE(scope_id::text, ''), kind, theme) WHERE tenant_id IS NULL",
        );
        DB::statement(
            'CREATE UNIQUE INDEX branding_settings_platform_slot_unique ON branding_settings '
            ."(scope, COALESCE(scope_id::text, '')) WHERE tenant_id IS NULL",
        );

        /*
         * De-duplicate before detaching, or the new index refuses to build: two tenants may each
         * hold a row for the same platform slot, and both are about to become the same row. The
         * newest wins, because a brand mark is replaced rather than merged.
         */
        foreach (['branding_assets', 'branding_settings'] as $table) {
            $keep = $table === 'branding_assets'
                ? ['scope', 'scope_id', 'kind', 'theme']
                : ['scope', 'scope_id'];

            $rows = DB::table($table)->where('scope', 'platform')->orderByDesc('created_at')->get();
            $seen = [];

            foreach ($rows as $row) {
                $slot = implode('|', array_map(static fn (string $c): string => (string) ($row->{$c} ?? ''), $keep));

                if (isset($seen[$slot])) {
                    DB::table($table)->where('id', $row->id)->delete();

                    continue;
                }

                $seen[$slot] = true;
                DB::table($table)->where('id', $row->id)->update(['tenant_id' => null]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS branding_assets_platform_slot_unique');
        DB::statement('DROP INDEX IF EXISTS branding_settings_platform_slot_unique');

        /*
         * The platform rows are deleted rather than given a tenant back: which tenant each one came
         * from is not recorded anywhere, and picking one would hand the product's own mark to an
         * arbitrary customer. A brand file is re-uploaded in a minute; a wrong owner is not
         * noticeable until it is on somebody's client report.
         */
        DB::table('branding_assets')->whereNull('tenant_id')->delete();
        DB::table('branding_settings')->whereNull('tenant_id')->delete();

        DB::statement('ALTER TABLE branding_assets ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE branding_settings ALTER COLUMN tenant_id SET NOT NULL');
    }
};
