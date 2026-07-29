<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'customer_code', 'credit_limit', 'credit_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'credit_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
