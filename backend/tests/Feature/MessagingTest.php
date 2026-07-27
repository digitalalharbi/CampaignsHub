<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Messaging\Services\MessagingService;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The messaging domain, exercised at the service + model layer (routes are wired by the orchestrator, so these
 * tests never touch HTTP): posting bumps last_message_at and creates an unread for the OTHER side, markRead
 * clears a side's unread, a client post raises a team notification, and threads are tenant-isolated.
 */
final class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function messaging(): MessagingService
    {
        return app(MessagingService::class);
    }

    private function thread(): MessageThread
    {
        return $this->messaging()->openThread([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->client->id,
            'subject' => 'Kickoff',
        ]);
    }

    public function test_open_thread_then_post_sets_last_message_at_and_marks_other_side_unread(): void
    {
        $thread = $this->thread();
        $this->assertNull($thread->last_message_at);

        // A client posts → thread bumped, team has one unread, client is caught up on their own message.
        $message = $this->messaging()->postMessage($thread, 'client', 'Hello team');

        $this->assertSame('client', $message->author_type);
        $this->assertNotNull($thread->refresh()->last_message_at);
        $this->assertSame(1, $this->messaging()->unreadCountFor($thread, 'team'));
        $this->assertSame(0, $this->messaging()->unreadCountFor($thread, 'client'));
        $this->assertNull($message->read_by_team_at);
        $this->assertNotNull($message->read_by_client_at);
    }

    public function test_mark_read_clears_the_side_unread(): void
    {
        $thread = $this->thread();
        $this->messaging()->postMessage($thread, 'client', 'One');
        $this->messaging()->postMessage($thread, 'client', 'Two');
        $this->assertSame(2, $this->messaging()->unreadCountFor($thread, 'team'));

        $cleared = $this->messaging()->markRead($thread, 'team');

        $this->assertSame(2, $cleared);
        $this->assertSame(0, $this->messaging()->unreadCountFor($thread, 'team'));
        // The client side is unaffected by the team marking read.
        $this->assertSame(0, $this->messaging()->unreadCountFor($thread, 'client'));
    }

    public function test_a_team_reply_creates_a_client_unread(): void
    {
        $thread = $this->thread();
        $this->messaging()->postMessage($thread, 'team', 'Welcome aboard');

        $this->assertSame(1, $this->messaging()->unreadCountFor($thread, 'client'));
        $this->assertSame(0, $this->messaging()->unreadCountFor($thread, 'team'));
    }

    public function test_client_post_raises_a_team_notification(): void
    {
        $thread = $this->thread();

        $this->messaging()->postMessage($thread, 'client', 'Please review the draft');

        $notification = AppNotification::where('type', 'message')->first();
        $this->assertNotNull($notification);
        $this->assertSame($this->tenant->id, $notification->tenant_id);
        $this->assertSame('message_thread', $notification->entity_type);
        $this->assertSame((string) $thread->id, $notification->entity_id);
        $this->assertSame('messaging', $notification->source);

        // A team reply does NOT notify the team.
        $this->messaging()->postMessage($thread, 'team', 'Looking now');
        $this->assertSame(1, AppNotification::where('type', 'message')->count());
    }

    public function test_close_thread_sets_status_and_a_new_post_reopens_it(): void
    {
        $thread = $this->thread();
        $this->messaging()->closeThread($thread);
        $this->assertSame('closed', $thread->refresh()->status);

        $this->messaging()->postMessage($thread, 'team', 'Reopening');
        $this->assertSame('open', $thread->refresh()->status);
    }

    public function test_tenant_isolation_hides_cross_tenant_threads(): void
    {
        // Tenant A owns a thread with a message.
        $threadA = $this->thread();
        $this->messaging()->postMessage($threadA, 'client', 'A-only');

        // Switch to tenant B: the global scope hides A's thread (route-model binding would 404).
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenantB->id);

        $this->assertNull(MessageThread::find($threadA->id));
        $this->assertSame(0, MessageThread::query()->count());
        $this->assertSame(0, Message::where('thread_id', $threadA->id)->count());

        // Back in tenant A, the thread and its message are intact.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertNotNull(MessageThread::find($threadA->id));
        $this->assertSame(1, Message::where('thread_id', $threadA->id)->count());
    }
}
