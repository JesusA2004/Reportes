<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PeriodBranchSummary extends Model { protected $fillable=['period_summary_id','branch_id','metrics']; protected $casts=['metrics'=>'array']; public function summary(){return $this->belongsTo(PeriodSummary::class,'period_summary_id');}}
