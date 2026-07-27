<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

/**
 * Allowed status transitions for external requests. Enforced in the backend — a client or a crafted
 * request can never jump illogically (e.g. new → completed) without the explicit restore path.
 */
final class RequestStatusMachine
{
    /** @var array<string, list<string>> */
    private const FORWARD = [
        'new' => ['under_review', 'qualified', 'rejected', 'cancelled'],
        'under_review' => ['waiting_client', 'qualified', 'rejected', 'cancelled'],
        'waiting_client' => ['under_review', 'qualified', 'cancelled'],
        'qualified' => ['approved', 'waiting_client', 'rejected', 'cancelled'],
        'approved' => ['in_progress', 'cancelled'],
        'in_progress' => ['waiting_client', 'completed', 'cancelled'],
        'completed' => ['archived'],
        'rejected' => ['archived'],
        'cancelled' => ['archived'],
        'archived' => [], // only via the dedicated restore action (requests.update + not here)
    ];

    /** @return list<string> */
    public function allowedFrom(string $status): array
    {
        return self::FORWARD[$status] ?? [];
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->allowedFrom($from), true);
    }
}
