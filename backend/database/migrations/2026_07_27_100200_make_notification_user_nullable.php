<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project-wide alert notifications have no single recipient (they are visible to every project member),
 * so user_id becomes nullable. Per-user notifications still set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app_notifications ALTER COLUMN user_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app_notifications ALTER COLUMN user_id SET NOT NULL');
    }
};
