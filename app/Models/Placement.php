<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Placement extends Model {

    protected $table = 'fact_placements';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'branch_id',
        'financial_product_id',
        'client_name',
        'normalized_client_name',
        'promoter_name',
        'normalized_promoter_name',
        'coordinator_name',
        'amount',
        'operation_date',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'operation_date' => 'date',
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

    public function financialProduct(): BelongsTo {
        return $this->belongsTo(FinancialProduct::class);
    }

}
