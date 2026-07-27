<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $module
 * @property string $name_ar
 * @property string $name_en
 */
class RequestType extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'bool'];
}
