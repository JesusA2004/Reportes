<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportUpload extends Model {

    protected $fillable = [
        'period_id',
        'data_source_id',
        'original_name',
        'stored_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function dataSource(): BelongsTo {
        return $this->belongsTo(DataSource::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function processRuns(): HasMany {
        return $this->hasMany(ProcessRun::class);
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

}
