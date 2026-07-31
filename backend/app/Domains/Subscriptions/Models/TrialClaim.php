<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * An identity that has already been given a trial (PAY-004).
 *
 * Only the HASH is stored. The question is "has this been seen before?", which needs no plaintext —
 * and a table of customer emails, phone numbers and card fingerprints is precisely the thing not to
 * keep in recoverable form.
 */
final class TrialClaim extends Model
{
    use HasUuidKey;

    protected $table = 'trial_claims';

    protected $fillable = ['kind', 'value_hash', 'registration_request_id', 'tenant_id'];
}
