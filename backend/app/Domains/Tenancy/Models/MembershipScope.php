<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entity a membership is confined to (ADR 0002).
 *
 * A membership says who someone is in a portal; these rows say what that reaches. Having NONE means
 * unrestricted within the tenant — an agency owner sees every client. Having one or more is a hard
 * ceiling: those entities and nothing else, whatever the role would otherwise permit.
 *
 * Kept a separate table rather than a column because the real cases are plural: an account manager
 * responsible for three clients, a freelancer on two projects. As a column that had to become three
 * membership rows, which then showed up as three workspaces in the switcher for what is one job.
 */
final class MembershipScope extends Model
{
    use HasUuidKey;

    public const TYPE_CLIENT = 'client_workspace';

    public const TYPE_PROJECT = 'project';

    protected $table = 'membership_scopes';

    protected $fillable = ['membership_id', 'scope_type', 'scope_id'];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
