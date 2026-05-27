<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodDatabaseUpdateRun extends Model
{
    protected $fillable = [
        'period_id',
        'created_by',
        'status',
        'log',
        'error_message',
        'metadata',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'queued_at'   => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'metadata'    => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
