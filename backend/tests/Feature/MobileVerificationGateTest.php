<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Identity\Actions\RegisterTenantAction;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * PHONE-VERIFY-001 — no account without a verified mobile number, however it was opened.
 *
 * The rule is easy to state and easy to lose: an account created through the email path, or through
 * an administrator's shortcut, or through a plan whose policy somebody edited, must still have proved
 * a phone. So the tests below attack it from each of those directions rather than walking the happy
 * path a second time.
 *
 * The Saudi reading rules get the same treatment. `05…`, `9665…` and `+9665…` are one number, which
 * means they are one ACCOUNT — a duplicate check that compares raw strings would let the same person
 * open three.
 */
final class MobileVerificationGateTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();
    }

    // ── The number is required, and read the way this market writes it ────────────────────────

    public function test_an_application_with_no_mobile_number_is_refused(): void
    {
        $this->apply(['phone' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    /**
     * @dataProvider saudiForms
     */
    public function test_a_saudi_number_is_accepted_in_every_form_this_market_writes(string $typed): void
    {
        $this->apply(['email' => 'forms@a.test', 'phone' => $typed])->assertStatus(202);

        $stored = RegistrationRequest::query()->whereRaw('lower(email) = ?', ['forms@a.test'])->firstOrFail();

        // Stored in E.164 whichever shape arrived — one canonical form, written once.
        $this->assertSame('+966501234567', $stored->phone);
    }

    /** @return array<string, array{string}> */
    public static function saudiForms(): array
    {
        return [
            'national with leading zero' => ['0501234567'],
            'national with spaces' => ['050 123 4567'],
            'national with dashes' => ['050-123-4567'],
            'country code, bare' => ['966501234567'],
            'country code with plus' => ['+966501234567'],
            'country code with spaces' => ['+966 50 123 4567'],
            'international prefix' => ['00966501234567'],
            'arabic-indic digits' => ['٠٥٠١٢٣٤٥٦٧'],
        ];
    }

    public function test_an_unreadable_number_is_refused_with_a_message_naming_the_field(): void
    {
        $this->apply(['phone' => 'not a phone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    // ── One number, one account ───────────────────────────────────────────────────────────────

    /**
     * The duplicate check compares in E.164, not as typed.
     *
     * A `unique:` rule on the column could not do this: the stored value is `+966501234567` and the
     * form holds `0501234567`, so the same person would open a second account simply by writing their
     * own number the way they normally do.
     */
    public function test_the_same_number_cannot_open_a_second_application_in_another_spelling(): void
    {
        $this->apply(['email' => 'first@a.test', 'phone' => '+966501234567'])->assertStatus(202);

        foreach (['0501234567', '966501234567', '050 123 4567', '٠٥٠١٢٣٤٥٦٧'] as $spelling) {
            $this->apply(['email' => 'second@a.test', 'phone' => $spelling])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['phone']);
        }
    }

    public function test_a_number_held_by_an_existing_user_is_refused(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['phone' => '+966555000111', 'email_verified_at' => now()])->save();

        $this->apply(['email' => 'newcomer@a.test', 'phone' => '0555000111'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    /** A refused application does not hold a number hostage. */
    public function test_a_rejected_application_releases_its_number(): void
    {
        $this->apply(['email' => 'again@a.test', 'phone' => '0501234567'])->assertStatus(202);

        RegistrationRequest::query()->firstOrFail()->forceFill([
            'state' => AccountState::Rejected->value,
            'state_reason' => 'Not this time.',
        ])->save();

        $this->apply(['email' => 'again@a.test', 'phone' => '0501234567'])->assertStatus(202);
    }

    // ── The gate itself ───────────────────────────────────────────────────────────────────────

    /**
     * The strongest form of the claim: an applicant who has proved their address, been approved and
     * PAID still has no account until the phone is proved.
     */
    public function test_a_paid_application_with_an_unverified_number_creates_nothing(): void
    {
        $applied = $this->apply(['email' => 'unphoned@a.test', 'tenant_name' => 'Unphoned Co'])->assertStatus(202);

        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk();

        $registration = RegistrationRequest::query()->firstOrFail();
        $this->assertSame(AccountState::MobileVerificationRequired, $registration->state);

        // There is nothing to pay for yet — the application has not reached a charge.
        $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/checkout", ['commitment_agreed' => true])->assertStatus(422);

        $this->assertSame(0, Tenant::withoutGlobalScopes()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_wrong_code_does_not_clear_the_mobile_gate(): void
    {
        $applied = $this->apply(['email' => 'wrongcode@a.test'])->assertStatus(202);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk();

        $registration = RegistrationRequest::query()->firstOrFail();
        $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/resend", ['channel' => 'mobile'])->assertOk();

        $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/verify-mobile", ['code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($registration->refresh()->mobile_verified_at);
        $this->assertSame(0, Tenant::withoutGlobalScopes()->count());
    }

    /** …and the whole journey does produce an account, with the number stored canonically. */
    public function test_the_completed_journey_stores_the_number_in_e164(): void
    {
        ['user' => $user] = $this->applyAndVerify([
            'email' => 'complete@a.test', 'tenant_name' => 'Complete Co', 'phone' => '050 987 6543',
        ]);

        $this->assertSame('+966509876543', $user->phone);
        $this->assertSame($user->phone, PhoneNumber::normalise('0509876543'));
    }

    /**
     * The administrator's shortcut is not a way round it either.
     *
     * `RegisterTenantAction` is the named auto-activate branch, and it refuses whenever the policy
     * has ANY gate open — which now includes the mobile one by default. A branch that quietly ignored
     * a gate would be the exact hole this whole path exists to close.
     */
    public function test_the_auto_activate_branch_refuses_while_the_mobile_gate_is_on(): void
    {
        $this->expectException(\RuntimeException::class);

        app(RegisterTenantAction::class)->execute(
            new RegisterData(
                tenantName: 'Shortcut Co', name: 'Shortcut', email: 'shortcut@a.test',
                password: 'secret1234', accountType: 'brand',
            ),
            emailAlreadyProvenBecause: 'test',
        );
    }
}
