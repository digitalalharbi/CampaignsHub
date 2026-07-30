<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff email verification. Honest delivery: with no mail provider the email is NOT sent — the record is
 * "awaiting_provider_credentials" and (non-production only, hard-gated) the verify link is surfaced so the
 * flow stays usable. Verifying advances onboarding from verify_email → account_type.
 */
final class EmailVerificationService
{
    private const TTL_MINUTES = 1440; // 24h

    /**
     * Issue a fresh verification token for the user (older ones are invalidated).
     *
     * @return array{delivery_status: string, dev_link: ?string}
     */
    public function send(User $user): array
    {
        DB::table('email_verifications')->where('user_id', $user->id)->whereNull('consumed_at')->delete();

        $token = Str::random(48);
        DB::table('email_verifications')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'delivery_status' => 'awaiting_provider_credentials', // no mailer wired → never "sent"
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // NOTE: when a mail provider is wired, dispatch the verification email here.
        return [
            'delivery_status' => 'awaiting_provider_credentials',
            'dev_link' => ContactVerificationService::exposeDevSecrets() ? "/verify-email?token={$token}" : null,
        ];
    }

    /**
     * Verify a token. Idempotent for an already-verified user. Throws on unknown/expired/consumed tokens.
     */
    public function verify(string $token): User
    {
        $row = DB::table('email_verifications')->where('token_hash', hash('sha256', $token))->first();
        if ($row === null || $row->consumed_at !== null) {
            throw ValidationException::withMessages(['token' => 'This verification link is invalid or already used.']);
        }
        if (Carbon::parse($row->expires_at)->isPast()) {
            throw ValidationException::withMessages(['token' => 'This verification link has expired. Request a new one.']);
        }

        /** @var User $user */
        $user = User::findOrFail($row->user_id);
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();
            // Advance onboarding out of the email step (only if it was waiting there), landing on the first
            // step the visitor has NOT already answered. Someone who picked their path on the public site
            // arrives with account_type and modules set and must not be asked those questions again.
            $tenant = Tenant::find($user->tenant_id);
            if ($tenant !== null && $tenant->onboarding_step === 'verify_email') {
                $tenant->forceFill(['onboarding_step' => self::firstUnansweredStep($tenant)])->save();
            }
        }
        DB::table('email_verifications')->where('id', $row->id)->update(['consumed_at' => Carbon::now()]);

        return $user->refresh();
    }

    /**
     * The first onboarding question this tenant has not answered yet. Registration may have already
     * recorded the account type and the modules from the path chosen on the public site.
     */
    private static function firstUnansweredStep(Tenant $tenant): string
    {
        if ($tenant->account_type === null) {
            return 'account_type';
        }
        if (empty($tenant->enabled_modules)) {
            return 'service';
        }

        return 'workspace';
    }
}
