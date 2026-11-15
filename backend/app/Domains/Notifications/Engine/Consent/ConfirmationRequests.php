<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Consent;

use App\Domains\Notifications\Engine\Contracts\SubscriptionLog;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Enums\SubscriptionLogAction;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Engine\Sending\EngineMail;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\External\Consent\ConsentMessage;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\MailFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Consent for external recipients (spec §9): «أرسل طلبات التأكيد» (preview + explicit send), the confirmation itself, the
 * internal approval for org-domain addresses, and the 7-day «no confirmation» notice.
 *
 * Nothing here runs by itself: `send()` is called only by the owner's explicit action, never by a migration, a deploy or
 * a schedule. Tokens are stored hashed; a confirmation only ever activates the recipient of the token's own workspace.
 */
final class ConfirmationRequests
{
    public function __construct(
        private readonly SubscriptionLog $log,
        private readonly WorkspaceSettingsRepository $settings,
        private readonly ProviderRegistry $providers,
    ) {}

    /** @return array{recipients: int, subscriptions: int, excluded_archived_projects: int} */
    public function preview(string $tenantId): array
    {
        $eligible = $this->eligible($tenantId);
        $subs = DB::table('notification_subscriptions')->where('tenant_id', $tenantId)->whereIn('recipient_id', $eligible->pluck('id'))
            ->where('status', 'pending')->pluck('id');
        $archived = DB::table('notification_subscription_projects as p')->join('projects', 'projects.id', '=', 'p.project_id')
            ->whereIn('p.subscription_id', $subs)->where(fn ($q) => $q->where('projects.status', 'archived')->orWhereNotNull('projects.deleted_at'))->count();

        return ['recipients' => $eligible->count(), 'subscriptions' => $subs->count(), 'excluded_archived_projects' => $archived];
    }

    /** @return array{sent: int, failed: int} */
    public function send(string $tenantId, int $actorUserId): array
    {
        $out = ['sent' => 0, 'failed' => 0];
        $ws = $this->settings->for($tenantId);
        // No provider, no request: nothing is recorded as asked when nothing could leave.
        if (! $this->providers->isConfigured('email')) {
            return ['sent' => 0, 'failed' => $this->eligible($tenantId)->count()];
        }

        foreach ($this->eligible($tenantId) as $recipient) {
            $token = Str::random(48);
            $projects = DB::table('notification_subscription_projects as p')->join('notification_subscriptions as s', 's.id', '=', 'p.subscription_id')
                ->join('projects', 'projects.id', '=', 'p.project_id')
                ->where('s.recipient_id', $recipient->id)->where('s.tenant_id', $tenantId)->where('projects.status', '!=', 'archived')
                ->whereNull('projects.deleted_at')->orderBy('projects.name')->pluck('projects.name')->unique()->values()->all();
            $ar = $recipient->language !== 'en';
            $link = url('/n/confirm/'.$token).($ar ? '' : '?lang=en');
            // The invitation's words and design: builder Q's ConsentMessage (shared design tokens, §9 wording).
            $message = ConsentMessage::compose($ws->workspaceName, $projects, $link, $ar ? 'ar' : 'en', ConsentMessage::footprintRemoved($tenantId), ConsentMessage::term($tenantId, $ar ? 'ar' : 'en'));

            try {
                Mail::to((string) $recipient->email)->send(new EngineMail($message, 'consent-'.substr((string) $recipient->id, 0, 8), $ar ? 'ar' : 'en'));
            } catch (Throwable $e2) {
                $out['failed']++;
                report(new RuntimeException('Confirmation request not sent: '.MailFailure::from($e2)['code']));

                continue;
            }

            DB::table('notification_external_recipients')->where('id', $recipient->id)->where('tenant_id', $tenantId)->update([
                'confirmation_token_hash' => hash('sha256', $token), 'confirmation_sent_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
            $this->log->record($tenantId, SubscriptionLogAction::ConfirmationRequested, (string) $recipient->id, null, ['projects' => count($projects)], $actorUserId);
            $out['sent']++;
        }

        return $out;
    }

    /** The recipient pressed «confirm». @return string|null the tenant, or null for an unknown / used token */
    public function confirm(string $token): ?string
    {
        // Pending (first confirmation) or active with new projects awaiting a fresh confirmation (subscriptions added later).
        $row = DB::table('notification_external_recipients')->where('confirmation_token_hash', hash('sha256', $token))->whereIn('status', ['pending', 'active'])->first();
        if ($row === null) {
            return null;
        }

        DB::transaction(function () use ($row): void {
            $now = Carbon::now();
            DB::table('notification_external_recipients')->where('id', $row->id)->update([
                'status' => 'active', 'consent' => 'double_opt_in', 'confirmed_at' => $now, 'confirmation_token_hash' => null, 'updated_at' => $now,
            ]);
            DB::table('notification_subscriptions')->where('recipient_id', $row->id)->where('tenant_id', $row->tenant_id)->where('status', 'pending')
                ->update(['status' => 'active', 'updated_at' => $now]);
            $this->log->record((string) $row->tenant_id, SubscriptionLogAction::Confirmed, (string) $row->id);
        });

        return (string) $row->tenant_id;
    }

    /** «Approved internally» — only for an address on the workspace's org domains and never for the client role (spec §9). */
    public function approveInternally(string $tenantId, string $recipientId, int $actorUserId): bool
    {
        $row = DB::table('notification_external_recipients')->where('id', $recipientId)->where('tenant_id', $tenantId)->first();
        $ws = $this->settings->for($tenantId);
        $profile = app(WorkspaceTypes::class)->profileFor($tenantId);
        if ($row === null || $row->status !== 'pending' || ! $ws->isOrgAddress((string) $row->email) || $profile->isClientRole((string) $row->role_key)) {
            return false;
        }

        $now = Carbon::now();
        DB::table('notification_external_recipients')->where('id', $recipientId)->update([
            'status' => 'active', 'consent' => 'approved_internally', 'approved_by' => $actorUserId, 'approved_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('notification_subscriptions')->where('recipient_id', $recipientId)->where('tenant_id', $tenantId)->where('status', 'pending')
            ->update(['status' => 'active', 'updated_at' => $now]);
        $this->log->record($tenantId, SubscriptionLogAction::ApprovedInternally, $recipientId, null, [], $actorUserId);

        return true;
    }

    /** Requests unanswered for 7 days: one internal notice per recipient, logged once. @return int notices */
    public function sweepUnconfirmed(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $days = (int) config('notifications_v2.consent.unconfirmed_notice_days', 7);
        $rows = DB::table('notification_external_recipients')->where('status', 'pending')->whereNotNull('confirmation_sent_at')
            ->where('confirmation_sent_at', '<=', $now->copy()->subDays($days))->get();
        $notices = 0;

        foreach ($rows as $row) {
            $logged = DB::table('notification_subscription_log')->where('recipient_id', $row->id)->where('action', SubscriptionLogAction::NoConfirmation7d->value)->exists();
            if ($logged) {
                continue;
            }
            $this->log->record((string) $row->tenant_id, SubscriptionLogAction::NoConfirmation7d, (string) $row->id);
            $project = DB::table('notification_subscription_projects as p')->join('notification_subscriptions as s', 's.id', '=', 'p.subscription_id')
                ->where('s.recipient_id', $row->id)->where('p.tenant_id', $row->tenant_id)->value('p.project_id');
            if ($project !== null) {
                app(NotificationEngine::class)->raise(new DeliveryIntent(
                    eventKey: 'external.recipient_unconfirmed_7d', tenantId: (string) $row->tenant_id, projectId: (string) $project,
                    objectType: 'external_recipient', objectId: (string) $row->id, payload: ['recipient' => (string) ($row->name ?: $row->email)],
                ));
            }
            $notices++;
        }

        return $notices;
    }

    /**
     * Pending recipients that never had a request — or confirmed-by-email recipients with a NEW pending subscription and no
     * open request (projects added after their confirmation) — with at least one live project on a pending subscription.
     *
     * @return Collection<int, \stdClass>
     */
    private function eligible(string $tenantId): Collection
    {
        return DB::table('notification_external_recipients as r')->where('r.tenant_id', $tenantId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('r.status', 'pending')->whereNull('r.confirmation_sent_at'))
                ->orWhere(fn ($q) => $q->where('r.status', 'active')->where('r.consent', 'double_opt_in')->whereNull('r.confirmation_token_hash')))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('notification_subscriptions as s')
                ->join('notification_subscription_projects as p', 'p.subscription_id', '=', 's.id')
                ->join('projects', 'projects.id', '=', 'p.project_id')
                ->whereColumn('s.recipient_id', 'r.id')->where('s.status', 'pending')
                ->where('projects.status', '!=', 'archived')->whereNull('projects.deleted_at'))
            ->orderBy('r.created_at')->get(['r.id', 'r.email', 'r.name', 'r.language']);
    }
}
