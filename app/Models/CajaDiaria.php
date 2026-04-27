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
        'monto_cierre_esperado',
        'monto_ventas',
        'cantidad_ventas',
        'cantidad_fichas_apertura',
        'monto_fichas_apertura',
        'cantidad_fichas_ingresadas',
        'monto_fichas_ingresadas',
        'cantidad_fichas_consumidas',
        'monto_fichas_consumidas',
        'cantidad_fichas_cierre_esperado',
        'monto_fichas_cierre_esperado',
        'cantidad_fichas_cierre_declarado',
        'monto_fichas_cierre_declarado',
        'diferencia_efectivo',
        'diferencia_fichas',
        'diferencia_cantidad_fichas',
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
        'monto_cierre_esperado' => 'float',
        'monto_ventas' => 'float',
        'monto_fichas_apertura' => 'float',
        'monto_fichas_ingresadas' => 'float',
        'monto_fichas_consumidas' => 'float',
        'monto_fichas_cierre_esperado' => 'float',
        'monto_fichas_cierre_declarado' => 'float',
        'diferencia_efectivo' => 'float',
        'diferencia_fichas' => 'float',
        'diferencia' => 'float',
    ];
}

