<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LEGAL-THROTTLE-001 — asking to be deleted must not be rationed by the address you ask from.
 *
 * ## The defect
 *
 * `POST /data-deletion` and `POST /data-requests` each carried a literal
 * `throttle:5,1`. A literal throttle is keyed by the authenticated user or, failing that, the IP —
 * and both endpoints exist precisely FOR people with no account, so in practice the key was always
 * the address. Five data-subject requests a minute, shared by everybody behind it.
 *
 * This is the same shape as SIGNUP-THROTTLE-001 on the resend endpoint, on the flow where it matters
 * most. `/data-deletion` is the URL given to Meta, TikTok, Snapchat and Google as the platform's
 * data-deletion contact: it is opened by a reviewer, and it is the one right that has to work when
 * the customer has already lost access to everything else. An agency filing on behalf of several
 * clients, a household, or a review team working from one office all hit a wall that says «عدد كبير
 * من الطلبات» to somebody who has asked exactly once.
 *
 * ## How it was found
 *
 * `legal-public-urls.spec.ts` — «the deletion page is a working flow» — failed in a full run and
 * passed alone. The page snapshot showed the form intact with the throttle notice above it, which is
 * what ruled out the page and pointed at the limiter: nothing was broken, the request was refused.
 *
 * ## What replaces it
 *
 * The subject of the request is the key, with the address kept as an abuse ceiling — the same
 * two-limit shape the resend endpoint now uses, and for the same reason: the risk being controlled
 * (do not let somebody be spammed, or the mailer abused) belongs to the person named in the request,
 * not to the router they happen to sit behind.
 */
final class DataSubjectRequestThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Production figures, applied to the keys this environment reads.
        config()->set('security.data_request_throttle_per_subject_local', 3);
        config()->set('security.data_request_throttle_per_address_local', 12);
    }

    /** @return array<string, string> */
    private function payload(string $email): array
    {
        return ['type' => 'delete_account', 'name' => 'A Person', 'email' => $email];
    }

    /**
     * **The defect, pinned.** One person's requests must not silence the next person's.
     *
     * Both come from the same address, which is the whole situation: an office, a household, a
     * carrier NAT, or a platform reviewer testing from the same desk as a colleague.
     */
    public function test_one_subject_cannot_use_up_another_subjects_allowance(): void
    {
        // Ask as one person until the platform refuses — deliberately without naming a number, so
        // this states the KEYING and stays true whatever the allowance is set to.
        $attempts = 0;
        do {
            $response = $this->postJson('/api/v1/data-deletion', $this->payload('first@a.test'));
            $attempts++;
        } while ($response->status() !== 429 && $attempts < 30);

        $this->assertSame(429, $response->status(), 'the control has to refuse somebody eventually');

        // Somebody else, same address, who has asked for nothing at all.
        $this->postJson('/api/v1/data-deletion', $this->payload('second@a.test'))->assertOk();
    }

    /** The control still exists: one subject filing over and over is refused. */
    public function test_a_subject_is_still_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/data-deletion', $this->payload('loud@a.test'))->assertOk();
        }

        $this->postJson('/api/v1/data-deletion', $this->payload('loud@a.test'))->assertStatus(429);
    }

    /** And the address ceiling still stops a loop of fresh addresses being used as a mailer. */
    public function test_the_address_ceiling_still_stops_a_loop_of_fresh_subjects(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/v1/data-deletion', $this->payload("bulk{$i}@a.test"))->assertOk();
        }

        $this->postJson('/api/v1/data-deletion', $this->payload('bulk-last@a.test'))->assertStatus(429);
    }

    /** The general data-subject intake is the same endpoint class and gets the same treatment. */
    public function test_the_general_data_request_intake_is_keyed_the_same_way(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/data-requests', $this->payload('subject@a.test'))->assertOk();
        }

        $this->postJson('/api/v1/data-requests', $this->payload('subject@a.test'))->assertStatus(429);
        $this->postJson('/api/v1/data-requests', $this->payload('other@a.test'))->assertOk();
    }
}
