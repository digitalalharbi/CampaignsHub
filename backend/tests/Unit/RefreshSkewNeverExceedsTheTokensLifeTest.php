<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\OAuth\OAuthTokens;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * INTEG-OAUTH-001 — a refresh skew wider than the token's own life refreshes on EVERY call.
 *
 * ## The production symptom that found this
 *
 * `integrations:probe` against the live Snapchat account returned, with `calls made: 0`:
 *
 *     Snapchat Marketing API refused the token request (429): "Too Many Requests", url "/token"
 *
 * — repeatedly, hours apart, on an account whose connection is healthy. A 429 on the token
 * endpoint fails the WHOLE operation before a single business call is made, so this is not a
 * diagnostics inconvenience: a real sync dies the same way, and the run reports a provider refusal
 * for a reason that has nothing to do with the provider's data.
 *
 * ## The mechanism
 *
 * `isExpired()` treats a token as spent when it expires within `refresh_skew_minutes`, which is 60
 * for every platform. Snapchat issues a token that lives 1800 seconds. Thirty minutes is inside a
 * sixty-minute window from the moment it is issued, so the token is «expired» while still warm,
 * every `tokens()` call refreshes, and the quota goes.
 *
 * The skew exists to avoid starting a long sync on a token about to die. It was never meant to
 * exceed the life of the token it is protecting — at that point it stops being a safety margin and
 * becomes a guarantee of churn.
 *
 * ## The rule
 *
 * A token states its own life in `expires_in`, and `raw` keeps it. So the margin is capped at half
 * that life: a 30-minute token refreshes with 15 minutes left, a long-lived one keeps the full
 * configured hour. No per-platform table, and nothing to keep in step with a provider's choices.
 */
final class RefreshSkewNeverExceedsTheTokensLifeTest extends TestCase
{
    private function snapchatShaped(int $livesForSeconds): OAuthTokens
    {
        return new OAuthTokens(
            accessToken: 'at',
            refreshToken: 'rt',
            expiresAt: Carbon::now()->addSeconds($livesForSeconds),
            raw: ['expires_in' => $livesForSeconds],
        );
    }

    /** The defect itself: a freshly issued Snapchat token must not already be «expired». */
    public function test_a_token_issued_one_second_ago_is_not_already_spent(): void
    {
        $this->assertFalse(
            $this->snapchatShaped(1800)->isExpired(60),
            'a 30-minute token was reported expired the moment it was issued, so every call refreshes',
        );
    }

    /** …and it IS due once it has burned through half its life. */
    public function test_the_same_token_is_due_when_half_its_life_is_gone(): void
    {
        $nearlyDone = new OAuthTokens(
            accessToken: 'at',
            expiresAt: Carbon::now()->addMinutes(10),
            raw: ['expires_in' => 1800],
        );

        $this->assertTrue($nearlyDone->isExpired(60), 'a token with ten minutes left was not refreshed');
    }

    /**
     * A long-lived token keeps the full configured margin — the skew is capped, never shortened
     * for its own sake. Meta's sixty-day token must still refresh an hour before it dies.
     */
    public function test_a_long_lived_token_keeps_the_whole_configured_margin(): void
    {
        $meta = new OAuthTokens(
            accessToken: 'at',
            expiresAt: Carbon::now()->addMinutes(45),
            raw: ['expires_in' => 60 * 24 * 3600],
        );

        $this->assertTrue($meta->isExpired(60), 'a long-lived token inside the hour was not refreshed');

        $comfortable = new OAuthTokens(
            accessToken: 'at',
            expiresAt: Carbon::now()->addMinutes(90),
            raw: ['expires_in' => 60 * 24 * 3600],
        );

        $this->assertFalse($comfortable->isExpired(60), 'a token with 90 minutes left was refreshed early');
    }

    /** A token that states no life at all falls back to the configured skew, exactly as before. */
    public function test_a_token_that_states_no_life_behaves_as_it_always_did(): void
    {
        $unstated = new OAuthTokens(accessToken: 'at', expiresAt: Carbon::now()->addMinutes(30));

        $this->assertTrue($unstated->isExpired(60), 'without a stated life the configured skew must still apply');
    }

    /** And a token with no expiry is still never expired — Meta system tokens behave this way. */
    public function test_a_token_with_no_expiry_is_never_expired(): void
    {
        $this->assertFalse((new OAuthTokens(accessToken: 'at'))->isExpired(60));
    }
}
