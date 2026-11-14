<?php

declare(strict_types=1);

namespace App\Domains\Legal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PLATFORM-INBOX-ATTENTION-001 — one administrator's answer to one visitor, and how it left.
 *
 * Written only by `PlatformInboxReplies`. Belongs to no tenant. `delivery_status` is the mail ledger's state at
 * the time of writing; readers ask the ledger through `mail_dedup_key` for the state now.
 *
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property int|null $author_id
 * @property string $recipient
 * @property string $body
 * @property string $delivery_status
 * @property string|null $mail_dedup_key
 * @property Carbon|null $created_at
 */
final class PlatformInboxReply extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];
}
