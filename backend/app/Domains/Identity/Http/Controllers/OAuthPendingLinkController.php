<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\ResolveOAuthIdentity;
use App\Domains\Identity\Actions\SignInWithPassword;
use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Services\PendingOAuthLink;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailLinks;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Linking a Google account IN PLACE, after proof (LOGIN-004).
 *
 * The callback refused to treat a matching address as ownership and kept the verified profile in a {@see PendingOAuthLink}. These
 * endpoints are how the person holding that browser session proves the account is theirs:
 *
 *   - `password` — the account's password, checked by `SignInWithPassword`, the same code the sign-in form runs, behind the same
 *     `auth-login` allowance. A wrong password is the form's wrong password.
 *   - `email` + `confirm-email` — for an account with no password to give: a link to the ACCOUNT's own address, usable once, and
 *     only from the browser session that asked for it.
 *
 * Either way the pending link is claimed (once), the identity is linked, and only then does the session open — through the same
 * `openSession` the password form uses, so a suspended account, a wrong portal or a closed origin stops here exactly as it does there.
 * Nothing about the profile is in a URL, a log line or an error; every refusal is a short state.
 */
final class OAuthPendingLinkController extends Controller
{
    public function __construct(
        private readonly PendingOAuthLink $pending,
        private readonly ResolveOAuthIdentity $identities,
        private readonly SignInWithPassword $signIn,
        private readonly PortalResolver $portals,
        private readonly TransactionalMailer $mailer,
    ) {}

    /** GET /auth/oauth/pending-link — what the card shows. 410 when there is nothing to confirm. */
    public function show(Request $request): JsonResponse
    {
        $pending = $this->pending->peek($request);
        $user = $pending === null ? null : $this->matchedUser($request, $pending);
        if ($pending === null || $user === null) {
            return $this->gone();
        }

        return ApiResponse::success([
            'provider' => $pending['provider'],
            'name' => $pending['profile']['name'],
            'email' => $pending['profile']['email'],
            'avatar' => $pending['profile']['avatar'],
            'local_email' => PendingOAuthLink::mask((string) $user->email),
            'expires_in' => max(0, $pending['expires_at'] - now()->getTimestamp()),
            'email_sent' => $pending['mails'] > 0,
            'can_email' => ! $user->emailUnprovenByPolicy(),
        ]);
    }

    /** POST /auth/oauth/pending-link/password */
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:1024']]);

        $pending = $this->pending->peek($request);
        $user = $pending === null ? null : $this->matchedUser($request, $pending);
        if ($pending === null || $user === null) {
            return $this->gone();
        }

        try {
            $this->signIn->execute(
                $request,
                (string) $user->email,
                (string) $data['password'],
                true,
                $pending['portal'],
                $this->claimAndLink($request, $pending, 'auth.oauth_link_confirmed_by_password'),
            );
        } catch (ValidationException) {
            // The form's wrong password, on the field this card has. Five of them and the pending link is gone.
            $discarded = $this->pending->recordWrongPassword($request, $pending);
            $this->pending->record('auth.oauth_link_failed', $pending['user_id'], $pending['provider'], 'wrong_password');
            if ($discarded) {
                return $this->gone();
            }

            throw ValidationException::withMessages(['password' => [__('auth.failed')]]);
        } catch (HttpExceptionInterface|HttpResponseException $refusal) {
            // Suspended, wrong portal, ended review access: the sign-in's own answer, with nothing linked.
            $this->pending->record('auth.oauth_link_failed', $pending['user_id'], $pending['provider'], 'refused');

            throw $refusal;
        }

        return $this->signedIn($request, $user, $pending);
    }

    /** POST /auth/oauth/pending-link/email — mail a one-time link to the account's own address. */
    public function email(Request $request): JsonResponse
    {
        $pending = $this->pending->peek($request);
        $user = $pending === null ? null : $this->matchedUser($request, $pending);
        if ($pending === null || $user === null) {
            return $this->gone();
        }

        // An address nobody proved may belong to a stranger: it cannot receive proof of ownership.
        if ($user->emailUnprovenByPolicy()) {
            return ApiResponse::error('Use your password for this account.', null, ['state' => 'email_unavailable'], 422);
        }

        $secret = $this->pending->issueMailToken($pending);
        if ($secret === null) {
            return ApiResponse::error('Please wait a moment before asking again.', null, ['state' => 'wait'], 429);
        }

        $delivery = $this->mailer->send(
            recipient: Str::lower(trim((string) $user->email)),
            mail: new CredentialMail(
                purpose: CredentialMail::OAUTH_LINK,
                lang: 'ar',
                url: MailLinks::to('/login?'.http_build_query(['oauth' => 'link', 'confirm' => $secret])),
                expiresInMinutes: max(1, (int) ceil(($pending['expires_at'] - now()->getTimestamp()) / 60)),
                provider: ucfirst($pending['provider']),
            ),
            kind: 'oauth_link',
            template: 'mail.credential',
            userId: (int) $user->getKey(),
        );

        return ApiResponse::success([
            // Honest, as for every code: `awaiting_credentials` means nothing left.
            'delivery' => $delivery,
            'resend_after' => PendingOAuthLink::MAIL_COOLDOWN_SECONDS,
        ]);
    }

    /** POST /auth/oauth/pending-link/confirm-email — spend the mailed link, in the browser that asked for it. */
    public function confirmEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200']]);

        $pending = $this->pending->peek($request);
        $user = $pending === null ? null : $this->matchedUser($request, $pending);
        if ($pending === null || $user === null) {
            return $this->gone();
        }

        if (! $this->pending->mailTokenMatches($pending, (string) $data['token']) || $user->emailUnprovenByPolicy()) {
            $discarded = $this->pending->recordWrongPassword($request, $pending);
            $this->pending->record('auth.oauth_link_failed', $pending['user_id'], $pending['provider'], 'bad_link');

            return $discarded ? $this->gone() : ApiResponse::error('This link is not valid.', null, ['state' => 'invalid'], 422);
        }

        try {
            $this->signIn->openSession(
                $request,
                $user,
                true,
                $pending['portal'],
                $this->claimAndLink($request, $pending, 'auth.oauth_link_confirmed_by_email'),
            );
        } catch (HttpExceptionInterface|HttpResponseException $refusal) {
            $this->pending->record('auth.oauth_link_failed', $pending['user_id'], $pending['provider'], 'refused');

            throw $refusal;
        }

        return $this->signedIn($request, $user, $pending);
    }

    /** DELETE /auth/oauth/pending-link — «إلغاء». */
    public function destroy(Request $request): JsonResponse
    {
        $this->pending->discard($request);

        return ApiResponse::success(null);
    }

    /**
     * Run after every refusal and just before the session opens: claim the pending link (once), then link.
     *
     * @param  array<string, mixed>  $pending
     */
    private function claimAndLink(Request $request, array $pending, string $action): Closure
    {
        return function (User $user) use ($request, $pending, $action): void {
            /** @var array{id: string, provider: string, profile: array{sub: string, email: ?string, email_verified: bool, name: ?string, avatar: ?string}, user_id: int|string} $pending */
            if (! $this->pending->claim($request, $pending)) {
                throw new HttpResponseException($this->gone());
            }

            try {
                $this->identities->link($user, $pending['provider'], $pending['profile']);
            } catch (RuntimeException) {
                // That provider account already belongs to somebody else: refused, nothing linked, no session.
                $this->pending->record('auth.oauth_link_failed', $pending['user_id'], $pending['provider'], 'taken_by_another');

                throw new HttpResponseException(ApiResponse::error('This Google account cannot be connected.', null, ['state' => 'link_failed'], 409));
            }

            $this->pending->record($action, $user->getKey(), $pending['provider'], 'linked');
        };
    }

    /** @param  array<string, mixed>  $pending */
    private function signedIn(Request $request, User $user, array $pending): JsonResponse
    {
        $redirect = is_string($pending['redirect'] ?? null) ? $pending['redirect'] : null;

        return ApiResponse::success([
            'user' => new UserResource($user),
            // The same answer the password form and the Google callback get, by the same method.
            'destination' => $this->portals->postAuthDestination($user, Portal::tryFrom((string) ($pending['portal'] ?? '')), $redirect),
        ], __('auth.signed_in'));
    }

    /**
     * The account the pending link is for — and only while the address that matched is still that account's address.
     *
     * Anything else discards the pending link: an address that changed in the ten minutes is no longer the one the provider verified.
     *
     * @param  array<string, mixed>  $pending
     */
    private function matchedUser(Request $request, array $pending): ?User
    {
        $user = User::query()->find($pending['user_id']);
        $same = $user !== null
            && is_string($pending['profile']['email'] ?? null)
            && Str::lower((string) $user->email) === Str::lower((string) $pending['profile']['email']);

        if (! $same) {
            $this->pending->discard($request);

            return null;
        }

        return $user;
    }

    private function gone(): JsonResponse
    {
        return ApiResponse::error('This Google link has expired. Start again.', null, ['state' => 'expired'], 410);
    }
}
