<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialProduct extends Model {

    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function placements(): HasMany {
        return $this->hasMany(Placement::class);
    }

}
