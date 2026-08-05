<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Contact;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHONE-001 — normalisation where it actually has to hold: through the models and through the API.
 *
 * `PhoneNumberTest` proves the reading is right. This proves it is REACHED — that a number arriving
 * by any of the ordinary routes ends up in one shape in the database, without the caller doing
 * anything. That distinction is the whole point of putting it on the model: the unit test would keep
 * passing if every call site quietly bypassed it.
 */
final class PhoneNormalisationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // The request catalogue is a structural seeder, not fixture data — `external_requests.type_id`
        // is NOT NULL and every real request carries one.
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    public function test_a_contact_saved_in_national_format_is_stored_as_e164(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $contact = Contact::create([
            'client_workspace_id' => $ws->id,
            'name' => 'Sara',
            'phone' => '050 123 4567',
        ]);

        $this->assertSame('+966501234567', $contact->fresh()->phone);
    }

    public function test_arabic_indic_digits_are_stored_as_e164(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2', 'mode' => 'managed']);
        $contact = Contact::create([
            'client_workspace_id' => $ws->id,
            'name' => 'Omar',
            'phone' => '٠٥٠٧٧٧٨٨٨٨',
        ]);

        $this->assertSame('+966507778888', $contact->fresh()->phone);
    }

    public function test_a_foreign_number_keeps_its_own_country(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C3', 'slug' => 'c3', 'mode' => 'managed']);
        $contact = Contact::create([
            'client_workspace_id' => $ws->id,
            'name' => 'Nadia',
            'phone' => '+20 123 456 7890',
        ]);

        $this->assertSame('+201234567890', $contact->fresh()->phone);
    }

    /**
     * The rule that protects data: a number the normaliser cannot read is KEPT, not blanked.
     *
     * Some contacts genuinely have «ask reception» or an extension in the field. Losing that because a
     * parser did not recognise it would be destroying information the user deliberately entered.
     */
    public function test_an_unreadable_number_is_kept_rather_than_discarded(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C4', 'slug' => 'c4', 'mode' => 'managed']);
        $contact = Contact::create([
            'client_workspace_id' => $ws->id,
            'name' => 'Front desk',
            'phone' => 'ask reception',
        ]);

        $this->assertSame('ask reception', $contact->fresh()->phone);
    }

    /** A user editing their own profile gets the same treatment as every other write path. */
    public function test_the_profile_endpoint_normalises(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['phone' => '050-123-4567'])
            ->assertOk();

        $this->assertSame('+966501234567', $user->fresh()->phone);
    }

    /**
     * The national format must be ACCEPTED by the profile endpoint, not merely normalised if it slips
     * through. It used to be rejected by a regex that required a leading «+».
     */
    public function test_the_profile_endpoint_accepts_the_national_format(): void
    {
        $user = User::create(['name' => 'U2', 'email' => 'u2@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['phone' => '0501234567'])
            ->assertOk();
    }

    public function test_the_profile_endpoint_refuses_something_that_is_not_a_number(): void
    {
        $user = User::create(['name' => 'U3', 'email' => 'u3@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['phone' => 'call me maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    /**
     * The point of all of it: two customers who wrote the same phone differently are one phone.
     *
     * Written against the stored column rather than a helper, because a duplicate check that queried
     * the raw string is exactly what used to let the two coexist.
     */
    public function test_two_spellings_of_one_number_land_on_the_same_stored_value(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C5', 'slug' => 'c5', 'mode' => 'managed']);
        Contact::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'phone' => '0501234567']);
        Contact::create(['client_workspace_id' => $ws->id, 'name' => 'B', 'phone' => '+966 50 123 4567']);

        $this->assertSame(2, Contact::where('phone', '+966501234567')->count());
    }

    /** An external request arriving from the public intake form is normalised the same way. */
    public function test_an_external_request_phone_is_normalised(): void
    {
        $request = ExternalRequest::create([
            'reference' => 'REQ-TEST-1',
            'type_id' => DB::table('request_types')->value('id'),
            'status_id' => DB::table('request_statuses')->value('id'),
            'contact_name' => 'Guest',
            'contact_email' => 'g@example.test',
            'contact_phone' => '٠٥٠ ١٢٣ ٤٥٦٧',
            'status' => 'new',
        ]);

        $this->assertSame('+966501234567', $request->fresh()->contact_phone);
    }

    /**
     * A proof issued for a number is a proof for that number however it is written the second time.
     *
     * The intake form now accepts every shape of the same phone, which makes this reachable: the
     * customer verifies «0501234567», the review step shows it back as «+966501234567», and a raw
     * string comparison then calls that a different phone. The request is refused with «verify your
     * phone and email» printed beside a green tick saying it already is — an error nobody can act on.
     */
    public function test_a_verification_proves_the_same_number_written_another_way(): void
    {
        $service = app(ContactVerificationService::class);
        $started = $service->start('sms', '0501234567');
        $service->verify($started['id'], (string) $started['dev_code']);

        $this->assertTrue($service->consumeVerified($started['id'], '+966501234567'));
    }

    /** …and is still not a proof for a DIFFERENT number, which is the only thing this check is for. */
    public function test_a_verification_does_not_prove_another_number(): void
    {
        $service = app(ContactVerificationService::class);
        $started = $service->start('sms', '0501234567');
        $service->verify($started['id'], (string) $started['dev_code']);

        $this->assertFalse($service->consumeVerified($started['id'], '0509999999'));
    }

    /**
     * An email is still matched exactly.
     *
     * Loosening the phone comparison must not loosen the others — for an address there is no second
     * valid spelling to accommodate, so anything looser only widens what one proof can be replayed against.
     */
    public function test_an_email_verification_is_still_matched_exactly(): void
    {
        $service = app(ContactVerificationService::class);
        $started = $service->start('email', 'guest@example.test');
        $service->verify($started['id'], (string) $started['dev_code']);

        $this->assertFalse($service->consumeVerified($started['id'], 'GUEST@example.test'));
    }
}
