<?php

declare(strict_types=1);

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHONE-001 — bring numbers already in the database into the one canonical form.
 *
 * New writes normalise themselves (see `NormalisesPhoneNumbers`), but every row written before this
 * exists in whatever shape its form happened to accept. Leaving them means duplicate checks keep
 * failing for exactly the customers who were here first, which is the worst possible half of the
 * userbase to break it for.
 *
 * ## Safety, which is the whole design of this migration
 *
 * - **A row is only rewritten when the normaliser can read it.** Anything it cannot parse is left
 *   exactly as it was. That is the rule the brief asks for and the one that matters: a migration that
 *   nulls what it does not understand destroys a real number somebody relies on, and there is no
 *   undoing it from a backup nobody knew they needed.
 * - **A row is only rewritten when the value actually changes.** Nothing is touched to no purpose, so
 *   `updated_at` stays honest and the migration is safe to re-run.
 * - **Chunked by primary key**, so a large table does not load into memory at once.
 * - **`down()` is deliberately empty.** The original strings are not recoverable — normalising is a
 *   one-way narrowing — and pretending otherwise with a reverse migration that guessed a national
 *   format back would be worse than admitting it. Rolling this back leaves the data normalised, which
 *   is harmless: every reader accepts E.164.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => phone columns */
    private const COLUMNS = [
        'users' => ['phone'],
        'contacts' => ['phone'],
        'leads' => ['phone'],
        'external_requests' => ['contact_phone'],
        'registration_requests' => ['phone'],
        'client_portal_tokens' => ['contact_phone'],
        'influencers' => ['contact_phone'],
        'portal_identity_conflicts' => ['contact_phone'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $this->normaliseColumn($table, $column);
            }
        }
    }

    private function normaliseColumn(string $table, string $column): void
    {
        DB::table($table)
            ->select('id', $column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $original = (string) $row->{$column};
                    $normalised = PhoneNumber::normalise($original);

                    // Unreadable, or already correct — leave it exactly as it is.
                    if ($normalised === null || $normalised === $original) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([$column => $normalised]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible — see the note above.
    }
};
