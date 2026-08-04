<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOGIN-UNIFIED-001 — the server, not the visitor, decides which sign-in form to show.
 *
 * These lock down the two properties that made the portal chooser removable: the answer is always
 * correct for a real account, and it never tells a stranger anything.
 */
final class SignInMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_platform_user_signs_in_with_a_password(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.test']);

        $this->postJson('/api/v1/auth/method', ['identifier' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.method', 'password');
    }

    public function test_the_lookup_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'operator@example.test']);

        $this->postJson('/api/v1/auth/method', ['identifier' => 'OPERATOR@Example.TEST'])
            ->assertOk()
            ->assertJsonPath('data.method', 'password');
    }

    public function test_a_client_contact_signs_in_with_a_one_time_code(): void
    {
        $this->contactOnARequest('client@example.test', 'REQ-METHOD-1');

        $this->postJson('/api/v1/auth/method', ['identifier' => 'client@example.test'])
            ->assertOk()
            ->assertJsonPath('data.method', 'code')
            ->assertJsonPath('data.channel', 'email');
    }

    /**
     * The collision that makes the "user wins" rule necessary.
     *
     * An agency operator is routinely named as the contact on a request they filed for a client.
     * Answering `code` for them would take somebody holding a real password and a real portal and
     * push them down the one-time-code path into `/portal`, which holds none of their work.
     */
    public function test_a_user_who_is_also_a_contact_still_signs_in_with_a_password(): void
    {
        User::factory()->create(['email' => 'both@example.test']);
        $this->contactOnARequest('both@example.test', 'REQ-METHOD-2');

        $this->postJson('/api/v1/auth/method', ['identifier' => 'both@example.test'])
            ->assertOk()
            ->assertJsonPath('data.method', 'password');
    }

    /**
     * An unknown address must NOT answer `code`.
     *
     * Two reasons, and either alone is sufficient: it would confirm to any stranger which addresses
     * are clients here, and it would send a real one-time code to somebody this platform has no
     * relationship with. `password` puts them on the form where a wrong identifier gets the same
     * uninformative answer as a wrong password.
     */
    public function test_an_unknown_email_is_sent_to_the_password_form_and_reveals_nothing(): void
    {
        $this->postJson('/api/v1/auth/method', ['identifier' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('data.method', 'password');
    }

    /** No account is addressable by phone + password, so a phone can only mean a code. */
    public function test_a_phone_number_signs_in_with_a_one_time_code(): void
    {
        $this->postJson('/api/v1/auth/method', ['identifier' => '0512345678'])
            ->assertOk()
            ->assertJsonPath('data.method', 'code')
            ->assertJsonPath('data.channel', 'sms');
    }

    public function test_an_identifier_is_required(): void
    {
        $this->postJson('/api/v1/auth/method', [])->assertStatus(422);
    }

    /**
     * The minimum row that makes an address a client contact.
     *
     * Inserted directly rather than through the model: `external_requests` requires a type and a
     * status by foreign key, and building the whole taxonomy would make these tests about the
     * request module instead of about the resolver. What is under test is one column —
     * `contact_email` — and this is the smallest honest fixture that produces it.
     */
    private function contactOnARequest(string $email, string $reference): void
    {
        $tenant = Tenant::create(['name' => 'Method', 'slug' => 'method-'.uniqid(), 'status' => 'active']);
        $typeId = DB::table('request_types')->insertGetId([
            'key' => 'probe-'.uniqid(), 'module' => 'paid_media', 'name_ar' => 'فحص', 'name_en' => 'Probe',
        ]);
        $statusId = DB::table('request_statuses')->insertGetId([
            'key' => 'probe-'.uniqid(), 'name_ar' => 'جديد', 'name_en' => 'New',
        ]);

        DB::table('external_requests')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'reference' => $reference,
            'type_id' => $typeId,
            'status_id' => $statusId,
            'contact_name' => 'Probe contact',
            'contact_email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
