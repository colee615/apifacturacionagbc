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
   protected static function boot()
   {
      parent::boot();

      static::creating(function ($model) {
         $latestOrder = static::latest('id')->first();
         $latestCode = $latestOrder ? $latestOrder->codigoOrden : 'venta-00000000';
         $nextCode = 'venta-' . str_pad((int)str_replace('venta-', '', $latestCode) + 1, 8, '0', STR_PAD_LEFT);
         $model->codigoOrden = $nextCode;
      });
   }
}
