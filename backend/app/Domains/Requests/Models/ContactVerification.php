<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An OTP / magic-code challenge for a phone or email destination. Only the code hash is stored; the row
 * records honest delivery state (awaiting_provider_credentials when no provider is wired). A verified row
 * is single-use (consumed_at) so a verification token cannot be replayed.
 */
final class ContactVerification extends Model
{
    use HasUlids;

    protected $table = 'request_contact_verifications';

    protected $guarded = ['id'];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'consumed_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
