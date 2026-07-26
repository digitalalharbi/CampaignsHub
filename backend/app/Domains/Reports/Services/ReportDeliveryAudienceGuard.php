<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Blocks delivering a report to the wrong audience. An internal report must never be emailed/sent to a
 * recipient who is not an internal team member of the same tenant. Applies to manual send, scheduled
 * delivery, re-send and link delivery. Client/executive reports may go to any recipient.
 */
final class ReportDeliveryAudienceGuard
{
    /**
     * @param  list<string>  $recipientEmails
     *
     * @throws HttpException 422 on an audience/recipient mismatch
     */
    public function assertDeliverable(Report $report, array $recipientEmails): void
    {
        $audience = $report->audience ?? 'client';
        if ($audience !== 'internal') {
            return; // client/executive are safe to send to anyone.
        }

        // Internal reports may only reach internal team members of the SAME tenant.
        $internal = User::query()
            ->where('tenant_id', $report->tenant_id)
            ->whereIn('email', $recipientEmails)
            ->pluck('email')
            ->map(fn ($e) => strtolower((string) $e))
            ->all();

        $external = array_values(array_filter(
            $recipientEmails,
            fn ($e) => ! in_array(strtolower($e), $internal, true),
        ));

        if ($external !== []) {
            throw new HttpException(
                422,
                'Delivery blocked — audience mismatch: an internal report cannot be sent to external/client recipients ('.
                implode(', ', $external).'). Create a client version first.',
            );
        }
    }
}
