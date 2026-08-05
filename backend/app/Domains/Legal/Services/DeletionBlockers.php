<?php

declare(strict_types=1);

namespace App\Domains\Legal\Services;

use Illuminate\Support\Facades\DB;

/**
 * LEGAL-002 — what stands between a deletion request and the deletion.
 *
 * ## The distinction this class exists to hold
 *
 * A data subject has a right to erasure, and an operator has an obligation to keep accounting
 * records. Those are not in conflict as often as they appear: the operational data — clients,
 * projects, campaigns, creatives, reports, connections — can almost always go, while a handful of
 * financial records must stay. What is unacceptable is either extreme: destroying an invoice because
 * somebody clicked delete, or refusing the whole request because one invoice exists.
 *
 * So this does not answer «may we delete?» with a boolean. It returns the specific reasons, each
 * with a count and a sentence in both languages, so the requester is told what is standing in the way
 * and what settling it would take — and the operator can act on the part that is free to go.
 *
 * ## Why it reads the billing tables directly
 *
 * Because the question is about rows that exist, not about a domain object's opinion of itself. A
 * subscription service could report «not active» while an unpaid invoice sits in the table; the
 * blocker check has to see the table.
 */
final class DeletionBlockers
{
    /**
     * Invoice states that represent money still owed or still owing an explanation.
     *
     * `issued` and `overdue` are open obligations. A `paid` invoice does not block deletion of the
     * workspace — it blocks deletion of ITSELF, which the retention policy already states and which
     * this check does not need to express.
     */
    private const OPEN_INVOICE_STATES = ['issued', 'overdue', 'partially_paid', 'pending'];

    /** Subscription states that mean the customer is still on a live commercial arrangement. */
    private const LIVE_SUBSCRIPTION_STATES = ['active', 'trialing', 'past_due', 'grace'];

    /**
     * Everything preventing this tenant's data from being deleted right now.
     *
     * An empty list means the request can proceed. It never means «nothing was checked» — a tenant
     * that does not exist is itself reported, rather than passing silently.
     *
     * @return list<array{code: string, count: int, ar: string, en: string}>
     */
    public function forTenant(?string $tenantId): array
    {
        if ($tenantId === null) {
            /*
             * A request that names no workspace is not a free pass.
             *
             * It is the normal case for someone writing from the public page, and it means the
             * operator must first establish which account they are — not that there is nothing to
             * check. Reporting it as a blocker keeps the request in review instead of letting it
             * fall through to «ready to delete».
             */
            return [[
                'code' => 'identity_unverified',
                'count' => 0,
                'ar' => 'لم تُربط هذه الطلبات بحساب بعد. يجب التحقق من هوية مقدّم الطلب قبل تنفيذ أي حذف.',
                'en' => 'This request is not linked to an account yet. The requester’s identity must be verified before any deletion.',
            ]];
        }

        $blockers = [];

        $openInvoices = $this->count('invoices', $tenantId, 'status', self::OPEN_INVOICE_STATES);
        if ($openInvoices > 0) {
            $blockers[] = [
                'code' => 'open_invoices',
                'count' => $openInvoices,
                'ar' => "توجد {$openInvoices} فاتورة غير مسددة أو قائمة. تُسوّى أولًا، ثم يمكن المضي في الحذف.",
                'en' => "There are {$openInvoices} unsettled invoices. These are settled first, then the deletion can proceed.",
            ];
        }

        $liveSubscriptions = $this->count('subscriptions', $tenantId, 'status', self::LIVE_SUBSCRIPTION_STATES);
        if ($liveSubscriptions > 0) {
            $blockers[] = [
                'code' => 'active_subscription',
                'count' => $liveSubscriptions,
                'ar' => 'يوجد اشتراك فعّال لم يُلغَ. ألغِ الاشتراك أولًا — الإلغاء لا يحذف أي بيانات.',
                'en' => 'An active subscription has not been cancelled. Cancel it first — cancelling deletes no data.',
            ];
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $states
     */
    private function count(string $table, string $tenantId, string $column, array $states): int
    {
        return (int) DB::table($table)
            ->where('tenant_id', $tenantId)
            ->whereIn($column, $states)
            ->count();
    }
}
