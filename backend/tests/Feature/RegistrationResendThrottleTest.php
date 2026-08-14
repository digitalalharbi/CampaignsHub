<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SIGNUP-THROTTLE-001 — "send me the code again" must be limited PER APPLICANT, not per address.
 *
 * ## The defect, and why it is a customer-facing one
 *
 * `POST /auth/registration/{registration}/resend` carried a literal `throttle:6,1`. Laravel keys a
 * literal throttle by the authenticated user or, for a guest, by the IP — and every caller of this
 * endpoint is a guest by definition, because an applicant has no account yet. So the allowance was
 * six resends per minute **shared by everyone behind one address**.
 *
 * An office, a campus, a café, a hotel, or any carrier doing CGNAT is one address. Two colleagues
 * signing up one after the other compete for the same six; the second is told «تم تجاوز عدد
 * المحاولات» about a code they have asked for once. The thing the control exists to protect — not
 * spamming an applicant's phone, and not burning SMS credit — is a property of the APPLICATION, and
 * the key never mentioned it.
 *
 * The same class of defect was found and fixed once before on `/register` (APP-100), whose comment
 * calls it «the last public endpoint still throttled inline». It was not the last; this one was
 * missed, and it is the last.
 *
 * ## How it presented
 *
 * As a test failure that looked like a broken sign-up page. The acceptance suite opens two accounts
 * per browser project from one address, and each has to answer the mobile gate. Its resend clicks
 * plus the sibling test's exhausted the per-IP six, so the status page rendered «Too many requests»
 * where the dev code should have been, and the walk failed at «no dev code was issued» — a rate
 * limit wearing the costume of a missing feature. Neither raising the number nor retrying the test
 * would have been a fix: the first weakens a real control, the second hides a real defect.
 *
 * ## What replaces it
 *
 * Two limits, both enforced, in the shape the risk actually has:
 *
 * - **per application** — the control that matters, and in production it is now STRICTER than the
 *   six it replaces, because one applicant has no legitimate reason to need more.
 * - **per address** — an abuse ceiling, so one machine cannot open applications in a loop and pump
 *   messages through them. It no longer punishes the applicant who happens to share an address with
 *   somebody else.
 */
final class RegistrationResendThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        // The production figures, applied to the off-production keys this environment reads — so the
        // test drives the real limiter with real numbers instead of asserting against 600.
        config()->set('accounts.resend_throttle_per_application_local', 3);
        config()->set('accounts.resend_throttle_per_address_local', 12);
    }

    private function applicant(string $email): RegistrationRequest
    {
        $request = new RegistrationRequest;
        $request->forceFill([
            'email' => $email,
            'name' => 'Applicant',
            'tenant_name' => 'Applicant Co',
            'account_type' => 'brand',
            'requested_portal' => 'app',
            'plan_code' => 'growth',
            'phone' => '+966500000001',
            'password' => Hash::make('secret1234'),
            'state' => AccountState::MobileVerificationRequired->value,
        ])->save();

        return $request->refresh();
    }

    private function resend(RegistrationRequest $registration): TestResponse
    {
        return $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/resend", ['channel' => 'mobile']);
    }

    /**
     * **The defect, pinned.** One applicant exhausting their own allowance must not silence another.
     *
     * Both applications are hit from the same address — the default test client sends one IP, which
     * is exactly the shared-address situation being described. Under the old literal throttle the
     * fourth request in this test came back 429 no matter whose application it belonged to.
     */
    public function test_one_applicant_cannot_use_up_another_applicants_allowance(): void
    {
        $first = $this->applicant('first@a.test');
        $second = $this->applicant('second@a.test');

        for ($i = 0; $i < 3; $i++) {
            $this->resend($first)->assertOk();
        }

        // The first applicant is now spent…
        $this->resend($first)->assertStatus(429);

        // …and the second has not asked for anything at all.
        $this->resend($second)->assertOk();
    }

    /** The control still exists: an applicant asking over and over is refused. */
    public function test_an_applicant_is_still_limited_on_their_own_application(): void
    {
        $registration = $this->applicant('loud@a.test');

        for ($i = 0; $i < 3; $i++) {
            $this->resend($registration)->assertOk();
        }

        $this->resend($registration)->assertStatus(429);
    }

    /**
     * And the address ceiling still bites, so opening applications in a loop is not a way around it.
     *
     * Twelve are allowed from one address; the thirteenth is refused even though it belongs to a
     * thirteenth application that has never asked for anything.
     */
    public function test_the_address_ceiling_still_stops_a_loop_of_fresh_applications(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->resend($this->applicant("bulk{$i}@a.test"))->assertOk();
        }

        $this->resend($this->applicant('bulk-last@a.test'))->assertStatus(429);
    }
}
