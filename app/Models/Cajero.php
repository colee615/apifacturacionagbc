<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Cajero extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public function Sucursale(){
        return $this->belongsTo(Sucursale::class);
    }

    protected $table = 'cajeros'; // Nombre de la tabla de maestros si es personalizada

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'confirmation_token'
    ];

    protected $casts = [

    ];
}
