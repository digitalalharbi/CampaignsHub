<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Services\PasswordResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOGIN-SINGLE-DOOR-001 — the recovery link the E2E gate has no inbox to read.
 *
 * Runs only against a database named `*_e2e` and never in production, the guards of `e2e:prepare`. It asks the real forgot-password
 * service, so a contact with no account gets the real `contact_setup` token, then swaps the secret for one it can print: the mail
 * itself goes to the local transport, and its plaintext is not stored anywhere to be read back.
 */
final class E2eResetLinkCommand extends Command
{
    protected $signature = 'e2e:reset-link {email}';

    protected $description = 'Print a usable /reset-password path for an address, on the E2E database only.';

    public function handle(PasswordResetService $resets): int
    {
        $database = Config::string('database.connections.'.Config::string('database.default').'.database');
        if (App::environment('production') || ! str_ends_with($database, E2ePrepareCommand::REQUIRED_SUFFIX)) {
            $this->error('e2e:reset-link runs only on an E2E database, never in production.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $resets->request($email);

        $secret = Str::random(64);
        if (DB::table('password_reset_tokens')->where('email', $email)->update(['token' => hash('sha256', $secret)]) === 0) {
            $this->error('No token was issued for that address.');

            return self::FAILURE;
        }

        $this->line('/reset-password?token='.$secret.'&email='.rawurlencode($email));

        return self::SUCCESS;
    }
}
