<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PeriodIncident extends Model { protected $fillable=['period_summary_id','type','severity','message','context']; protected $casts=['context'=>'array']; }
