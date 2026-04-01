<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model {

    protected $fillable = [
        'employee_code',
        'full_name',
        'normalized_name',
        'first_name',
        'paternal_last_name',
        'maternal_last_name',
        'is_active',
        'source_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employeeBranchAssignments(): HasMany {
        return $this->hasMany(EmployeeBranchAssignment::class);
    }

    public function noiMovements(): HasMany {
        return $this->hasMany(NoiMovement::class);
    }

    public function expenses(): HasMany {
        return $this->hasMany(Expense::class);
    }

    public function monthlyEmployeeSummaries(): HasMany {
        return $this->hasMany(MonthlyEmployeeSummary::class);
    }

}
