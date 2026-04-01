<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyEmployeeSummary extends Model {

    protected $table = 'fact_monthly_employee_summary';

    protected $fillable = [
        'period_id',
        'employee_id',
        'branch_id',
        'total_payments',
        'total_bonuses',
        'total_discounts',
        'total_expenses',
        'net_amount',
        'has_useful_movement',
        'included_in_report',
        'exclusion_reason',
    ];

    protected $casts = [
        'total_payments' => 'decimal:2',
        'total_bonuses' => 'decimal:2',
        'total_discounts' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'has_useful_movement' => 'boolean',
        'included_in_report' => 'boolean',
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
