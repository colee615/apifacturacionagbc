<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
   use HasFactory;

   protected $fillable = [
      'cajero_id',
      'ip_address',
      'user_agent',
      'login_time',
   ];

   public function cajero()
   {
      return $this->belongsTo(Cajero::class);
   }
}
