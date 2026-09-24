<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $fillable = ['name', 'vendor_code', 'is_expense_account', 'credit_limit', 'credit_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_expense_account' => 'boolean',
            'credit_limit' => 'decimal:2',
            'credit_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
