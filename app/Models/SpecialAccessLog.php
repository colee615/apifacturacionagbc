<?php

// app/Models/SpecialAccessLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpecialAccessLog extends Model
{
   use HasFactory;

   protected $fillable = [
      'cajero_id',
      'modified_by',
      'special_access',
      'motivo',
   ];

   public function cajero()
   {
      return $this->belongsTo(Cajero::class, 'cajero_id');
   }

   public function modifiedBy()
   {
      return $this->belongsTo(Cajero::class, 'modified_by');
   }
}
