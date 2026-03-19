<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $fillable = [
        'razonSocial',
        'documentoIdentidad',
        'complemento',
        'tipoDocumentoIdentidad',
        'correo',
        'codigoCliente',
        'estado',
    ];
}
