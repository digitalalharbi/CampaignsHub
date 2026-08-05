<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Legal\Models\PolicyAcceptance;
use App\Domains\Legal\PolicyRegistry;
use App\Domains\Legal\Services\AcceptanceRecorder;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * LEGAL-003 — what somebody agreed to, and which words they were shown.
 *
 * Cookie consent is deliberately absent from this suite: the banner was withdrawn because this
 * application sets strictly necessary cookies only, and there is nothing to consent to. Agreeing to
 * the terms and the privacy policy is a separate act by a real person, and that is what is tested here.
 *
 * The property under test is that a record is EVIDENCE rather than a boolean. «Accepted the terms»
 * is worth little a year later; «accepted terms v1.0, effective 2026-08-07, from this address, while
 * registering» survives a dispute — but only because the version cannot be supplied by whoever is
 * accepting, and only because the text behind that version lives in git.
 */
final class ConsentAndAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ── policy acceptance ─────────────────────────────────────────────────────────────────────

    /**
     * The version is the server's, not the client's.
     *
     * A browser posting an old version would otherwise create a record saying somebody accepted
     * wording that suited whoever sent it.
     */
    public function test_the_recorded_version_comes_from_the_registry_not_the_request(): void
    {
        $user = $this->user();

        app(AcceptanceRecorder::class)->recordBinding(
            request: Request::create('/', 'POST'),
            context: 'registration',
            user: $user,
            accepted: PolicyRegistry::binding(),
        );

        foreach (PolicyRegistry::binding() as $slug) {
            $row = PolicyAcceptance::query()->where('user_id', $user->id)->where('document', $slug)->firstOrFail();
            $this->assertSame(PolicyRegistry::versionOf($slug), $row->version);
            $this->assertNotNull($row->effective);
            $this->assertNotNull($row->accepted_at);
        }
    }

    /**
     * An incomplete acceptance is refused, and the refusal names what is missing.
     *
     * Recording three of four would leave an account that looks compliant and is not.
     */
    public function test_an_incomplete_acceptance_is_refused_and_names_what_is_missing(): void
    {
        $binding = PolicyRegistry::binding();
        $partial = array_slice($binding, 0, count($binding) - 1);

        try {
            app(AcceptanceRecorder::class)->recordBinding(
                request: Request::create('/', 'POST'),
                context: 'registration',
                user: $this->user(),
                accepted: $partial,
            );
            $this->fail('an incomplete acceptance was recorded');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('accepted_policies', $e->errors());
            $this->assertStringContainsString(end($binding), $e->errors()['accepted_policies'][0]);
        }

        // Nothing at all was written — a partial record is worse than none.
        $this->assertSame(0, PolicyAcceptance::query()->count());
    }

    /** Acceptance taken before the account existed is joined to it afterwards. */
    public function test_an_acceptance_taken_before_registration_is_linked_to_the_account(): void
    {
        $recorder = app(AcceptanceRecorder::class);

        $recorder->recordBinding(
            request: Request::create('/', 'POST'),
            context: 'registration',
            email: 'later@example.test',
            accepted: PolicyRegistry::binding(),
        );

        $this->assertSame(0, PolicyAcceptance::query()->whereNotNull('user_id')->count());

        $user = User::create([
            'name' => 'Later', 'email' => 'later@example.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $recorder->linkToUser($user);

        $this->assertSame(
            count(PolicyRegistry::binding()),
            PolicyAcceptance::query()->where('user_id', $user->id)->count(),
        );
    }

    /**
     * Re-accepting the same version is recorded again, not discarded.
     *
     * A second purchase is a separate fact with its own timestamp, and it is exactly the evidence
     * somebody would later want.
     */
    public function test_accepting_twice_keeps_both_records(): void
    {
        $user = $this->user();
        $recorder = app(AcceptanceRecorder::class);

        foreach (['registration', 'payment'] as $context) {
            $recorder->recordBinding(
                request: Request::create('/', 'POST'),
                context: $context,
                user: $user,
                accepted: PolicyRegistry::binding(),
            );
        }

        $this->assertSame(
            count(PolicyRegistry::binding()) * 2,
            PolicyAcceptance::query()->where('user_id', $user->id)->count(),
        );
        $this->assertSame(1, PolicyAcceptance::query()->where('context', 'payment')->where('document', 'terms')->count());
    }

    // ── what is still owed ────────────────────────────────────────────────────────────────────

    public function test_a_user_who_has_accepted_nothing_owes_every_binding_document(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/legal/outstanding')->assertOk();

        $this->assertSame(PolicyRegistry::binding(), $res->json('data.outstanding'));
    }

    public function test_accepting_clears_what_was_outstanding(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/legal/accept', ['accepted_policies' => PolicyRegistry::binding()])
            ->assertOk();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/legal/outstanding')->assertOk();

        $this->assertSame([], $res->json('data.outstanding'));
        $this->assertSame('reacceptance', PolicyAcceptance::query()->where('user_id', $user->id)->first()->context);
    }

    /** Signing out does not make the acceptance endpoint public. */
    public function test_the_acceptance_endpoint_refuses_an_anonymous_caller(): void
    {
        $this->postJson('/api/v1/legal/accept', ['accepted_policies' => PolicyRegistry::binding()])
            ->assertUnauthorized();
    }

    private function user(): User
    {
        return User::create([
            'name' => 'U', 'email' => 'consent-'.uniqid().'@example.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
    }
}
