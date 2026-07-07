<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalLevel extends Model
{
    protected $fillable = ['level', 'name', 'min_amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'min_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
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
