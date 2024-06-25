<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetalleVenta extends Model
{
   use HasFactory;
   // Relación con Venta
   public function venta()
   {
      return $this->belongsTo(Venta::class);
   }

   // Relación con Servicio
   public function servicio()
   {
      return $this->belongsTo(Servicio::class);
   }
}
