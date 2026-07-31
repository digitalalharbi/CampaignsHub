<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\OAuthIdentity;
use App\Models\User;
use RuntimeException;

/**
 * Turn a verified provider identity into a local account — or refuse, clearly (LOGIN-004).
 *
 * Three outcomes, and the two refusals are the interesting ones:
 *
 *   1. **A link already exists** → sign that user in. The only case that authenticates.
 *   2. **No link, but the email matches a local account** → REFUSE. This is the case everyone gets
 *      wrong. Treating a matching email as proof of ownership means anyone who can make a provider
 *      assert an address — including a provider that never verified it — takes over the local
 *      account that uses it. Linking is an action the account's owner performs from inside their
 *      own session, where they have already proven who they are.
 *   3. **No link and no local account** → REFUSE. Signing in is not a way to register. The contract
 *      routes new accounts through the gated path (type → plan → verification → approval → payment),
 *      and a provider callback that quietly minted an active tenant would walk straight past all of
 *      it.
 */
final class ResolveOAuthIdentity
{
    public const LINK_REQUIRED = 'link_required';

    public const REGISTRATION_REQUIRED = 'registration_required';

    /**
     * @param  array{sub: string, email: ?string, email_verified: bool, name: ?string, avatar: ?string}  $profile
     * @return array{user: User, identity: OAuthIdentity}
     */
    public function execute(string $provider, array $profile): array
    {
        if ($profile['sub'] === '') {
            throw new RuntimeException('The provider did not identify the account.');
        }

        $identity = OAuthIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $profile['sub'])
            ->first();

        if ($identity !== null) {
            $identity->forceFill([
                // Refreshed for display; never used to find the account.
                'email' => $profile['email'],
                'email_verified' => $profile['email_verified'],
                'name' => $profile['name'],
                'avatar_url' => $profile['avatar'],
                'last_login_at' => now(),
            ])->save();

            return ['user' => $identity->user, 'identity' => $identity];
        }

        $email = $profile['email'];
        $existing = $email === null ? null : User::where('email', $email)->first();

        if ($existing !== null) {
            throw new OAuthOutcome(
                self::LINK_REQUIRED,
                'An account already uses this email address. Sign in with your password first, then connect '
                .ucfirst($provider).' from your account settings.',
            );
        }

        throw new OAuthOutcome(
            self::REGISTRATION_REQUIRED,
            'No CampaignsHub account is connected to this '.ucfirst($provider).' account. Create an account first.',
        );
    }

    /**
     * Link a provider account to the CURRENTLY SIGNED-IN user.
     *
     * The safe half of the story: the person is already authenticated, so attaching an identity is
     * something they are doing to their own account rather than something a callback is doing on
     * their behalf.
     *
     * @param  array{sub: string, email: ?string, email_verified: bool, name: ?string, avatar: ?string}  $profile
     */
    public function link(User $user, string $provider, array $profile): OAuthIdentity
    {
        $takenByAnother = OAuthIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $profile['sub'])
            ->where('user_id', '!=', $user->getKey())
            ->exists();

        if ($takenByAnother) {
            throw new RuntimeException('That '.ucfirst($provider).' account is already connected to another user.');
        }

        return OAuthIdentity::updateOrCreate(
            ['user_id' => $user->getKey(), 'provider' => $provider],
            [
                'provider_user_id' => $profile['sub'],
                'email' => $profile['email'],
                'email_verified' => $profile['email_verified'],
                'name' => $profile['name'],
                'avatar_url' => $profile['avatar'],
                'linked_at' => now(),
            ],
        );
    }
}
