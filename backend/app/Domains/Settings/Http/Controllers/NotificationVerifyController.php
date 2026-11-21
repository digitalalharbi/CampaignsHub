<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Alerts\Services\AlertEventMailer;
use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NOTIFICATION-ENROLMENT-001 — «is it actually reaching me?», answered by doing it, for the signed-in person only.
 *
 * Owner, 2026-11-06: «كل مستخدم يحتاج إشعارات وملخصات على الإيميل، هل هي فعّالة أو لا؟». A switch that says «on» proves
 * nothing about the mail. These two actions run the REAL path once, for the person who pressed them:
 *
 * - `digest` — the daily, weekly or monthly digest, built from THEIR scope (so the spend rule and `DigestScope` apply),
 *   through the scheduled sender's own builder, gate and mailer. Its ledger row is `now_<kind>` with its own key, so it never
 *   consumes the slot the scheduled send needs and a scheduled send never blocks it.
 * - `alert` — one clearly marked sample alert through `AlertEventMailer::sweep`, restricted to this one person, so their own
 *   switches, quiet hours and importance floor decide whether it arrives, and the answer says which one stopped it.
 *
 * The recipient is never a parameter. The address is read from the authenticated user, it must have been PROVED, and both
 * routes sit behind the named `mail-test-send` limiter. Every press is audited with the address masked.
 */
final class NotificationVerifyController extends Controller
{
    public function __construct(
        private readonly DigestDispatcher $digests,
        private readonly AlertEventMailer $alerts,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /settings/notifications/digest-now/{kind} */
    public function digest(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['daily', 'weekly', 'monthly'], true), 404);
        /** @var User $user */
        $user = $request->user();
        $tenantId = (string) $this->tenants->tenantId();

        if ($refused = $this->refuseUnprovedAddress($user, 'digest_now.'.$kind)) {
            return $refused;
        }

        $result = $this->digests->sendNow($user, $tenantId, $kind);

        $this->audit->log(
            action: 'notifications.digest_now', entityType: User::class, entityId: (string) $user->getKey(),
            after: ['kind' => $kind, 'state' => $result['state'], 'reason' => $result['reason'], 'recipient' => MailAddress::mask((string) $user->email)],
            tenantId: $tenantId,
        );

        return ApiResponse::success(['kind' => $kind, 'state' => $result['state'], 'reason' => $result['reason']], 'Digest requested.');
    }

    /** POST /settings/notifications/sample-alert */
    public function alert(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenantId = (string) $this->tenants->tenantId();

        if ($refused = $this->refuseUnprovedAddress($user, 'sample_alert')) {
            return $refused;
        }

        $ar = ($user->locale ?? 'ar') !== 'en';
        $eventId = (string) Str::uuid();
        $now = Carbon::now();

        $counts = $this->alerts->sweep($tenantId, [[
            'event_id' => $eventId,
            'notification_type' => 'sample_alert',
            'severity' => 'warning',
            'title' => 'Sample alert — nothing to do',
            'title_ar' => 'تنبيه تجريبي — لا شيء مطلوب',
            'detail' => 'You asked for this one to check that alerts reach you.',
            'detail_ar' => 'طلبته بنفسك للتأكد أن التنبيهات تصلك.',
            'project_id' => null,
            'project_name' => $ar ? 'تجريبي' : 'Sample',
            'triggered_at' => $now->toIso8601String(),
            'url' => Frontend::origin().'/agency/settings/workspace?tab=notifications',
        ]], $now, (int) $user->getKey());

        // The ledger names it, so «why is there an alert_event row nobody raised?» has an answer.
        DB::table('digest_sends')->where('user_id', $user->getKey())->where('kind', AlertEventMailer::KIND)
            ->where('alert_event_id', $eventId)->update(['reason' => DB::raw("coalesce(reason, 'on_demand_sample')")]);

        $state = $this->stateOf($counts);

        $this->audit->log(
            action: 'notifications.sample_alert', entityType: User::class, entityId: (string) $user->getKey(),
            after: ['state' => $state, 'recipient' => MailAddress::mask((string) $user->email)], tenantId: $tenantId,
        );

        return ApiResponse::success(['state' => $state], 'Sample alert requested.');
    }

    /**
     * The one word for what the sweep did with the one person's one item.
     *
     * No row at all is its own answer, `not_enrolled`: it is the very condition this feature exists to expose, and saying
     * «sent» or staying silent about it would hide the finding.
     *
     * @param  array<string,int>  $counts
     */
    private function stateOf(array $counts): string
    {
        foreach (array_keys(array_filter($counts)) as $state) {
            return $state;
        }

        return 'not_enrolled';
    }

    private function refuseUnprovedAddress(User $user, string $what): ?JsonResponse
    {
        if ($user->email_verified_at !== null) {
            return null;
        }

        $this->audit->log(action: 'mail.test_send.refused', after: ['template' => 'notification.'.$what, 'recipient' => MailAddress::mask((string) $user->email)], reason: 'address_not_proven');

        return ApiResponse::error('Your address has not been verified, so nothing can be sent to it.', ['code' => 'address_not_proven'], status: 422);
    }
}
