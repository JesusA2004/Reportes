<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Portfolio extends Model {

    protected $table = 'fact_portfolios';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'branch_id',
        'client_name',
        'normalized_client_name',
        'balance',
        'past_due_balance',
        'days_past_due',
        'portfolio_date',
        'raw_payload',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'past_due_balance' => 'decimal:2',
        'days_past_due' => 'integer',
        'portfolio_date' => 'date',
        'raw_payload' => 'array',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function reportUpload(): BelongsTo {
        return $this->belongsTo(ReportUpload::class);
    }

    public function branch(): BelongsTo {
        return $this->belongsTo(Branch::class);
    }

}
