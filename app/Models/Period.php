<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model {

    protected $fillable = [
        'year',
        'month',
        'code',
        'start_date',
        'end_date',
        'is_closed',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
    ];

    public function reportUploads(): HasMany {
        return $this->hasMany(ReportUpload::class);
    }

    public function processRuns(): HasMany {
        return $this->hasMany(ProcessRun::class);
    }

    public function employeeBranchAssignments(): HasMany {
        return $this->hasMany(EmployeeBranchAssignment::class);
    }

    public function noiMovements(): HasMany {
        return $this->hasMany(NoiMovement::class);
    }

    public function placements(): HasMany {
        return $this->hasMany(Placement::class);
    }

    public function recoveries(): HasMany {
        return $this->hasMany(Recovery::class);
    }

    public function portfolios(): HasMany {
        return $this->hasMany(Portfolio::class);
    }

    public function expenses(): HasMany {
        return $this->hasMany(Expense::class);
    }

    public function monthlyEmployeeSummaries(): HasMany {
        return $this->hasMany(MonthlyEmployeeSummary::class);
    }

}
