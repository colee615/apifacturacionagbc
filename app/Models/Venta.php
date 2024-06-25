<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
   use HasFactory;
   public function detalleVentas()
   {
      return $this->hasMany(DetalleVenta::class);
   }
   public function cliente()
   {
      return $this->belongsTo(Cliente::class);
   }
   public function cajero()
   {
      return $this->belongsTo(Cajero::class);
   }
}
