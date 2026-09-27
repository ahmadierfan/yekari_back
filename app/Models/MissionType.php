<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionType extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fields' => 'array', 'needs_dropoff' => 'boolean', 'active' => 'boolean'];
    }
}
