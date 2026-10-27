<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class FacturacionCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'origen_usuario_id' => 'required|string|max:60',
            'origen_usuario_nombre' => 'nullable|string|max:255',
            'origen_usuario_email' => 'nullable|string|max:255',
            'origen_usuario_alias' => 'nullable|string|max:80',
            'origen_usuario_carnet' => 'nullable|string|max:40',
            'origen_sucursal_id' => 'nullable|string|max:60',
            'origen_sucursal_codigo' => 'nullable|string|max:60',
            'origen_sucursal_nombre' => 'nullable|string|max:255',
            'origen_tipo' => 'required|string|max:120',
            'origen_id' => 'required|integer|min:1',
            'confirmar_reagregar' => 'sometimes|boolean',
            'confirmar_reagregar_codigo' => 'sometimes|string|max:120',
            'codigo' => 'required_if:origen_tipo,cliente_online|nullable|string|max:120',
            'titulo' => 'required|string|max:255',
            'nombre_servicio' => 'nullable|string|max:255',
            'nombre_destinatario' => 'nullable|string|max:255',
            'servicios_extra' => 'nullable|array',
            'resumen_origen' => 'required_if:origen_tipo,cliente_online|nullable|array',
            'cantidad' => 'nullable|integer|min:1',
            'monto_base' => 'nullable|numeric|min:0',
            'monto_extras' => 'nullable|numeric|min:0',
            'total_linea' => 'nullable|numeric|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // A nested rule on resumen_origen makes validated() discard all its
        // other keys, including SIN fields for cashier items. Check the UUID
        // separately while retaining the complete integration snapshot.
        $validator->after(function (Validator $validator): void {
            if (strtolower(trim((string) $this->input('origen_tipo'))) !== 'cliente_online') {
                return;
            }

            $attemptId = $this->input('resumen_origen.qr_attempt_id');
            if (!is_string($attemptId) || !Str::isUuid($attemptId)) {
                $validator->errors()->add('resumen_origen.qr_attempt_id', 'El intento QR debe contener un UUID válido.');
            }
        });
    }
}
