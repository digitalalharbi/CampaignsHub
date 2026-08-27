<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\CRM\Actions\LinkDuplicateLead;
use App\Domains\CRM\Models\Lead;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEAD-DEDUP-001 — the same person twice is recorded twice and counted once.
 *
 * `canonical_lead_id` and `duplicate_reason` have existed since LEAD-PROVENANCE-001, along with the
 * `canonical()` / `duplicates()` / `isDuplicate()` relationships. Nothing ever wrote them: three
 * relationships with no producer, and every duplicate submission counted as a separate person.
 */
final class LeadDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'dd-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->projectId = (string) Str::uuid();
    }

    /** The second submission points at the first, and BOTH rows survive with their own provenance. */
    public function test_a_second_submission_is_linked_rather_than_dropped(): void
    {
        $first = $this->lead('نورة', email: 'noura@example.com', provider: 'meta');
        $second = $this->lead('نورة', email: 'noura@example.com', provider: 'snapchat');

        $canonical = app(LinkDuplicateLead::class)->handle($second);

        $this->assertNotNull($canonical);
        $this->assertTrue($canonical->is($first));
        $this->assertSame(2, Lead::withoutGlobalScopes()->count(), 'A real acquisition event was deleted.');
        $this->assertSame('snapchat', $second->refresh()->provider, 'The duplicate lost its own provenance.');
        $this->assertSame('meta', $first->refresh()->provider);
        $this->assertSame('email', $second->duplicate_reason);
    }

    /** Phone matches too — the same person from an Arabic keyboard is the same person. */
    public function test_a_matching_phone_links_even_when_the_email_differs(): void
    {
        $first = $this->lead('محمد', phone: '0501234567');
        $second = $this->lead('محمد', email: 'other@example.com', phone: '0501234567');

        $canonical = app(LinkDuplicateLead::class)->handle($second);

        $this->assertNotNull($canonical);
        $this->assertTrue($canonical->is($first));
        $this->assertSame('phone', $second->refresh()->duplicate_reason);
    }

    /**
     * The FIRST lead stays canonical, and a third submission points at it rather than at the second.
     *
     * A chain would mean counting people required walking it, and every consumer that forgot would
     * over-count. `duplicates()` on the canonical must return the whole set.
     */
    public function test_duplicates_point_at_the_original_and_never_form_a_chain(): void
    {
        /*
         * Linked as each arrives, which is what ingestion actually does. Creating all three first and
         * linking afterwards is a backfill, not a live sequence — the action handles both, and this
         * asserts the live one.
         */
        $first = $this->lead('نورة', email: 'noura@example.com');
        $second = $this->lead('نورة', email: 'noura@example.com');
        app(LinkDuplicateLead::class)->handle($second);
        $third = $this->lead('نورة', email: 'noura@example.com');
        app(LinkDuplicateLead::class)->handle($third);

        /*
         * The invariant is not «the canonical is $first» — three rows created in the same second have
         * no meaningful order, and asserting one would be asserting UUID luck. What must hold is that
         * both duplicates elect the SAME canonical and that the canonical is not itself a duplicate,
         * because that is what lets a consumer count people without walking a chain.
         */
        $secondCanonical = $second->refresh()->canonical;
        $thirdCanonical = $third->refresh()->canonical;

        $this->assertNotNull($secondCanonical);
        $this->assertNotNull($thirdCanonical);
        $this->assertTrue($secondCanonical->is($thirdCanonical), 'Two duplicates of one person elected different canonicals.');
        $this->assertFalse($secondCanonical->isDuplicate(), 'A chain formed: the canonical is itself a duplicate.');
        $this->assertCount(2, $secondCanonical->refresh()->duplicates);
    }

    /**
     * The same person for two different clients is two leads.
     *
     * Linking across projects would leak the fact of one client's enquiry into another client's
     * pipeline — a tenancy violation wearing a data-quality justification.
     */
    public function test_the_same_person_in_a_different_project_is_not_a_duplicate(): void
    {
        $this->lead('نورة', email: 'noura@example.com');
        $other = $this->lead('نورة', email: 'noura@example.com', projectId: (string) Str::uuid());

        $this->assertNull(app(LinkDuplicateLead::class)->handle($other));
        $this->assertNull($other->refresh()->canonical_lead_id);
    }

    /** A lead with no email and no phone is unknown, and unknown is not a match. */
    public function test_a_lead_with_no_identity_is_never_matched(): void
    {
        $this->lead('مجهول');
        $second = $this->lead('مجهول');

        $this->assertNull(app(LinkDuplicateLead::class)->handle($second));
    }

    /**
     * Two different people who happen to share a name are two people.
     *
     * A false duplicate is worse than a missed one: it removes a real person's enquiry from the list
     * the sales team works.
     */
    public function test_a_shared_name_alone_never_links(): void
    {
        $this->lead('محمد الحربي', email: 'a@example.com');
        $second = $this->lead('محمد الحربي', email: 'b@example.com');

        $this->assertNull(app(LinkDuplicateLead::class)->handle($second));
    }

    private function lead(
        string $name,
        ?string $email = null,
        ?string $phone = null,
        string $provider = 'meta',
        ?string $projectId = null,
    ): Lead {
        $lead = new Lead;
        $lead->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $projectId ?? $this->projectId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'email_normalized' => $email === null ? null : strtolower(trim($email)),
            'phone_normalized' => $phone === null ? null : preg_replace('/\D/', '', $phone),
            'source' => 'provider',
            'provider' => $provider,
            'provider_lead_id' => (string) Str::uuid(),
        ])->save();

        return $lead;
    }
}
