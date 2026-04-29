<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PeriodRadiographyRun extends Model { protected $fillable=['period_id','period_summary_id','status','log','started_at','finished_at','created_by']; protected $casts=['started_at'=>'datetime','finished_at'=>'datetime']; }
