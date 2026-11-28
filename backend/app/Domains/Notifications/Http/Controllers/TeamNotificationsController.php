<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Internal\EngineKinds;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\NotificationChoices;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailDeliveryState;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Who on this team is actually being told anything — MAIL-012.
 *
 * ## The question this answers
 *
 * «Sara says she never gets the budget alerts.» Before this, answering that meant a developer with
 * database access: her preference row, her memberships, whether an arrangement names her, whether
 * anything was ever sent to her address, and whether the mailer was configured on the day it would
 * have gone. Four tables and a judgement call, for a question a manager asks weekly.
 *
 * ## Two states that look identical from the outside and are not
 *
 * - **`silent`** — nothing will reach this person by email, whatever happens. Every category off, no
 *   digest, no alerts. The ledger is empty because there was never anything to send.
 * - **`never_sent`** — they are subscribed to things, and nothing has happened yet worth sending.
 *
 * Both show «no messages» in a naive listing, and the actions are opposite: one is a settings
 * problem, the other is Tuesday. They are distinguished here rather than left to a reader.
 *
 * ## Awaiting credentials is a state, not an absence
 *
 * With no mail provider bound, every attempt is recorded as `awaiting_credentials` and nothing
 * leaves. A view that rendered those as failures would have a manager chasing a bug that is a
 * configuration step; one that hid them would have them chasing an email that never existed. So the
 * response says plainly, once, at the top, whether a provider is wired at all.
 *
 * ## Fail-closed, the same rule as the recipient screen
 *
 * Only people whose reachable projects OVERLAP the actor's own are listed, and only the overlapping
 * projects are named. A settings screen must not become a way to enumerate a workspace — this one
 * lists colleagues, their clients and their addresses, which is exactly what an operator scoped to
 * one client should not be able to read off.
 */
final class TeamNotificationsController extends Controller
{
    /** How many attempts the log shows. Enough to answer «has this been arriving», bounded so a busy
     *  workspace does not hand a settings screen ten thousand rows. */
    private const LOG_LIMIT = 100;

    public function __construct(
        private readonly DigestScope $scope,
        private readonly NotificationChoices $choices,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $tenantId = (string) $this->tenants->tenantId();
        $mine = $this->scope->projectIdsFor($request->user(), $tenantId);

        /*
         * Everybody with an active membership, INCLUDING client-portal contacts — found by opening
         * the screen, where «Demo Client» appeared under a heading that said «team».
         *
         * They belong here: they receive report and billing messages, and «the client contact has
         * every email switched off» is exactly the kind of thing this board exists to surface. What
         * would be wrong is listing them without saying which portal they are in, so the portal
         * travels with the row rather than the row being dropped.
         */
        $portals = DB::table('memberships')
            ->where('tenant_id', $tenantId)->where('status', 'active')
            ->pluck('portal', 'user_id');

        $members = User::query()->whereIn('id', $portals->keys()->all())->orderBy('name')->get();

        $names = $this->projectNames($tenantId);
        $arranged = DB::table('notification_recipients')->where('tenant_id', $tenantId)
            ->select('user_id')->distinct()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

        $people = [];

        foreach ($members as $member) {
            $theirs = array_values(array_intersect($mine, $this->scope->projectIdsFor($member, $tenantId)));

            if ($theirs === []) {
                continue;
            }

            $userId = (int) $member->getKey();
            $categories = $this->categoriesFor($userId, $tenantId);
            $rhythms = $this->rhythmsFor($userId, $tenantId);
            $last = $this->lastMessage($userId, (string) $member->email, $tenantId);

            $people[] = [
                'user_id' => $userId,
                'name' => $member->name,
                'email' => $member->email,
                'roles' => $member->roles->pluck('name')->all(),
                'portal' => (string) ($portals[$userId] ?? ''),
                'projects' => array_values(array_map(
                    static fn (string $id): string => $names[$id] ?? $id,
                    $theirs,
                )),
                // What they would receive BY EMAIL, in the vocabulary the preferences screen uses.
                'categories' => $categories,
                'rhythms' => $rhythms,
                'arranged_by_manager' => in_array($userId, $arranged, true),
                'last_message' => $last,
                'state' => $this->state($categories, $rhythms, $last),
            ];
        }

        return ApiResponse::success([
            'people' => $people,
            /*
             * Said once, at the top, rather than implied by twenty rows of the same word.
             *
             * `false` here is the honest reason every row reads `awaiting_credentials`, and a
             * manager who does not see it will read the table as «email is broken».
             */
            'email_provider_configured' => $this->providers->isConfigured('email'),
            'available_categories' => MessageCatalogue::ARRANGEABLE,
        ], 'Team notification status retrieved.');
    }

    /**
     * The categories this person would receive by email today.
     *
     * `account` is excluded deliberately: everybody receives those and always will, so listing them
     * beside somebody's chosen categories would make every row look identical.
     *
     * @return list<string>
     */
    private function categoriesFor(int $userId, string $tenantId): array
    {
        $out = [];

        foreach (MessageCatalogue::CATEGORIES as $category) {
            if ($category === 'account') {
                continue;
            }
            if ($this->choices->wantsCategory($userId, $tenantId, $category)) {
                $out[] = $category;
            }
        }

        return $out;
    }

    /** @return array{daily: bool, weekly: bool, alerts: bool} */
    private function rhythmsFor(int $userId, string $tenantId): array
    {
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereNull('client_workspace_id')->first();

        $digests = $row?->digests === null ? [] : (array) json_decode((string) $row->digests, true);

        return [
            'daily' => ($digests['daily'] ?? false) === true,
            'weekly' => ($digests['weekly'] ?? false) === true,
            'monthly' => ($digests['monthly'] ?? false) === true,
            'alerts' => ($digests['alerts'] ?? false) === true,
        ];
    }

    /**
     * The delivery LOG — EMAIL-SETTINGS-DEPTH-001.
     *
     * The records already existed and nothing listed them. «Last send: 08:04» answers «did the last
     * one work?» and not «has this person been getting them», which is the question somebody asks
     * when a client says they never see the report.
     *
     * Both ledgers, because they are not redundant: `mail_deliveries` holds transactional messages by
     * address, `digest_sends` holds digests and alerts by user and period. Reading one would show
     * «nothing has ever been sent» to somebody receiving a digest every morning.
     *
     * FAILURES included, with their reason. A log of successes cannot answer the only question
     * anybody opens it for, and a failure with no reason is only marginally better.
     */
    public function deliveries(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $tenantId = (string) $this->tenants->tenantId();

        /*
         * TEAM-MAIL-LOG-001 — Owner 2026-11-28: «احتاج كنترول وسجل وش الإيميلات التي تصل للفريق، وهل هم مستخدمين داخلي أو خارجي».
         * Every mail this workspace sends is one row here, from whichever ledger records it — digests and summaries
         * (`digest_sends`), the bell's and the alerts' mails (`mail_deliveries`), the engine's notices (`notification_delivery_log`,
         * email only) and the budget follow-ups to people outside the platform (`external_digest_sends`) — each with WHO received it,
         * WHAT it was in words, and whether that person is a member of the workspace (`internal`) or not (`external`).
         */
        $members = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenantId)->where('m.status', 'active')
            ->pluck('u.email', 'u.id')->map(static fn ($e): string => mb_strtolower((string) $e))->all();
        $memberAddresses = array_flip(array_values($members));
        $audience = static fn (?string $address): string => $address !== null && isset($memberAddresses[mb_strtolower($address)]) ? 'internal' : 'external';
        $userEmails = DB::table('users')->whereIn('id', DB::table('digest_sends')->where('tenant_id', $tenantId)->orderByDesc('created_at')->limit(self::LOG_LIMIT)->pluck('user_id')->all())
            ->pluck('email', 'id')->all();
        $projectNames = DB::table('projects')->where('tenant_id', $tenantId)->pluck('name', 'id')->all();
        $projectByKey = [];
        foreach ($projectNames as $id => $name) {
            $projectByKey[substr(str_replace('-', '', (string) $id), 0, 12)] = (string) $name;
        }

        $digests = DB::table('digest_sends')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(self::LOG_LIMIT)
            ->get()
            ->map(function ($r) use ($userEmails, $projectByKey): array {
                $key = (string) $r->period_key;
                $project = str_contains($key, '~') ? ($projectByKey[substr($key, (int) strpos($key, '~') + 1)] ?? null) : null;

                return [
                    'source' => 'digest',
                    'kind' => (string) $r->kind,
                    'label' => self::digestLabel((string) $r->kind, $project !== null || str_contains($key, '~')),
                    'project' => $project,
                    'recipient' => isset($userEmails[$r->user_id]) ? (string) $userEmails[$r->user_id] : null,
                    'audience' => 'internal',
                    'status' => (string) $r->status,
                    'state' => MailDeliveryState::of((string) $r->status, $r->delivered_at ?? null),
                    'failure_code' => $r->failure_code ?? null,
                    'reason' => $r->reason === null ? null : (string) $r->reason,
                    'attempts' => (int) ($r->attempts ?? 0),
                    'at' => (string) ($r->sent_at ?? $r->created_at),
                    'sort' => (string) $r->created_at,
                ];
            });

        /*
         * `tenant_id` is nullable here ON PURPOSE — a password reset is requested with no session and
         * no resolved tenant. Those rows belong to nobody's workspace, so this asks for this tenant's
         * only: showing an unattributed reset under one workspace's log would be a guess.
         */
        $transactional = DB::table('mail_deliveries')
            ->where('tenant_id', $tenantId)
            ->where('kind', '!=', TransactionalMailer::TEST_KIND)
            ->orderByDesc('created_at')
            ->limit(self::LOG_LIMIT)
            ->get()
            ->map(fn ($r): array => [
                'source' => 'transactional',
                'kind' => (string) $r->kind,
                'label' => self::kindLabel((string) $r->kind),
                'project' => null,
                'recipient' => (string) $r->recipient,
                'audience' => $audience((string) $r->recipient),
                'status' => (string) $r->status,
                'state' => MailDeliveryState::of((string) $r->status, $r->delivered_at ?? null),
                'failure_code' => $r->failure_code ?? null,
                // The named reason, not the transport's words: this is a workspace's screen, not the operator's.
                'reason' => $r->failure_code ?? null,
                'attempts' => (int) ($r->attempts ?? 1),
                'at' => (string) ($r->sent_at ?? $r->created_at),
                'sort' => (string) $r->created_at,
            ]);

        $engine = DB::table('notification_delivery_log')
            ->where('tenant_id', $tenantId)->where('channel', 'email')
            ->orderByDesc('seq')->limit(self::LOG_LIMIT)
            ->get()
            ->map(fn ($r): array => [
                'source' => 'engine',
                'kind' => (string) $r->event_key,
                'label' => EngineKinds::label((string) $r->event_key, app()->getLocale() !== 'en') ?? (string) $r->event_key,
                'project' => $r->project_name === null ? null : (string) $r->project_name,
                'recipient' => $r->recipient_address === null ? null : (string) $r->recipient_address,
                'audience' => (string) $r->scope === 'external' || (string) $r->recipient_kind === 'external' ? 'external' : 'internal',
                'status' => (string) $r->status,
                'state' => MailDeliveryState::of((string) $r->status, null),
                'failure_code' => null,
                'reason' => $r->reason === null ? null : (string) $r->reason,
                'attempts' => (int) ($r->attempt ?? 1),
                'at' => (string) ($r->provider_accepted_at ?? $r->created_at),
                'sort' => (string) $r->created_at,
            ]);

        $external = DB::table('external_digest_sends as s')
            ->leftJoin('external_digest_recipients as x', 'x.id', '=', 's.recipient_id')
            ->where('s.tenant_id', $tenantId)
            ->orderByDesc('s.created_at')->limit(self::LOG_LIMIT)
            ->get(['s.*', 'x.email as recipient_email'])
            ->map(fn ($r): array => [
                'source' => 'external',
                'kind' => 'spend_summary_external',
                'label' => app()->getLocale() === 'en' ? 'Budget follow-up (outside the platform)' : 'متابعة الميزانية (خارج المنصة)',
                'project' => null,
                'recipient' => $r->recipient_email === null ? null : (string) $r->recipient_email,
                'audience' => 'external',
                'status' => (string) $r->status,
                'state' => MailDeliveryState::of((string) $r->status, null),
                'failure_code' => null,
                'reason' => $r->reason === null ? null : (string) $r->reason,
                'attempts' => 1,
                'at' => (string) ($r->sent_at ?? $r->created_at),
                'sort' => (string) $r->created_at,
            ]);

        $rows = $digests->concat($transactional)->concat($engine)->concat($external)
            ->sortByDesc('sort')
            ->take(self::LOG_LIMIT)
            ->map(function (array $row): array {
                unset($row['sort']);

                return $row;
            })
            ->values()
            ->all();

        return ApiResponse::success($rows, 'Delivery log.');
    }

    /** TEAM-MAIL-LOG-001 — a digest ledger kind in words («ملخص المشاريع : أمس», «ملخص المشروع : الأسبوع الماضي», …). */
    private static function digestLabel(string $kind, bool $perProject): string
    {
        $ar = app()->getLocale() !== 'en';
        $manual = str_starts_with($kind, 'now_');
        $base = $manual ? substr($kind, 4) : $kind;
        $period = match ($base) {
            'daily' => $ar ? 'أمس' : 'yesterday',
            'weekly' => $ar ? 'الأسبوع الماضي' : 'last week',
            'monthly' => $ar ? 'الشهر الماضي' : 'last month',
            default => null,
        };
        if ($period === null) {
            return self::kindLabel($kind);
        }
        $what = $perProject ? ($ar ? 'ملخص المشروع' : 'Project summary') : ($ar ? 'ملخص المشاريع' : 'Projects summary');

        return $what.' : '.$period.($manual ? ($ar ? ' (أرسل الآن)' : ' (sent now)') : '');
    }

    /** A transactional / alert kind in words, from the catalogue; the raw key when the catalogue has none. */
    private static function kindLabel(string $kind): string
    {
        $ar = app()->getLocale() !== 'en';
        $known = [
            'project_budget' => ['تنبيه ميزانية المشروع', 'Project budget notice'],
            'budget_risk' => ['تنبيه أداء', 'Performance alert'],
            'alerts' => ['تنبيهات الأداء', 'Performance alerts'],
            'sync_failed' => ['تنبيه الربط', 'Connection notice'],
            'token_expiring' => ['تنبيه الربط', 'Connection notice'],
            'report_ready' => ['التقرير جاهز', 'Report ready'],
            'security' => ['تنبيه أمان', 'Security notice'],
            'invitation' => ['دعوة', 'Invitation'],
        ];
        if (isset($known[$kind])) {
            return $known[$kind][$ar ? 0 : 1];
        }

        return EngineKinds::label(EngineKinds::eventFor($kind), $ar) ?? $kind;
    }

    /**
     * The most recent attempt at this person, from whichever ledger holds it.
     *
     * There are two, and they are not redundant: `mail_deliveries` records transactional messages
     * (MAIL-009) by recipient address, `digest_sends` records digests and alerts (MAIL-003, MAIL-006)
     * by user id and period. Reading only one would show «nothing has ever been sent» to somebody
     * receiving a digest every morning.
     *
     * @return array{at: ?string, kind: string, status: string, source: string}|null
     */
    private function lastMessage(int $userId, string $email, string $tenantId): ?array
    {
        // This workspace's only, as `deliveries()` asks: another workspace's mail is not its manager's to read.
        $delivery = DB::table('mail_deliveries')
            ->where('tenant_id', $tenantId)
            // EMAIL-TEST-SEND-001 — keyed by person, so a platform admin who is also a member would show their own test here.
            ->where('kind', '!=', TransactionalMailer::TEST_KIND)
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('recipient', $email))
            ->orderByDesc('created_at')
            ->first();

        $digest = DB::table('digest_sends')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();

        $candidates = array_values(array_filter([
            $delivery === null ? null : [
                'at' => (string) ($delivery->sent_at ?? $delivery->created_at),
                'kind' => (string) $delivery->kind,
                'status' => (string) $delivery->status,
                'source' => 'transactional',
                'sort' => (string) $delivery->created_at,
            ],
            $digest === null ? null : [
                'at' => (string) ($digest->sent_at ?? $digest->created_at),
                'kind' => (string) $digest->kind,
                'status' => (string) $digest->status,
                'source' => 'digest',
                'sort' => (string) $digest->created_at,
            ],
        ]));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($b['sort'], $a['sort']));
        $winner = $candidates[0];
        unset($winner['sort']);

        return $winner;
    }

    /**
     * One word for «what is happening to this person's email», in the order that matters.
     *
     * `silent` outranks everything, including a successful send from before they switched
     * everything off — the question a manager is asking is about now.
     *
     * @param  list<string>  $categories
     * @param  array{daily: bool, weekly: bool, alerts: bool}  $rhythms
     * @param  array{at: ?string, kind: string, status: string, source: string}|null  $last
     */
    private function state(array $categories, array $rhythms, ?array $last): string
    {
        if ($categories === [] && ! in_array(true, array_values($rhythms), true)) {
            return 'silent';
        }

        if ($last === null) {
            return 'never_sent';
        }

        return match ($last['status']) {
            'sent' => 'sent',
            'failed' => 'failed',
            'awaiting_credentials', 'awaiting_provider_credentials' => 'awaiting_credentials',
            'sandbox' => 'sandbox',
            default => $last['status'],
        };
    }

    /**
     * Project names, qualified by client.
     *
     * The same reason MAIL-010 qualifies them: three clients each have a «Q3 Launch», and a list of
     * the projects somebody covers is useless if it names three of them identically.
     *
     * @return array<string, string>
     */
    private function projectNames(string $tenantId): array
    {
        return Project::query()->where('tenant_id', $tenantId)->with('clientWorkspace:id,name')
            ->get(['id', 'name', 'client_workspace_id'])
            ->mapWithKeys(static fn (Project $p): array => [
                (string) $p->id => $p->clientWorkspace?->name !== null
                    ? $p->clientWorkspace->name.' · '.$p->name
                    : (string) $p->name,
            ])->all();
    }
}
