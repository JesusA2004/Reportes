<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBranchAssignment extends Model {

    protected $fillable = [
        'period_id',
        'employee_id',
        'branch_id',
        'source_type',
        'source_reference',
        'match_type',
        'confidence',
        'was_manual_reviewed',
        'notes',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'was_manual_reviewed' => 'boolean',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo {
        return $this->belongsTo(Branch::class);
    }

}
