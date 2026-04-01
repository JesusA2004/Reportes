<?php

namespace App\Models;

use App\Enums\MatchType;
use App\Enums\SourceType;
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
        'source_type' => SourceType::class,
        'match_type' => MatchType::class,
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
