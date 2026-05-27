<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAlias extends Model
{
    protected $fillable = [
        'employee_id',
        'alias_name',
        'normalized_alias',
        'source',
        'confidence',
        'created_by',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
