<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'sort_order', 'job_no', 'customer_id',
        'description', 'currency', 'amount', 'tax_rate', 'tax_amount', 'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'customer_id' => 'integer',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
