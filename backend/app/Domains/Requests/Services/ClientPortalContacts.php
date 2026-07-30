<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Models\ExternalRequest;
use Illuminate\Support\Str;

/**
 * The LEGACY reach: which client workspaces a contact detail owns, derived from their own requests.
 *
 * Extracted from `ClientPortalController` so the cutover can compare the two engines without one of
 * them living inside a controller method. It is deliberately a faithful copy of the old rule — this
 * is the thing being migrated AWAY from, and changing its behaviour here would make the parity check
 * compare the new engine against a moving target.
 *
 * It goes when `ClientPortalToken` goes.
 */
final class ClientPortalContacts
{
    /** @return list<string> */
    public function ownedWorkspaceIds(ClientPortalToken $token): array
    {
        return ExternalRequest::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $token->tenant_id)
            ->where(function ($q) use ($token): void {
                if ($token->contact_email) {
                    $q->orWhereRaw('lower(contact_email) = ?', [Str::lower($token->contact_email)]);
                }
                if ($token->contact_phone) {
                    $q->orWhere('contact_phone', $token->contact_phone);
                }
            })
            ->whereNotNull('client_id')
            ->pluck('client_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }
}
