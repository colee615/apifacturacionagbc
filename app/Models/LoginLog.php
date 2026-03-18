<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
   use HasFactory;

   protected $fillable = [
      'usuario_id',
      'ip_address',
      'user_agent',
      'login_time',
   ];

   public function usuario()
   {
      return $this->belongsTo(Usuario::class, 'usuario_id');
   }
}
