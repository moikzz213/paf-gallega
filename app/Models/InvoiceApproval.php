<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceApproval extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['invoice_id', 'level', 'level_name', 'status', 'approver_id', 'comments', 'acted_at'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
