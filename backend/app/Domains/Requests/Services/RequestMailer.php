<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Client request mail — EMAIL-GAPS (§17.3.2).
 *
 * Off behind `requests.client_mail_enabled`. It changes nothing about what the portal can do or who may
 * do it: it reads a request after the work is finished, and any failure here stays here.
 *
 * ## Recipients are scoped at send time, by tenant and project
 *
 * - The request must be coherent: its client and its project in its own tenant, its project under its
 *   client. Otherwise nothing is sent to anybody, and the refusal is recorded.
 * - The TEAM is the active app and agency members of THIS tenant who hold `requests.view` and whose reach
 *   includes the request. With a project, `DigestScope` decides; with only a client, the membership's
 *   scopes do. A member of another tenant is never a candidate.
 * - The CLIENT is the request's own contact. If that address belongs to a client-portal member of this
 *   tenant who is confined to another client or project, the message is refused and recorded.
 *
 * The body says what happened and where to read it. It never quotes a comment, an internal note or a
 * related campaign.
 */
final class RequestMailer
{
    public const REFUSED = 'refused_scope';

    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly DigestScope $scope,
        private readonly TenantContext $tenants,
    ) {}

    public static function enabled(): bool
    {
        return config('requests.client_mail_enabled') === true;
    }

    /** A lifecycle event, to the request's contact. @return string the ledger state */
    public function toClient(ExternalRequest $request, string $event, string $destination, string $deepLink, string $dedupKey): string
    {
        $tenantId = (string) $request->tenant_id;
        $key = 'client-request:'.$dedupKey;
        $contact = User::query()->whereRaw('lower(email) = ?', [Str::lower($destination)])->first();
        // A contact with an account has a language. One without has told us none, so the product default.
        $locale = $contact !== null && $contact->locale === 'en' ? 'en' : 'ar';
        $ar = $locale === 'ar';

        if (! $this->isCoherent($request) || ! $this->contactMayReach($request, $destination)) {
            return $this->mailer->refuse($destination, self::REFUSED, 'client_request', 'mail.operational', $locale, $tenantId, null, $key);
        }

        $words = self::clientWords($event, $ar);

        return $this->mailer->queue(
            recipient: $destination,
            mail: new OperationalMail(
                kind: 'client_request',
                severity: $event === 'info_requested' ? 'warning' : 'info',
                title: $words['title'],
                detail: $words['detail'],
                context: (string) $request->reference,
                lang: $locale,
                recipientName: (string) ($request->contact_name ?? ''),
                path: $deepLink,
                action: $ar ? 'فتح الطلب' : 'Open the request',
                why: $ar ? 'وصلتك هذه الرسالة لأنك صاحب هذا الطلب في كامبينز هب.' : 'You are receiving this because you filed this request in CampaignsHub.',
            ),
            kind: 'client_request',
            template: 'mail.operational',
            locale: $locale,
            tenantId: $tenantId,
            dedupKey: $key,
        );
    }

    /** A client filed a request in the portal: the team who can reach it is told. */
    public function newRequestToTeam(ExternalRequest $request): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            if (! $this->isCoherent($request)) {
                $this->mailer->refuse('(team)', self::REFUSED, 'client_request', 'mail.operational', 'ar', (string) $request->tenant_id, null, 'request-team:'.$request->id);

                return;
            }

            foreach ($this->team($request) as $user) {
                $locale = in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar';
                $ar = $locale === 'ar';

                $this->mailer->queue(
                    recipient: (string) $user->email,
                    mail: new OperationalMail(
                        kind: 'client_request',
                        severity: 'info',
                        title: $ar ? 'طلب جديد من عميل' : 'A new request from a client',
                        detail: $ar
                            ? "وصل طلب جديد برقم {$request->reference} من بوابة العملاء، وينتظر مراجعة فريقك."
                            : "Request {$request->reference} has arrived from the client portal and is waiting for your team.",
                        context: (string) ($request->company_name ?: $request->reference),
                        lang: $locale,
                        recipientName: (string) $user->name,
                        path: "/agency/requests/{$request->id}",
                        action: $ar ? 'مراجعة الطلب' : 'Review the request',
                        why: $ar ? 'وصلتك هذه الرسالة لأن هذا الطلب ضمن نطاق عملك.' : 'You are receiving this because this request is within your access.',
                    ),
                    kind: 'client_request',
                    template: 'mail.operational',
                    locale: $locale,
                    tenantId: (string) $request->tenant_id,
                    userId: (int) $user->getKey(),
                    dedupKey: 'request-team:'.$request->id.':'.$user->getKey(),
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Is this request's own client and project in its own tenant, and the project under the client?
     *
     * The checks read without a tenant scope, because the question is what the rows really say.
     */
    public function isCoherent(ExternalRequest $request): bool
    {
        $tenantId = (string) $request->tenant_id;

        if ($tenantId === '') {
            return false;
        }

        if ($request->client_id !== null) {
            $client = ClientWorkspace::withoutGlobalScopes()->find($request->client_id);

            if ($client === null || (string) $client->tenant_id !== $tenantId) {
                return false;
            }
        }

        if ($request->project_id !== null) {
            $project = Project::withoutGlobalScopes()->find($request->project_id);

            if ($project === null || (string) $project->tenant_id !== $tenantId) {
                return false;
            }

            if ($request->client_id !== null && (string) $project->client_workspace_id !== (string) $request->client_id) {
                return false;
            }
        }

        return true;
    }

    /** @return list<User> */
    public function team(ExternalRequest $request): array
    {
        $tenantId = (string) $request->tenant_id;
        $this->tenants->setTenantId($tenantId);

        try {
            $memberships = Membership::query()->with('scopes')->where('tenant_id', $tenantId)->where('status', 'active')
                ->whereIn('portal', [Portal::App->value, Portal::Agency->value])->get();

            $out = [];

            foreach ($memberships as $membership) {
                $user = User::query()->find($membership->user_id);

                if ($user !== null && (string) $user->email !== '' && $user->hasPermission('requests.view') && $this->memberReaches($user, $membership, $request)) {
                    $out[(int) $user->getKey()] = $user;
                }
            }

            ksort($out);

            return array_values($out);
        } finally {
            $this->tenants->forget();
        }
    }

    private function memberReaches(User $user, Membership $membership, ExternalRequest $request): bool
    {
        $tenantId = (string) $request->tenant_id;

        if ($request->project_id !== null) {
            return in_array((string) $request->project_id, $this->scope->projectIdsFor($user, $tenantId), true);
        }

        $clients = $membership->clientScopeIds();

        if ($request->client_id !== null && $clients !== []) {
            return in_array((string) $request->client_id, $clients, true);
        }

        // Named project scopes and no project on the request: nothing says this person may see it.
        return $membership->projectScopeIds() === [] && $clients === [];
    }

    private function contactMayReach(ExternalRequest $request, string $destination): bool
    {
        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower($destination)])->first();

        if ($user === null) {
            return true;
        }

        $membership = Membership::query()->with('scopes')->where('tenant_id', $request->tenant_id)
            ->where('user_id', $user->getKey())->where('status', 'active')
            ->where('portal', Portal::ClientPortal->value)->first();

        if ($membership === null) {
            return true;
        }

        $clients = $membership->clientScopeIds();
        $projects = $membership->projectScopeIds();

        if ($request->client_id !== null && ! in_array((string) $request->client_id, $clients, true)) {
            return false;
        }

        return $projects === [] || ($request->project_id !== null && in_array((string) $request->project_id, $projects, true));
    }

    /** @return array{title: string, detail: string} */
    private static function clientWords(string $event, bool $ar): array
    {
        [$title, $detail, $titleEn, $detailEn] = match ($event) {
            'received' => ['استلمنا طلبك', 'وصل طلبك إلى الفريق وستصلك المستجدات هنا.', 'We received your request', 'Your request has reached the team, and updates will arrive here.'],
            'info_requested' => ['طلبك يحتاج معلومات منك', 'طلب الفريق معلومات إضافية لإكمال طلبك.', 'Your request needs information from you', 'The team has asked for more information to complete your request.'],
            'team_reply' => ['ردّ جديد على طلبك', 'أضاف الفريق ردًا على طلبك، ويمكنك قراءته من صفحة الطلب.', 'A new reply on your request', 'The team replied on your request. You can read it on the request page.'],
            'approved' => ['تمت الموافقة على طلبك', 'وافق الفريق على طلبك وسيبدأ العمل عليه.', 'Your request was approved', 'The team approved your request and will start work on it.'],
            'in_progress' => ['بدأ العمل على طلبك', 'طلبك قيد التنفيذ الآن.', 'Work has started on your request', 'Your request is in progress now.'],
            'completed' => ['اكتمل طلبك', 'أنهى الفريق العمل على طلبك.', 'Your request is complete', 'The team has finished work on your request.'],
            default => ['تحديث على طلبك', 'تغيّرت حالة طلبك، ويمكنك متابعتها من صفحة الطلب.', 'An update on your request', 'The status of your request changed. You can follow it on the request page.'],
        };

        return $ar ? ['title' => $title, 'detail' => $detail] : ['title' => $titleEn, 'detail' => $detailEn];
    }
}
