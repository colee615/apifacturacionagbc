<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CajaDiaria extends Model
{
    use HasFactory;

    protected $table = 'cajas_diarias';

    protected $fillable = [
        'usuario_id',
        'usuario_nombre',
        'usuario_email',
        'codigo_sucursal',
        'punto_venta',
        'fecha_operacion',
        'estado',
        'monto_apertura',
        'monto_cierre_declarado',
        'monto_ventas',
        'cantidad_ventas',
        'diferencia',
        'observacion_apertura',
        'observacion_cierre',
        'abierta_en',
        'cerrada_en',
    ];

    protected $casts = [
        'fecha_operacion' => 'date:Y-m-d',
        'abierta_en' => 'datetime',
        'cerrada_en' => 'datetime',
        'monto_apertura' => 'float',
        'monto_cierre_declarado' => 'float',
        'monto_ventas' => 'float',
        'diferencia' => 'float',
    ];
}

