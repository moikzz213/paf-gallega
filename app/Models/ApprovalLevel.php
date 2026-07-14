<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalLevel extends Model
{
    protected $fillable = ['level', 'name', 'min_amount', 'default_approver_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'min_amount' => 'decimal:2',
            'default_approver_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** The user pre-filled onto a request's chain for this level (editable per request). */
    public function defaultApprover()
    {
        return $this->belongsTo(User::class, 'default_approver_id');
    }

    /** Levels a given invoice total must pass through, in order. */
    public static function requiredFor(float $totalAmount)
    {
        return static::where('is_active', true)
            ->where('min_amount', '<=', $totalAmount)
            ->orderBy('level')
            ->get();
    }
}
