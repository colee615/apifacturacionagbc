<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
   use HasFactory;

   public const CODIGO_ORDEN_PREFIX = 'AGBC-';
   public const CODIGO_ORDEN_PAD = 7;

   public function detalleVentas()
   {
      return $this->hasMany(DetalleVenta::class);
   }

   protected static function boot()
   {
      parent::boot();

      static::creating(function ($model) {
         if (!empty($model->codigoOrden)) {
            return;
         }

         $model->codigoOrden = static::nextCodigoOrden();
      });
   }

   public static function formatCodigoOrdenFromNumber(int $number): string
   {
      return self::CODIGO_ORDEN_PREFIX . str_pad((string) max($number, 1), self::CODIGO_ORDEN_PAD, '0', STR_PAD_LEFT);
   }

   public static function nextCodigoOrden(): string
   {
      $latestCode = (string) static::query()
         ->where('codigoOrden', 'like', self::CODIGO_ORDEN_PREFIX . '%')
         ->latest('id')
         ->value('codigoOrden');

      $nextNumber = 1;
      if ($latestCode !== '' && preg_match('/^' . preg_quote(self::CODIGO_ORDEN_PREFIX, '/') . '(\d+)$/', $latestCode, $matches)) {
         $nextNumber = ((int) $matches[1]) + 1;
      }

      return static::formatCodigoOrdenFromNumber($nextNumber);
   }
}
