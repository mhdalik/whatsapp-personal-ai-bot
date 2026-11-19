<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';
    protected $guarded  = [];

    protected function casts(): array
    {
        return [
            'datetime'    => 'datetime',
            'income'  => 'decimal:2',
            'expense' => 'decimal:2',
            'is_loan' => 'boolean',
            'meta'    => 'array',
        ];
    }
}
