<?php

namespace App\Models;

use App\Enums\ProcessType;
use App\Enums\ProcessRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessRun extends Model {

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'process_type',
        'status',
        'rows_read',
        'rows_inserted',
        'rows_skipped',
        'rows_with_errors',
        'log',
        'started_at',
        'finished_at',
        'status' => ProcessRunStatus::class,
        'process_type' => ProcessType::class,
    ];

    protected $casts = [
        'rows_read' => 'integer',
        'rows_inserted' => 'integer',
        'rows_skipped' => 'integer',
        'rows_with_errors' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function reportUpload(): BelongsTo {
        return $this->belongsTo(ReportUpload::class);
    }

}
