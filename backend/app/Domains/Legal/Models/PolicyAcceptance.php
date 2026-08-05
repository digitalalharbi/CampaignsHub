<?php

declare(strict_types=1);

namespace App\Domains\Legal\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * LEGAL-003 — one person agreeing to one version of one document, once.
 *
 * The version is what makes the row evidence. It points at text held in git, which cannot be edited
 * after the fact — so «accepted terms v1.0 effective 2026-08-07» remains answerable a year later,
 * where a bare `accepted: true` against a policy somebody has since rewritten only looks like it is.
 */
final class PolicyAcceptance extends Model
{
    use HasUuidKey;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'email', 'document', 'version', 'effective',
        'context', 'accepted_at', 'ip', 'user_agent', 'locale',
    ];

    protected $casts = [
        'effective' => 'date',
        'accepted_at' => 'datetime',
    ];

    public const CONTEXTS = ['registration', 'payment', 'reacceptance'];
}
