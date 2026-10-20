<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Who receives this department's weekly Query Raised Report. Nominated by an admin.
     *
     * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
     */
    public function heads(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_heads')->withTimestamps()->orderBy('name');
    }

    /**
     * The form a department name is compared in. Invoices store their department as text, so a
     * stray capital or space must not separate an invoice from its department.
     */
    public static function normalizeName(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
