<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeMatchRejection extends Model
{
    protected $table = 'employee_match_rejections';

    protected $fillable = [
        'employee_id_a',
        'employee_id_b',
        'period_id',
        'pair_key',
        'reason',
        'created_by',
    ];

    /** Canonical pair key in "minId-maxId" format — use for unique lookups. */
    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    public function employeeA(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id_a');
    }

    public function employeeB(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id_b');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
