<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROVIDER-AUTOFILL-001 — the browser filled the admin's login email into provider client-id fields
 * and saved it. An email-shaped identifier is refused on save, and one already stored is flagged on
 * the provider card (as a fact, never the value).
 */
final class ProviderAutofillGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Platform', 'email' => 'owner@platform.test', 'password' => 'secret123']);
        $this->owner->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    public function test_an_email_shaped_client_id_is_refused_and_nothing_is_stored(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/linkedin', ['client_id' => 'owner@platform.test', 'client_secret' => 'abc123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);

        $this->assertNull(app(ProviderConfigurationService::class)->values('linkedin')['client_id'] ?? null);
    }

    public function test_a_real_client_id_is_saved(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/linkedin', ['client_id' => '86abcdefgh1234', 'client_secret' => 'abc123'])
            ->assertOk();

        $this->assertSame('86abcdefgh1234', app(ProviderConfigurationService::class)->values('linkedin')['client_id']);
    }

    public function test_a_stored_email_shaped_id_is_flagged_without_its_value(): void
    {
        // Stored before the guard existed — the state Production is in.
        app(ProviderConfigurationService::class)->save('linkedin', ['client_id' => 'someone@example.com', 'client_secret' => 'x']);

        $row = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/admin/settings/integrations/providers')->assertOk()->json('data.providers'))
            ->firstWhere('key', 'linkedin');

        $this->assertContains('client_id_looks_like_email', $row['warnings']);
        $this->assertStringNotContainsString('someone@example.com', (string) json_encode($row));
    }
}
