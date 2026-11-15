<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Recipients;

use App\Domains\Notifications\Engine\Consent\ConfirmationRequests;
use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\SubscriptionLog;
use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\WorkspaceTypeProfile;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\Enums\SubscriptionLogAction;
use App\Domains\Notifications\Engine\Models\ExternalRecipient;
use App\Domains\Notifications\Engine\Models\NotificationSubscription;
use App\Domains\Notifications\Engine\Scheduling\DigestPeriods;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * External recipients and their subscriptions (spec §9) — the one write path R's wizard and settings call.
 *
 * Rules held here, server-side, whatever the UI sends:
 * - a role is one of the workspace type's profile roles (no type is named in code); language ar|en; display currency
 *   defaults to the workspace's;
 * - projects come from the SAME workspace only; archived/deleted projects are dropped and reported, never stored;
 * - a subscription needs projects and at least one type (a digest type or an external event);
 * - illogical period/frequency pairs are refused with the alternative («استخدم البديل»);
 * - consent: double opt-in for the client role and for every address outside the org domains; an org-domain address
 *   outside the client role may be «approved internally» (logged with who and when); NOTHING is active before that;
 * - the client role gets review on by default; every new subscription has its first N messages reviewed;
 * - CC/BCC need `notifications.external.cc_bcc` and stay within `cc_per_message`; a visible CC that would expose a
 *   client to an address outside their scope moves to BCC with a note; a copy address outside the org domains becomes
 *   its own pending recipient (a «copy» subscription on the same projects) and receives nothing until it confirms;
 * - a custom subject needs `notifications.external.custom_subject`;
 * - the recipients limit (`external_recipients`) and the monthly recipients counter.
 */
final class ExternalRecipients
{
    public const DIGEST_TYPES = ['performance', 'spend', 'budgets', 'top_campaigns', 'growth_suggestions', 'achievements'];

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const COPY_TEMPLATE = 'copy';

    public function __construct(
        private readonly WorkspaceTypes $types,
        private readonly ExternalSettings $settings,
        private readonly EntitlementGate $gate,
        private readonly UsageCounter $usage,
        private readonly SubscriptionLog $log,
        private readonly EventRegistry $events,
        private readonly ConfirmationRequests $consent,
    ) {}

    /** The external event keys a subscription may name (catalog, never an internal-only one). @return list<string> */
    public function externalEventKeys(): array
    {
        $keys = [];
        foreach ($this->events->all() as $event) {
            if ($event->scope->includes(Scope::External) && ! $event->internalOnly && $event->key !== 'digest.periodic') {
                $keys[] = $event->key;
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{recipient: array<string, mixed>, notes: list<string>, hints: list<string>}
     */
    public function create(string $tenantId, int $actorUserId, array $input): array
    {
        $ws = $this->settings->workspace($tenantId);
        $profile = $this->types->profileFor($tenantId);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $this->fail(filter_var($email, FILTER_VALIDATE_EMAIL) === false, 'email', 'A valid email is required.');
        $this->fail(DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('email', $email)->where('status', '!=', 'cancelled')->exists(), 'email', 'This address is already a recipient; add a subscription to it instead.');

        $role = (string) ($input['role_key'] ?? '');
        $this->fail(! $profile->hasRole($role), 'role_key', 'Choose one of the workspace roles.');
        $limit = $this->gate->limit($tenantId, 'external_recipients');
        $count = DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('status', '!=', 'cancelled')->count();
        $this->fail($limit !== null && $count >= $limit, 'plan', 'The plan limit of external recipients is reached.');

        $subscriptions = array_values((array) ($input['subscriptions'] ?? []));
        $this->fail($subscriptions === [], 'subscriptions', 'At least one subscription is required.');

        $notes = [];
        $clean = [];
        foreach ($subscriptions as $i => $s) {
            $clean[] = $this->cleanSubscription($tenantId, $ws, $profile, $role, (array) $s, "subscriptions.{$i}", $notes);
        }

        $needsDoubleOptIn = $profile->isClientRole($role) || ! $ws->isOrgAddress($email);
        $approveInternally = (bool) ($input['approve_internally'] ?? false);
        $this->fail($approveInternally && $needsDoubleOptIn, 'approve_internally', 'Internal approval is only for an org-domain address outside the client role.');

        $recipient = DB::transaction(function () use ($tenantId, $actorUserId, $input, $email, $role, $clean): ExternalRecipient {
            $recipient = ExternalRecipient::query()->create([
                'tenant_id' => $tenantId,
                'email' => $email,
                'name' => $this->str($input['name'] ?? null, 120),
                'salutation' => $this->str($input['salutation'] ?? null, 120),
                'role_key' => $role,
                'language' => ($input['language'] ?? 'ar') === 'en' ? 'en' : 'ar',
                'display_currency' => $this->currency($input['display_currency'] ?? null),
                'is_role_mailbox' => (bool) ($input['is_role_mailbox'] ?? false),
                'status' => 'pending',
                'consent' => 'none',
                'created_by' => $actorUserId,
            ]);
            $this->log->record($tenantId, SubscriptionLogAction::Created, (string) $recipient->id, null, ['role' => $role], $actorUserId);
            foreach ($clean as $s) {
                $this->storeSubscription($tenantId, (string) $recipient->id, $actorUserId, $s, 'pending');
            }

            return $recipient;
        });
        $this->usage->increment($tenantId, UsageCounter::EXTERNAL_RECIPIENTS);

        foreach ($clean as $s) {
            foreach (array_merge($s['cc'], $s['bcc']) as $copy) {
                $this->ensureCopyRecipient($tenantId, $ws, $actorUserId, $copy, $s['project_ids']);
            }
        }

        if ($approveInternally) {
            $this->consent->approveInternally($tenantId, (string) $recipient->id, $actorUserId);
        }

        return ['recipient' => $this->show($tenantId, (string) $recipient->id), 'notes' => $notes, 'hints' => $this->hints($tenantId, $email, (bool) ($input['is_role_mailbox'] ?? false), $needsDoubleOptIn)];
    }

    /**
     * Add a subscription to an existing recipient. New projects for a recipient who confirmed by email wait for a new
     * confirmation (the subscription stays pending and «أرسل طلبات التأكيد» lists it); otherwise it follows the recipient.
     *
     * @param  array<string, mixed>  $input
     * @return array{subscription: array<string, mixed>, notes: list<string>}
     */
    public function addSubscription(string $tenantId, string $recipientId, int $actorUserId, array $input): array
    {
        $recipient = $this->recipientRow($tenantId, $recipientId);
        $ws = $this->settings->workspace($tenantId);
        $notes = [];
        $s = $this->cleanSubscription($tenantId, $ws, $this->types->profileFor($tenantId), (string) $recipient->role_key, $input, 'subscription', $notes);
        $status = $this->statusForNewProjects($tenantId, $recipient, $s['project_ids']);
        $id = $this->storeSubscription($tenantId, $recipientId, $actorUserId, $s, $status);
        foreach (array_merge($s['cc'], $s['bcc']) as $copy) {
            $this->ensureCopyRecipient($tenantId, $ws, $actorUserId, $copy, $s['project_ids']);
        }

        return ['subscription' => $this->subscriptionArray($tenantId, $id), 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{subscription: array<string, mixed>, notes: list<string>}
     */
    public function updateSubscription(string $tenantId, string $subscriptionId, int $actorUserId, array $input): array
    {
        $sub = $this->subscriptionRow($tenantId, $subscriptionId);
        $recipient = $this->recipientRow($tenantId, (string) $sub->recipient_id);
        $current = $this->subscriptionArray($tenantId, $subscriptionId);
        $merged = array_merge($current, array_intersect_key($input, $current));
        $notes = [];
        $ws = $this->settings->workspace($tenantId);
        $s = $this->cleanSubscription($tenantId, $ws, $this->types->profileFor($tenantId), (string) $recipient->role_key, $merged, 'subscription', $notes);

        $added = array_diff($s['project_ids'], $current['project_ids']);
        $status = (string) $sub->status;
        if ($added !== [] && $status === 'active') {
            $status = $this->statusForNewProjects($tenantId, $recipient, $added);
        }

        $model = NotificationSubscription::query()->where('tenant_id', $tenantId)->findOrFail($subscriptionId);
        $model->fill($this->columns($s) + ['status' => $status, 'next_run_at' => null])->save();
        $model->bindProjects($s['project_ids']);
        foreach (array_merge($s['cc'], $s['bcc']) as $copy) {
            $this->ensureCopyRecipient($tenantId, $ws, $actorUserId, $copy, $s['project_ids']);
        }

        return ['subscription' => $this->subscriptionArray($tenantId, $subscriptionId), 'notes' => $notes];
    }

    /** pause | resume | cancel — a cancelled row is kept (the log points at it); nothing is deleted. */
    public function setSubscriptionStatus(string $tenantId, string $subscriptionId, string $action, int $actorUserId): array
    {
        $sub = $this->subscriptionRow($tenantId, $subscriptionId);
        $recipient = $this->recipientRow($tenantId, (string) $sub->recipient_id);
        [$to, $logAction] = match ($action) {
            'pause' => ['paused', SubscriptionLogAction::Paused],
            'resume' => [$recipient->status === 'active' ? 'active' : 'pending', SubscriptionLogAction::Resumed],
            'cancel' => ['cancelled', SubscriptionLogAction::Cancelled],
            default => throw ValidationException::withMessages(['action' => ['Unknown action.']]),
        };
        $this->fail($sub->status === 'cancelled', 'status', 'A cancelled subscription cannot change; duplicate it instead.');
        DB::table('notification_subscriptions')->where('id', $subscriptionId)->where('tenant_id', $tenantId)
            ->update(['status' => $to, 'next_run_at' => null, 'updated_at' => Carbon::now()]);
        $this->log->record($tenantId, $logAction, (string) $sub->recipient_id, $subscriptionId, [], $actorUserId);

        return $this->subscriptionArray($tenantId, $subscriptionId);
    }

    public function duplicateSubscription(string $tenantId, string $subscriptionId, int $actorUserId): array
    {
        $current = $this->subscriptionArray($tenantId, $subscriptionId);
        $current['status'] = null;

        return $this->addSubscription($tenantId, (string) $current['recipient_id'], $actorUserId, $current)['subscription'];
    }

    /** pause | resume | cancel the recipient and every subscription under it. */
    public function setRecipientStatus(string $tenantId, string $recipientId, string $action, int $actorUserId): array
    {
        $recipient = $this->recipientRow($tenantId, $recipientId);
        $this->fail($recipient->status === 'cancelled', 'status', 'A cancelled recipient cannot change.');
        $confirmed = in_array((string) $recipient->consent, ['double_opt_in', 'approved_internally'], true);
        [$to, $logAction] = match ($action) {
            'pause' => ['paused', SubscriptionLogAction::Paused],
            'resume' => [$confirmed ? 'active' : 'pending', SubscriptionLogAction::Resumed],
            'cancel' => ['cancelled', SubscriptionLogAction::Cancelled],
            default => throw ValidationException::withMessages(['action' => ['Unknown action.']]),
        };
        $now = Carbon::now();
        DB::transaction(function () use ($tenantId, $recipientId, $to, $now, $action): void {
            DB::table('notification_external_recipients')->where('id', $recipientId)->where('tenant_id', $tenantId)->update(['status' => $to, 'updated_at' => $now]);
            $q = DB::table('notification_subscriptions')->where('recipient_id', $recipientId)->where('tenant_id', $tenantId)->where('status', '!=', 'cancelled');
            if ($action === 'resume') {
                $q->where('status', 'paused');
            }
            $q->update(['status' => $to, 'next_run_at' => null, 'updated_at' => $now]);
        });
        $this->log->record($tenantId, $logAction, $recipientId, null, [], $actorUserId);

        return $this->show($tenantId, $recipientId);
    }

    /** @param array<string, mixed> $input name, salutation, role_key, language, display_currency, is_role_mailbox */
    public function updateRecipient(string $tenantId, string $recipientId, array $input): array
    {
        $recipient = $this->recipientRow($tenantId, $recipientId);
        $profile = $this->types->profileFor($tenantId);
        $patch = [];
        foreach (['name', 'salutation'] as $k) {
            if (array_key_exists($k, $input)) {
                $patch[$k] = $this->str($input[$k], 120);
            }
        }
        if (array_key_exists('role_key', $input)) {
            $role = (string) $input['role_key'];
            $this->fail(! $profile->hasRole($role), 'role_key', 'Choose one of the workspace roles.');
            // Moving INTO the client role on a recipient approved only internally needs a real confirmation.
            $this->fail($profile->isClientRole($role) && $recipient->consent === 'approved_internally', 'role_key', 'The client role needs an email confirmation; add the address again as a client.');
            $patch['role_key'] = $role;
        }
        if (array_key_exists('language', $input)) {
            $patch['language'] = $input['language'] === 'en' ? 'en' : 'ar';
        }
        if (array_key_exists('display_currency', $input)) {
            $patch['display_currency'] = $this->currency($input['display_currency']);
        }
        if (array_key_exists('is_role_mailbox', $input)) {
            $patch['is_role_mailbox'] = (bool) $input['is_role_mailbox'];
        }
        if ($patch !== []) {
            DB::table('notification_external_recipients')->where('id', $recipientId)->where('tenant_id', $tenantId)->update($patch + ['updated_at' => Carbon::now()]);
        }

        return $this->show($tenantId, $recipientId);
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, ?string $status = null): array
    {
        $ids = DB::table('notification_external_recipients')->where('tenant_id', $tenantId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))->orderBy('created_at')->pluck('id');

        return $ids->map(fn ($id): array => $this->show($tenantId, (string) $id))->all();
    }

    /** @return array<string, mixed> */
    public function show(string $tenantId, string $recipientId): array
    {
        $r = $this->recipientRow($tenantId, $recipientId);
        $subs = DB::table('notification_subscriptions')->where('tenant_id', $tenantId)->where('recipient_id', $recipientId)->orderBy('created_at')->pluck('id');

        return [
            'id' => (string) $r->id,
            'email' => (string) $r->email,
            'name' => $r->name,
            'salutation' => $r->salutation,
            'role_key' => (string) $r->role_key,
            'language' => (string) $r->language,
            'display_currency' => $r->display_currency,
            'is_role_mailbox' => (bool) $r->is_role_mailbox,
            'status' => (string) $r->status,
            'consent' => (string) $r->consent,
            'confirmation_sent_at' => $r->confirmation_sent_at,
            'confirmed_at' => $r->confirmed_at,
            'approved_by' => $r->approved_by,
            'approved_at' => $r->approved_at,
            'is_copy_only' => $subs->isNotEmpty() && DB::table('notification_subscriptions')->whereIn('id', $subs)->where('template_key', '!=', self::COPY_TEMPLATE)->doesntExist(),
            'subscriptions' => $subs->map(fn ($id): array => $this->subscriptionArray($tenantId, (string) $id))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function subscriptionArray(string $tenantId, string $subscriptionId): array
    {
        $s = $this->subscriptionRow($tenantId, $subscriptionId);
        $json = static fn ($v): array => $v === null ? [] : array_values((array) (is_string($v) ? json_decode($v, true) : $v));

        return [
            'id' => (string) $s->id,
            'recipient_id' => (string) $s->recipient_id,
            'status' => (string) $s->status,
            'template_key' => $s->template_key,
            'digest_types' => $json($s->digest_types),
            'event_keys' => $json($s->event_keys),
            'period_key' => $s->period_key,
            'frequency' => $s->frequency,
            'weekdays' => array_map('intval', $json($s->weekdays)),
            'month_day' => $s->month_day === null ? null : (int) $s->month_day,
            'send_time' => (string) $s->send_time,
            'timezone' => $s->timezone,
            'subject_template' => $s->subject_template,
            'cc' => $json($s->cc),
            'bcc' => $json($s->bcc),
            'review_mode' => $s->review_mode === null ? null : (bool) $s->review_mode,
            'reviews_remaining' => (int) $s->reviews_remaining,
            'show_budget' => (bool) (self::overrides($s)['show_budget'] ?? true),
            'project_ids' => DB::table('notification_subscription_projects')->where('subscription_id', $s->id)->where('tenant_id', $tenantId)
                ->orderBy('project_id')->pluck('project_id')->map(static fn ($p): string => (string) $p)->all(),
            'next_run_at' => $s->next_run_at,
            'last_run_at' => $s->last_run_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function overrides(object $subscriptionRow): array
    {
        $raw = $subscriptionRow->overrides ?? null;

        return is_string($raw) ? (array) json_decode($raw, true) : (array) ($raw ?? []);
    }

    /** KPI strip of the external section (spec §2). @return array<string, int> */
    public function kpis(string $tenantId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $log = fn (string $status, Carbon $since) => DB::table('notification_delivery_log')->where('tenant_id', $tenantId)->where('scope', 'external')
            ->where('status', $status)->where('created_at', '>=', $since)->count();

        return [
            'held_for_review' => DB::table('notification_external_reviews')->where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'held_for_privacy' => $log('held_for_privacy', $now->copy()->subDays(30)),
            'sent_this_week' => $log('sent', $now->copy()->subDays(7)),
            'failed' => $log('failed', $now->copy()->subDays(7)),
            'awaiting_confirmation' => DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'unsubscribed_this_month' => DB::table('notification_subscription_log')->where('tenant_id', $tenantId)
                ->whereIn('action', [SubscriptionLogAction::Unsubscribed->value, SubscriptionLogAction::Reduced->value])
                ->where('created_at', '>=', $now->copy()->startOfMonth())->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private function cleanSubscription(string $tenantId, WorkspaceSettings $ws, WorkspaceTypeProfile $profile, string $role, array $s, string $field, array &$notes): array
    {
        $requested = array_values(array_unique(array_map('strval', (array) ($s['project_ids'] ?? []))));
        $live = DB::table('projects')->where('tenant_id', $tenantId)->whereIn('id', $requested)->where('status', '!=', 'archived')->whereNull('deleted_at')
            ->pluck('id')->map(static fn ($p): string => (string) $p)->all();
        $foreign = count($requested) - DB::table('projects')->where('tenant_id', $tenantId)->whereIn('id', $requested)->count();
        $this->fail($foreign > 0, "{$field}.project_ids", 'Projects must belong to this workspace.');
        if (count($live) < count($requested)) {
            $notes[] = 'archived_projects_excluded';
        }
        $this->fail($live === [], "{$field}.project_ids", 'Choose at least one active project.');

        $digest = array_values(array_intersect(array_map('strval', (array) ($s['digest_types'] ?? [])), self::DIGEST_TYPES));
        $events = array_values(array_intersect(array_map('strval', (array) ($s['event_keys'] ?? [])), $this->externalEventKeys()));
        $isCopy = ($s['template_key'] ?? null) === self::COPY_TEMPLATE;
        $this->fail(! $isCopy && $digest === [] && $events === [], "{$field}.types", 'Choose at least one digest type or event.');

        $period = isset($s['period_key']) && $s['period_key'] !== '' ? (string) $s['period_key'] : null;
        $frequency = isset($s['frequency']) && $s['frequency'] !== '' ? (string) $s['frequency'] : null;
        if ($digest !== []) {
            $this->fail($period === null || $frequency === null, "{$field}.period_key", 'A digest needs a period and a frequency.');
            $check = DigestPeriods::check((string) $period, (string) $frequency);
            if (! $check['ok']) {
                throw ValidationException::withMessages(["{$field}.period_key" => ['This period does not fit this frequency.'], 'alternative' => [(string) $check['alternative']]]);
            }
        } else {
            [$period, $frequency] = [null, null];
        }

        $subject = $this->str($s['subject_template'] ?? null, 200);
        $this->fail($subject !== null && ! $this->gate->allows($tenantId, EntitlementKey::ExternalCustomSubject), "{$field}.subject_template", 'entitlement:'.EntitlementKey::ExternalCustomSubject->value);

        [$cc, $bcc] = $this->copies($tenantId, $ws, $profile, $role, $live, (array) ($s['cc'] ?? []), (array) ($s['bcc'] ?? []), $field, $notes);

        $review = array_key_exists('review_mode', $s) && $s['review_mode'] !== null ? (bool) $s['review_mode'] : null;
        if ($review === false && $profile->isClientRole($role)) {
            $notes[] = 'client_role_review_stays_on';
            $review = true;
        }
        $this->fail($review === true && ! $profile->isClientRole($role) && ! $this->gate->allows($tenantId, EntitlementKey::ExternalReviewMode), "{$field}.review_mode", 'entitlement:'.EntitlementKey::ExternalReviewMode->value);

        $weekdays = array_values(array_unique(array_filter(array_map('intval', (array) ($s['weekdays'] ?? [])), static fn (int $d): bool => $d >= 0 && $d <= 6)));
        $time = (string) ($s['send_time'] ?? '08:00');
        $this->fail(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1, "{$field}.send_time", 'Use HH:MM.');
        $tz = isset($s['timezone']) && is_string($s['timezone']) && in_array($s['timezone'], timezone_identifiers_list(), true) ? $s['timezone'] : null;

        return [
            'template_key' => $this->str($s['template_key'] ?? null, 60),
            'digest_types' => $digest,
            'event_keys' => $events,
            'period_key' => $period,
            'frequency' => $frequency,
            'weekdays' => $weekdays,
            'month_day' => isset($s['month_day']) ? max(1, min(31, (int) $s['month_day'])) : null,
            'send_time' => $time,
            'timezone' => $tz,
            'subject_template' => $subject,
            'cc' => $cc,
            'bcc' => $bcc,
            'review_mode' => $review ?? ($profile->isClientRole($role) ? true : null),
            'show_budget' => ! array_key_exists('show_budget', $s) || (bool) $s['show_budget'],
            'project_ids' => $live,
        ];
    }

    /**
     * @param  list<string>  $projectIds
     * @param  array<int, mixed>  $cc
     * @param  array<int, mixed>  $bcc
     * @param  list<string>  $notes
     * @return array{0: list<string>, 1: list<string>}
     */
    private function copies(string $tenantId, WorkspaceSettings $ws, WorkspaceTypeProfile $profile, string $role, array $projectIds, array $cc, array $bcc, string $field, array &$notes): array
    {
        $norm = static fn (array $list): array => array_values(array_unique(array_filter(array_map(static fn ($a): string => strtolower(trim((string) $a)), $list))));
        $cc = $norm($cc);
        $bcc = array_values(array_diff($norm($bcc), $cc)); // an address in both is delivered once, as CC
        if ($cc === [] && $bcc === []) {
            return [[], []];
        }
        $this->fail(! $this->gate->allows($tenantId, EntitlementKey::ExternalCcBcc), "{$field}.cc", 'entitlement:'.EntitlementKey::ExternalCcBcc->value);
        foreach (array_merge($cc, $bcc) as $address) {
            $this->fail(filter_var($address, FILTER_VALIDATE_EMAIL) === false, "{$field}.cc", "Not an email: {$address}");
        }
        $limit = $this->gate->limit($tenantId, 'cc_per_message');
        $this->fail($limit !== null && count($cc) + count($bcc) > $limit, "{$field}.cc", 'The plan limit of copies per message is reached.');

        // A visible CC out of a client's scope becomes a BCC (spec §8): a client must never see another outside address, and
        // a client-role address of another project must never be shown beside this one.
        $visible = [];
        foreach ($cc as $address) {
            $otherClient = DB::table('notification_external_recipients as r')
                ->join('notification_subscriptions as s', 's.recipient_id', '=', 'r.id')
                ->where('r.tenant_id', $tenantId)->where('r.email', $address)->whereIn('r.role_key', array_filter([$profile->clientRole]))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('notification_subscription_projects as p')
                    ->whereColumn('p.subscription_id', 's.id')->whereIn('p.project_id', $projectIds))
                ->exists();
            if ($otherClient || ($profile->isClientRole($role) && ! $ws->isOrgAddress($address))) {
                $bcc[] = $address;
                $notes[] = 'cc_moved_to_bcc:'.$address;
            } else {
                $visible[] = $address;
            }
        }

        return [$visible, array_values(array_unique($bcc))];
    }

    /** @param list<string> $projectIds */
    private function ensureCopyRecipient(string $tenantId, WorkspaceSettings $ws, int $actorUserId, string $address, array $projectIds): void
    {
        if ($ws->isOrgAddress($address)) {
            return; // org-domain copies need no confirmation
        }
        $existing = DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('email', $address)->first();
        if ($existing !== null) {
            return; // already a recipient: its own consent state governs its copies
        }
        $recipient = ExternalRecipient::query()->create([
            'tenant_id' => $tenantId, 'email' => $address, 'role_key' => 'other', 'language' => 'ar', 'status' => 'pending', 'consent' => 'none', 'created_by' => $actorUserId,
        ]);
        $this->log->record($tenantId, SubscriptionLogAction::Created, (string) $recipient->id, null, ['copy' => true], $actorUserId);
        $this->storeSubscription($tenantId, (string) $recipient->id, $actorUserId, [
            'template_key' => self::COPY_TEMPLATE, 'digest_types' => [], 'event_keys' => [], 'period_key' => null, 'frequency' => null, 'weekdays' => [],
            'month_day' => null, 'send_time' => '08:00', 'timezone' => null, 'subject_template' => null, 'cc' => [], 'bcc' => [], 'review_mode' => null,
            'show_budget' => true, 'project_ids' => $projectIds,
        ], 'pending');
    }

    /** @param array<string, mixed> $s */
    private function storeSubscription(string $tenantId, string $recipientId, int $actorUserId, array $s, string $status): string
    {
        $model = NotificationSubscription::query()->create($this->columns($s) + [
            'tenant_id' => $tenantId, 'recipient_id' => $recipientId, 'status' => $status, 'created_by' => $actorUserId,
            'reviews_remaining' => (int) config('notifications_v2.review.first_messages', 3),
        ]);
        $model->bindProjects($s['project_ids']);
        $this->log->record($tenantId, SubscriptionLogAction::Created, $recipientId, (string) $model->id, ['projects' => count($s['project_ids'])], $actorUserId);

        return (string) $model->id;
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function columns(array $s): array
    {
        return [
            'template_key' => $s['template_key'], 'digest_types' => $s['digest_types'], 'event_keys' => $s['event_keys'],
            'period_key' => $s['period_key'], 'frequency' => $s['frequency'], 'weekdays' => $s['weekdays'], 'month_day' => $s['month_day'],
            'send_time' => $s['send_time'], 'timezone' => $s['timezone'], 'subject_template' => $s['subject_template'],
            'cc' => $s['cc'], 'bcc' => $s['bcc'], 'review_mode' => $s['review_mode'], 'overrides' => ['show_budget' => (bool) $s['show_budget']],
        ];
    }

    /** @param list<string> $projectIds */
    private function statusForNewProjects(string $tenantId, object $recipient, array $projectIds): string
    {
        if ($recipient->status !== 'active') {
            return $recipient->status === 'paused' ? 'paused' : 'pending';
        }
        if ($recipient->consent !== 'double_opt_in') {
            return 'active';
        }
        $confirmed = DB::table('notification_subscription_projects as p')->join('notification_subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->where('s.recipient_id', $recipient->id)->where('p.tenant_id', $tenantId)->where('s.status', 'active')->pluck('p.project_id')
            ->map(static fn ($p): string => (string) $p)->all();

        return array_diff($projectIds, $confirmed) === [] ? 'active' : 'pending';
    }

    /** @return list<string> */
    private function hints(string $tenantId, string $email, bool $roleMailbox, bool $needsDoubleOptIn): array
    {
        $hints = [];
        $local = strtolower((string) strstr($email, '@', true));
        $roleish = in_array($local, ['finance', 'accounts', 'marketing', 'admin', 'info', 'reports', 'team', 'billing', 'management'], true);
        if (! $roleMailbox && ! $roleish) {
            $hints[] = 'suggest_role_mailbox';
        }
        $isMember = DB::table('users')->join('memberships', 'memberships.user_id', '=', 'users.id')
            ->where('memberships.tenant_id', $tenantId)->whereRaw('lower(users.email) = ?', [$email])->exists();
        if ($isMember) {
            $hints[] = 'already_workspace_user_suggest_internal';
        }
        $hints[] = $needsDoubleOptIn ? 'confirmation_email_required' : 'can_approve_internally';

        return $hints;
    }

    private function recipientRow(string $tenantId, string $id): object
    {
        $row = DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function subscriptionRow(string $tenantId, string $id): object
    {
        $row = DB::table('notification_subscriptions')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function str(mixed $v, int $max): ?string
    {
        $s = is_string($v) ? trim($v) : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function currency(mixed $v): ?string
    {
        $c = is_string($v) ? strtoupper(trim($v)) : '';

        return preg_match('/^[A-Z]{3}$/', $c) === 1 ? $c : null;
    }

    private function fail(bool $condition, string $field, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
    }
}
