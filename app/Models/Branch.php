<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model {

    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employeeBranchAssignments(): HasMany {
        return $this->hasMany(EmployeeBranchAssignment::class);
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
