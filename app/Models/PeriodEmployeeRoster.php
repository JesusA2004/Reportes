<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodEmployeeRoster extends Model {

    protected $fillable = [
        'period_id',
        'employee_id',
        'employee_key',
        'nombre_normalizado',
        'nombre_original',
        'branch_id',
        'branch_name',
        'is_branch_operativa',
        'source',
        'appears_in_nomina_normal',
        'appears_in_nomina_fiscal',
        'is_active_for_period',
        'movement_type',
    ];

    protected $casts = [
        'is_branch_operativa'      => 'boolean',
        'appears_in_nomina_normal' => 'boolean',
        'appears_in_nomina_fiscal' => 'boolean',
        'is_active_for_period'     => 'boolean',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }
}
