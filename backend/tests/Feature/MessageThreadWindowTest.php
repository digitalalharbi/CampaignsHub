<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Messaging\Services\MessagingService;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MESSAGE-THREAD-TRUTH-001 — a long conversation shows its LATEST messages, and says what it withheld.
 *
 * Both sides read the thread as `orderBy('created_at')->limit(500)` — ascending, then capped. Past
 * five hundred messages that is the OLDEST five hundred: the client opens the conversation, sees a
 * discussion from months ago, and the reply they were sent this morning is not on the page. Worse, the
 * client route marks the whole thread read on the way out, so the unread badge clears for messages
 * nobody was shown.
 *
 * Nothing fails when it happens. The request is 200, the shape is right, and the count is under the
 * cap the code asked for — which is exactly the silent truncation the Owner's rule names: no hidden
 * rows, and a total that tells the truth.
 *
 * Both sides are asserted here because both carry the same line, and a fix to one would leave the
 * agency reading a different conversation from the client it is having it with.
 */
final class MessageThreadWindowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        /* `is_default_portal`: the client portal resolves its tenant from the host, and falls back to this. */
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    /**
     * A thread with more messages than the window, numbered so the newest is identifiable.
     *
     * `saveQuietly` on an explicit `created_at`: the column is not fillable, so passing it to the
     * service would be silently dropped and every row would share a timestamp — which would make the
     * ordering a tie and this test unable to tell the newest from the oldest.
     */
    private function longThread(int $count): MessageThread
    {
        $thread = app(MessagingService::class)->openThread([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->client->id,
            'subject' => 'A long conversation',
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $message = app(MessagingService::class)->postMessage(
                $thread,
                $i % 2 === 0 ? 'client' : 'team',
                "message {$i}",
            );
            $message->forceFill(['created_at' => now()->subMinutes($count - $i)])->saveQuietly();
        }

        return $thread->refresh();
    }

    /**
     * A contact the portal will authenticate, reachable on the workspace these threads belong to.
     *
     * The portal identifies a person by the request that introduced them, not by a contacts row, so
     * this creates the request rather than the shape a reader might expect.
     */
    private function contactEmail(): string
    {
        $email = 'contact-'.uniqid().'@example.test';

        ExternalRequest::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-'.strtoupper(substr(md5($email), 0, 8)),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Contact',
            'contact_email' => $email,
            'contact_phone' => '+966500000000',
            'client_id' => $this->client->id,
            'journey_stage' => 'awaiting_client_approval',
            'submitted_at' => now(),
        ]);

        return $email;
    }

    /** Log in through the portal's own OTP flow and return the dev token for the header. */
    private function portalLogin(string $email): string
    {
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])->assertCreated();

        return $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');
    }

    /** A team member who may read a thread, which is what the agency side of this test needs. */
    private function teamMember(): User
    {
        $user = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user->assignRole($role);

        /* Portal::Agency, not the default App: `/api/v1/messaging` is an agency-portal route. */
        $this->grantMembership($user, $this->tenant, Portal::Agency);

        return $user;
    }

    public function test_the_client_sees_the_newest_messages_not_the_oldest(): void
    {
        $thread = $this->longThread(520);

        $token = $this->portalLogin($this->contactEmail());

        $response = $this->withHeader('X-Client-Token', $token)
            ->getJson("/api/v1/client/messages/{$thread->id}")
            ->assertOk();

        $bodies = array_column($response->json('data.messages'), 'body');

        $this->assertContains('message 520', $bodies, 'the newest message is not in the window the client was shown');
        $this->assertSame('message 520', end($bodies), 'the window is not in reading order — the newest must be last');
    }

    public function test_the_client_is_told_that_older_messages_were_withheld(): void
    {
        $thread = $this->longThread(520);

        $response = $this->withHeader('X-Client-Token', $this->portalLogin($this->contactEmail()))
            ->getJson("/api/v1/client/messages/{$thread->id}")
            ->assertOk();

        $this->assertSame(520, $response->json('data.messages_total'));
        $this->assertGreaterThan(0, $response->json('data.messages_withheld'));
    }

    public function test_the_team_reads_the_same_end_of_the_conversation_as_the_client(): void
    {
        $thread = $this->longThread(520);

        $response = $this->actingAs($this->teamMember(), 'sanctum')
            ->getJson("/api/v1/messaging/threads/{$thread->id}")
            ->assertOk();

        $bodies = array_column($response->json('data.messages'), 'body');

        $this->assertSame('message 520', end($bodies), 'the team is reading a different end of the conversation from the client');
        $this->assertSame(520, $response->json('data.messages_total'));
        $this->assertGreaterThan(0, $response->json('data.messages_withheld'));
    }

    /**
     * The withheld messages are REACHABLE, and the two windows join without a gap or a repeat.
     *
     * Naming a count the reader cannot act on is half a fix: «twenty older messages exist» with no way
     * to open them leaves the conversation just as unreadable, only honestly so.
     *
     * The join is the assertion that matters. Paging on `created_at` alone would skip or duplicate
     * every message sharing a second with the cursor — which a burst routinely produces — so the two
     * windows are concatenated and checked for BOTH a gap and a repeat, against the whole thread.
     */
    public function test_the_older_messages_can_be_opened_and_the_windows_join_exactly(): void
    {
        $thread = $this->longThread(520);
        $user = $this->teamMember();

        $first = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/messaging/threads/{$thread->id}")->assertOk();

        $cursor = $first->json('data.older_before');
        $this->assertNotNull($cursor, 'the reader is told messages are missing but given no way to reach them');

        $older = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/messaging/threads/{$thread->id}?before={$cursor}")->assertOk();

        $bodies = array_merge(
            array_column($older->json('data.messages'), 'body'),
            array_column($first->json('data.messages'), 'body'),
        );

        $this->assertSame(520, count($bodies), 'the two windows overlap or leave a gap between them');
        $this->assertSame(range(1, 520), array_map(
            static fn (string $b): int => (int) str_replace('message ', '', $b),
            $bodies,
        ));

        /* At the beginning of the conversation there is nothing older, and it says so. */
        $this->assertSame(0, $older->json('data.messages_withheld'));
        $this->assertNull($older->json('data.older_before'));
    }

    /**
     * A burst posted inside ONE second still pages exactly — the case the timestamp alone cannot carry.
     *
     * Every message here shares a `created_at`, which is what a scripted reply or a fast exchange
     * actually produces. Ordering or paging on that column alone makes the whole thread one tie: the
     * window's edge is then arbitrary, and the second page either repeats what the first showed or
     * steps over it. This is the case that decides whether `(created_at, id)` is load-bearing or
     * decoration.
     */
    public function test_messages_sharing_one_timestamp_still_page_without_a_gap(): void
    {
        $thread = $this->longThread(505);
        $at = now()->subHour();
        Message::where('thread_id', $thread->getKey())->update(['created_at' => $at]);

        $user = $this->teamMember();

        $first = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/messaging/threads/{$thread->id}")->assertOk();
        $older = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/messaging/threads/{$thread->id}?before={$first->json('data.older_before')}")->assertOk();

        $ids = array_merge(
            array_column($older->json('data.messages'), 'id'),
            array_column($first->json('data.messages'), 'id'),
        );

        $this->assertCount(505, $ids);
        $this->assertSame(505, count(array_unique($ids)), 'the two windows repeat messages that share a timestamp');
    }

    /** An unreadable cursor returns the newest window rather than an empty conversation. */
    public function test_a_cursor_from_another_thread_is_ignored(): void
    {
        $thread = $this->longThread(12);
        $stranger = $this->longThread(3);
        $strangerMessage = $stranger->messages()->first();

        $response = $this->actingAs($this->teamMember(), 'sanctum')
            ->getJson("/api/v1/messaging/threads/{$thread->id}?before={$strangerMessage->id}")->assertOk();

        $this->assertCount(12, $response->json('data.messages'));
    }

    /** A short thread is not a truncated one — it must report nothing withheld. */
    public function test_a_short_thread_withholds_nothing(): void
    {
        $thread = $this->longThread(3);

        $response = $this->actingAs($this->teamMember(), 'sanctum')
            ->getJson("/api/v1/messaging/threads/{$thread->id}")
            ->assertOk();

        $this->assertSame(3, $response->json('data.messages_total'));
        $this->assertSame(0, $response->json('data.messages_withheld'));
        $this->assertCount(3, $response->json('data.messages'));
    }
}
