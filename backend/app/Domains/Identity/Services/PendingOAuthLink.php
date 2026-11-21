<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A verified provider profile waiting for its owner to prove who they are (LOGIN-004).
 *
 * ## Why it exists
 *
 * A Google callback whose verified address matches a local account is still not a sign-in: an address a provider asserts is not
 * proof that the person holding the browser owns the account. What it used to answer was a dead end — «sign in with your password
 * and connect Google from settings». The same proof can be taken right there, so this keeps the profile for ten minutes and the
 * sign-in page asks for the proof: the account's password, or a link mailed to the ACCOUNT's own address. Only then is the identity
 * linked (`ResolveOAuthIdentity::link`) and the session opened.
 *
 * ## What holds it, and what does not
 *
 * - The profile lives in the cache, encrypted, under a random opaque id. The id lives ONLY in the session of the browser that
 *   completed the Google flow: possession of the pending link is possession of that session. The id is never in a URL, a log line
 *   or a response, and the profile is never logged.
 * - **Single use.** `claim()` is an atomic `add` on a marker, so two requests racing with the right proof link once, and a replay
 *   finds nothing.
 * - **Ten minutes**, then it is gone from the cache whatever anyone does.
 * - **Five wrong passwords** discard it. The password attempts also spend the same `auth-login` allowance as the form.
 * - **The mailed link carries a second secret** (stored as a hash) and works only in the browser session that holds the pending
 *   link: opened anywhere else it fails closed and consumes nothing.
 */
final class PendingOAuthLink
{
    public const TTL_SECONDS = 600;

    public const MAX_WRONG_PASSWORDS = 5;

    public const MAX_MAILS = 3;

    public const MAIL_COOLDOWN_SECONDS = 60;

    private const SESSION_KEY = 'oauth.pending_link';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Keep a verified profile for its owner to confirm.
     *
     * @param  array{sub: string, email: ?string, email_verified: bool, name: ?string, avatar: ?string}  $profile
     */
    public function offer(Request $request, string $provider, array $profile, int|string $userId, ?string $portal, ?string $redirect): void
    {
        $this->discard($request);

        $id = Str::random(48);
        $this->write($id, [
            'provider' => $provider,
            'profile' => $profile,
            'user_id' => $userId,
            'portal' => $portal,
            'redirect' => $redirect,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
            'wrong' => 0,
            'mails' => 0,
            'mail_at' => null,
            'mail_token_hash' => null,
        ]);
        $request->session()->put(self::SESSION_KEY, $id);

        $this->record('auth.oauth_link_offered', $userId, $provider, 'proof_required');
    }

    /**
     * The pending link this browser holds, or null when there is none, it expired, or it was spent.
     *
     * @return array{id: string, provider: string, profile: array{sub: string, email: ?string, email_verified: bool, name: ?string, avatar: ?string}, user_id: int|string, portal: ?string, redirect: ?string, expires_at: int, wrong: int, mails: int, mail_at: ?int, mail_token_hash: ?string}|null
     */
    public function peek(Request $request): ?array
    {
        $id = $request->session()->get(self::SESSION_KEY);
        if (! is_string($id) || $id === '') {
            return null;
        }

        $entry = $this->read($id);
        if ($entry === null || $entry['expires_at'] <= now()->getTimestamp()) {
            $this->forget($id);
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return ['id' => $id] + $entry;
    }

    /** Throw it away: «إلغاء», a spent link, too many wrong passwords. Idempotent. */
    public function discard(Request $request): void
    {
        $id = $request->session()->pull(self::SESSION_KEY);
        if (is_string($id) && $id !== '') {
            $this->forget($id);
        }
    }

    /**
     * Take the pending link, once. Null when somebody already did.
     *
     * Atomic: the marker is added, never read-then-written, so of two simultaneous confirmations exactly one wins.
     *
     * @param  array{id: string}  $pending
     */
    public function claim(Request $request, array $pending): bool
    {
        if (! Cache::add('oauth_pending_link_claimed:'.hash('sha256', $pending['id']), 1, self::TTL_SECONDS)) {
            return false;
        }

        $this->forget($pending['id']);
        $request->session()->forget(self::SESSION_KEY);

        return true;
    }

    /**
     * Count a wrong password. At the cap the pending link is discarded.
     *
     * @param  array{id: string, wrong: int, user_id: int|string, provider: string}  $pending
     */
    public function recordWrongPassword(Request $request, array $pending): bool
    {
        $entry = $this->read($pending['id']);
        if ($entry === null) {
            return true;
        }

        $entry['wrong']++;
        if ($entry['wrong'] >= self::MAX_WRONG_PASSWORDS) {
            $this->discard($request);

            return true;
        }
        $this->write($pending['id'], $entry);

        return false;
    }

    /**
     * Mint the secret for the mailed link, when one may be sent now. Null while the cooldown or the cap holds.
     *
     * Only the hash is stored; each new secret replaces the last, so «send again» never widens what is usable.
     *
     * @param  array{id: string}  $pending
     */
    public function issueMailToken(array $pending): ?string
    {
        $entry = $this->read($pending['id']);
        if ($entry === null) {
            return null;
        }

        $now = now()->getTimestamp();
        if ($entry['mails'] >= self::MAX_MAILS || ($entry['mail_at'] !== null && $now - $entry['mail_at'] < self::MAIL_COOLDOWN_SECONDS)) {
            return null;
        }

        $secret = Str::random(64);
        $entry['mails']++;
        $entry['mail_at'] = $now;
        $entry['mail_token_hash'] = hash('sha256', $secret);
        $this->write($pending['id'], $entry);

        return $secret;
    }

    /** @param  array{mail_token_hash: ?string}  $pending */
    public function mailTokenMatches(array $pending, string $token): bool
    {
        $hash = $pending['mail_token_hash'] ?? null;

        return is_string($hash) && $token !== '' && hash_equals($hash, hash('sha256', $token));
    }

    /** `mo•••@example.com` — enough to recognise your own address, not enough to read someone else's. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1))).'•••@'.$domain;
    }

    /** One audit line; no address, no id, no token — what happened, to whom, through which provider. */
    public function record(string $action, int|string $userId, string $provider, string $detail): void
    {
        $user = User::query()->find($userId);

        $this->audit->log(
            $action,
            'user',
            $user !== null ? (string) $user->uuid : null,
            null,
            ['provider' => $provider, 'detail' => $detail],
            null,
            $user !== null ? (int) $user->getKey() : null,
        );
    }

    private function key(string $id): string
    {
        return 'oauth_pending_link:'.hash('sha256', $id);
    }

    /** @param  array<string, mixed>  $entry */
    private function write(string $id, array $entry): void
    {
        $remaining = max(1, (int) $entry['expires_at'] - now()->getTimestamp());
        Cache::put($this->key($id), Crypt::encryptString((string) json_encode($entry, JSON_THROW_ON_ERROR)), $remaining);
    }

    /** @return array<string, mixed>|null */
    private function read(string $id): ?array
    {
        $raw = Cache::get($this->key($id));
        if (! is_string($raw)) {
            return null;
        }

        try {
            $entry = json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        return is_array($entry) ? $entry : null;
    }

    private function forget(string $id): void
    {
        Cache::forget($this->key($id));
    }
}
