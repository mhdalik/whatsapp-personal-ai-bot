<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array'];
    }
}
