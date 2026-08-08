<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailLinks;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recovering an account nobody can sign in to — MAIL-009.
 *
 * ## What was here before
 *
 * `AuthController::forgotPassword` matched the address, wrote a line to the log, and returned success.
 * There was no token, no reset endpoint, and no way to finish: an account whose password was lost was
 * an account lost. The interface said «check your email» and there was nothing to check — including
 * for every member invited through `TeamController::invite`, who is created with a random 24-character
 * password and has never had another way in.
 *
 * ## The token is stored as a hash
 *
 * `password_reset_tokens.token` holds `sha256(secret)`. A leaked database read is the scenario this
 * defends against, and a plaintext reset token in that dump is a working key to every account with an
 * open request. The comparison is `hash_equals`, so it does not leak position through timing.
 *
 * ## One live token per address
 *
 * The table is keyed by email, so issuing replaces. A resend must REPLACE the window rather than widen
 * it — leaving the previous token usable means «send it again» quietly doubles how long an account is
 * reachable by anybody holding either message.
 *
 * ## Nothing here reveals whether an account exists
 *
 * `request()` returns the same shape for an unknown address as for a known one. The controller answers
 * identically either way; see its docblock. An enumeration oracle in a password-reset form is the
 * cheapest one a product can offer, because it is unauthenticated by definition.
 */
final class PasswordResetService
{
    /** Long enough to read the email and act; short enough that a forwarded message goes stale. */
    private const TTL_MINUTES = 60;

    /**
     * A new member has not asked for anything and may not read their mail until tomorrow.
     *
     * An hour would mean the first message the product ever sends them is already dead when they open
     * it — and the account they were given cannot be signed into by any other route.
     */
    private const SETUP_TTL_MINUTES = 4320;

    public function __construct(private readonly TransactionalMailer $mailer) {}

    /**
     * Issue a reset link, if there is an account to issue one for.
     *
     * @return string the delivery state, or `ignored` when no account matches
     */
    public function request(string $email): string
    {
        $email = Str::lower(trim($email));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            return 'ignored';
        }

        $secret = $this->issue($email, 'password_reset', self::TTL_MINUTES);

        /*
         * No dedup key.
         *
         * Everywhere else in this product exactly-once is the goal; here a person asking twice
         * because the first message went to spam must get a second message. Each request issues a
         * fresh token and invalidates the previous one, so «twice» cannot mean two working links.
         */
        return $this->mailer->send(
            recipient: $email,
            mail: new CredentialMail(
                purpose: CredentialMail::PASSWORD_RESET,
                lang: 'ar',
                url: $this->link($email, $secret),
                expiresInMinutes: self::TTL_MINUTES,
            ),
            kind: 'password_reset',
            template: 'mail.credential',
            userId: (int) $user->getKey(),
        );
    }

    /**
     * The first message a new team member receives: choose a password and sign in.
     *
     * `TeamController::invite` provisions the account with a random 24-character password and, until
     * this existed, sent nothing — so every member added through the settings screen held an account
     * they could not sign in to, and no route existed to recover it. The comment in that controller
     * said the account was «usable via password reset meanwhile»; password reset had a TODO where its
     * implementation should have been.
     *
     * The same token machinery as a reset, with its own lifetime and its own words. It is not a reset:
     * telling somebody «we received a request to reset your password» when they asked for nothing is
     * the kind of message that gets a product reported.
     */
    public function inviteExistingMember(User $user, string $workspace): string
    {
        $email = Str::lower(trim((string) $user->email));
        $secret = $this->issue($email, 'member_setup', self::SETUP_TTL_MINUTES);

        return $this->mailer->send(
            recipient: $email,
            mail: new CredentialMail(
                purpose: CredentialMail::MEMBER_SETUP,
                lang: 'ar',
                url: $this->link($email, $secret),
                expiresInMinutes: self::SETUP_TTL_MINUTES,
                workspace: $workspace,
            ),
            kind: 'member_setup',
            template: 'mail.credential',
            userId: (int) $user->getKey(),
        );
    }

    /**
     * Write one live token for this address, replacing any earlier one.
     *
     * The table is keyed by email, so issuing REPLACES. A resend must replace the window rather than
     * widen it: leaving the previous token usable means «send it again» quietly doubles how long the
     * account is reachable by anybody holding either message.
     *
     * @return string the plaintext secret — stored only as its hash
     */
    private function issue(string $email, string $purpose, int $ttlMinutes): string
    {
        $secret = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => hash('sha256', $secret),
                'created_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
                'purpose' => $purpose,
            ],
        );

        return $secret;
    }

    private function link(string $email, string $secret): string
    {
        return MailLinks::to('/reset-password?token='.$secret.'&email='.rawurlencode($email));
    }

    /**
     * Consume a reset token and set the new password.
     *
     * Every failure answers with the same message on the `token` key. Distinguishing «expired» from
     * «wrong» would tell somebody holding a stale link whether the address has an open request, which
     * is the same oracle `request()` refuses to be.
     */
    public function reset(string $email, string $token, string $password): User
    {
        $email = Str::lower(trim($email));
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        $invalid = ValidationException::withMessages([
            'token' => 'This reset link is invalid or has expired. Request a new one.',
        ]);

        if ($row === null || ! hash_equals((string) $row->token, hash('sha256', $token))) {
            throw $invalid;
        }

        /*
         * The row's own expiry, with the issuing default as the fallback.
         *
         * A token written before `expires_at` existed still has `created_at`, and reading it that way
         * keeps those links working for exactly the hour they were promised rather than treating a
         * missing column as «expired».
         */
        $expiresAt = $row->expires_at !== null
            ? Carbon::parse($row->expires_at)
            : ($row->created_at !== null ? Carbon::parse($row->created_at)->addMinutes(self::TTL_MINUTES) : null);

        if ($expiresAt === null || $expiresAt->isPast()) {
            throw $invalid;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            // The account went away between issuing and using. Same answer: the link cannot be used.
            throw $invalid;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            /*
             * A new remember token, so «remember me» cookies minted before the reset stop working.
             *
             * Without this, somebody who reset their password because a device was stolen keeps that
             * device signed in — which is the one outcome the reset was performed to prevent.
             */
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Every open session for this account, ended. `sessions` is the database driver's own table;
        // rows for other users are untouched.
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        /*
         * Tell them it happened.
         *
         * If the reset was theirs this is a receipt. If it was not, it is the only message that will
         * ever reach them about it — and it arrives at the address the attacker just used, which is
         * the one place they cannot suppress it from.
         */
        $this->mailer->send(
            recipient: $email,
            mail: new SecurityAlertMail(
                event: SecurityAlertMail::PASSWORD_CHANGED,
                lang: 'ar',
                recipientName: (string) $user->name,
                at: Carbon::now()->format('Y-m-d H:i'),
            ),
            kind: 'security_alert',
            template: 'mail.security',
            userId: (int) $user->getKey(),
        );

        return $user;
    }
}
