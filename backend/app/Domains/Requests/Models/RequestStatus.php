<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name_ar
 * @property string $name_en
 * @property bool $is_terminal
 * @property bool $pauses_sla
 * @property bool $is_client_visible
 */
class RequestStatus extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'is_terminal' => 'bool',
        'pauses_sla' => 'bool',
        'is_client_visible' => 'bool',
    ];
}
