<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Cajero extends Authenticatable implements JWTSubject
{
   use HasApiTokens, HasFactory, Notifiable;
   public function specialAccessLogs()
   {
      return $this->hasMany(SpecialAccessLog::class);
   }
   public function Sucursale()
   {
      return $this->belongsTo(Sucursale::class);
   }

   protected $table = 'cajeros'; // Nombre de la tabla de maestros si es personalizada

   protected $fillable = [
      'email',
      'password',
      'codigo_confirmacion',
   ];

   protected $hidden = [
      'password',
      'confirmation_token'
   ];

   protected $casts = [];



   public function getJWTIdentifier()
   {
      return $this->getKey();
   }

   /**
    * Return a key value array, containing any custom claims to be added to the JWT.
    *
    * @return array
    */
   public function getJWTCustomClaims()
   {
      return [];
   }
}
