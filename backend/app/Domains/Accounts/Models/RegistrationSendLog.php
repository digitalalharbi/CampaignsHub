<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to send a signup challenge (SIGNUP-VERIFY-POLICY-001): what the send limits and the cost estimate count.
 *
 * Not tenant-scoped, because an applicant has no tenant. It holds no address and no secret.
 */
final class RegistrationSendLog extends Model
{
    use HasUlids;

    public const RESERVED = 'reserved';

    public const BLOCKED_GLOBAL = 'blocked_global_cap';

    public const BLOCKED_APPLICATION = 'blocked_application_cap';

    /** The provider's outcomes that mean a message left for the recipient, so it may have cost something. */
    public const DELIVERED = ['sent', 'queued', 'pending'];

    public const UPDATED_AT = null;

    protected $table = 'registration_send_log';

    protected $guarded = ['*'];

    protected $casts = ['created_at' => 'datetime'];
}
