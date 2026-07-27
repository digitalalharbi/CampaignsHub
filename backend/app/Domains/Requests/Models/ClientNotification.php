<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A client-facing notification delivery record (email/WhatsApp) for a request lifecycle event. */
final class ClientNotification extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'client_notifications';

    protected $guarded = ['id'];

    protected $casts = ['attempts' => 'integer'];
}
