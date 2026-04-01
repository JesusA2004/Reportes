<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model {

    protected $table = 'fact_expenses';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'employee_id',
        'branch_id',
        'category',
        'concept',
        'amount',
        'paid_amount',
        'expense_date',
        'observations',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'expense_date' => 'date',
        'raw_payload' => 'array',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function reportUpload(): BelongsTo {
        return $this->belongsTo(ReportUpload::class);
    }

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo {
        return $this->belongsTo(Branch::class);
    }

}
