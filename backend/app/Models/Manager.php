<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Manager extends Model
{
    protected $guarded = [];

    protected $casts = [
        'permissions' => 'array',
        'is_admin'    => 'boolean',
        'telegram_id' => 'integer',
    ];
}
