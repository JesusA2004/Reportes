<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model {

    protected $fillable = [
        'name',
        'code',
        'type',
        'year',
        'month',
        'sequence',
        'start_date',
        'end_date',
        'is_closed',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
    ];

    protected $appends = [
        'label',
    ];

    public function getLabelAttribute(): string {
        if (!empty($this->name)) {
            return $this->name;
        }

        if ($this->type === 'weekly') {
            return sprintf('Semana %d %04d-%02d', (int) $this->sequence, (int) $this->year, (int) $this->month);
        }

        $months = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
        $monthName = $months[(int) $this->month] ?? 'Periodo';

        return "{$monthName} {$this->year}";
    }

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

    public function radiographyRuns(): HasMany {
        return $this->hasMany(PeriodRadiographyRun::class);
    }

}
