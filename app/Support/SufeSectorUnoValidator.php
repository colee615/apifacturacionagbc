<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class SufeSectorUnoValidator
{
    private const INDIVIDUAL_DOCUMENT_SECTORS = [1, 5, 7, 11, 13, 17, 23, 28, 36, 50];
    private const ADJUSTMENT_DOCUMENT_SECTORS = [24, 29];
    private const MASSIVE_DOCUMENT_SECTORS = [1, 5, 7, 11, 13, 17, 23, 28, 36, 50];
    private const CAFC_DOCUMENT_SECTORS = [1, 5, 7, 11, 13, 17, 28, 50];
    private const CARD_METHODS = [2, 10, 16, 17, 18, 19, 20, 29, 39, 40, 41, 42, 43];
    private const GIFT_CARD_METHODS = [27, 30, 35, 40, 43, 49, 53, 60, 64, 68, 72];
    private const NOTIFICATION_STATES = ['EXITO', 'OBSERVADO', 'CREADO'];
    private const NOTIFICATION_TYPES = [
        'EMISION',
        'MULTIPLE',
        'MASIVO',
        'CONTINGENCIA',
        'CONTINGENCIA_CAFC',
        'ANULACION',
    ];

    public function validateIndividualPayload(array $data): array
    {
        $validator = Validator::make($data, array_merge(
            [
                'codigoOrden' => ['required', 'string', 'min:1', 'max:64'],
                'origenVenta.id' => ['nullable', 'string', 'max:100'],
                'origenVenta.tipo' => ['nullable', 'string', 'max:100'],
                'origenUsuario.id' => ['nullable', 'string', 'max:100'],
                'origenUsuario.nombre' => ['nullable', 'string', 'max:255'],
                'origenUsuario.email' => ['nullable', 'email', 'max:120'],
                'origenUsuario.alias' => ['nullable', 'string', 'max:80'],
                'origenUsuario.carnet' => ['nullable', 'string', 'max:40'],
                'origenSucursal.id' => ['nullable', 'string', 'max:100'],
                'origenSucursal.codigo' => ['nullable', 'string', 'max:100'],
                'origenSucursal.nombre' => ['nullable', 'string', 'max:255'],
                'codigoSucursal' => ['required', 'integer', 'min:0'],
                'puntoVenta' => ['required', 'integer', 'min:0'],
                'documentoSector' => ['required', 'integer', 'in:' . implode(',', self::INDIVIDUAL_DOCUMENT_SECTORS)],
            ],
            $this->invoiceRules('', 500, false, false)
        ));

        $this->applyInvoiceBusinessRules($validator, $data, '', false);

        return $validator->validate();
    }

    public function validateMassivePayload(array $data): array
    {
        $validator = Validator::make($data, array_merge(
            [
                'codigoSucursal' => ['required', 'integer', 'min:0'],
                'puntoVenta' => ['required', 'integer', 'min:0'],
                'documentoSector' => ['required', 'integer', 'in:' . implode(',', self::MASSIVE_DOCUMENT_SECTORS)],
                'facturas' => ['required', 'array', 'min:2', 'max:1000'],
            ],
            $this->invoiceRules('facturas.*.', 500, true, false)
        ));

        $this->applyInvoicesCollectionRules($validator, $data, 'facturas', false);

        return $validator->validate();
    }

    public function validateContingenciaCafcPayload(array $data): array
    {
        $validator = Validator::make($data, array_merge(
            [
                'cafc' => ['required', 'string', 'min:1', 'max:255'],
                'fechaInicio' => ['required', 'date_format:Y-m-d H:i:s'],
                'fechaFin' => ['required', 'date_format:Y-m-d H:i:s'],
                'documentoSector' => ['required', 'integer', 'in:' . implode(',', self::CAFC_DOCUMENT_SECTORS)],
                'puntoVenta' => ['required', 'integer', 'min:0'],
                'codigoSucursal' => ['required', 'integer', 'min:0'],
                'facturas' => ['required', 'array', 'min:1', 'max:500'],
                'facturas.*.nroFactura' => ['required', 'integer', 'min:1'],
            ],
            $this->invoiceRules('facturas.*.', 70, false, true)
        ));

        $this->applyContingencyWindowValidation($validator, $data);
        $this->applyInvoicesCollectionRules($validator, $data, 'facturas', true);

        return $validator->validate();
    }

    public function validateAnulacionPayload(array $data): array
    {
        return Validator::make($data, [
            'motivo' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9\s\-_.\/;,\\\\]+$/'],
            'tipoAnulacion' => ['required', 'integer', 'in:1,2,3,4'],
        ])->validate();
    }

    public function validateDocumentoAjustePayload(array $data): array
    {
        $validator = Validator::make($data, [
            'codigoOrden' => ['required', 'string', 'min:1', 'max:64'],
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'documentoSector' => ['required', 'integer', 'in:' . implode(',', self::ADJUSTMENT_DOCUMENT_SECTORS)],
            'municipio' => ['required', 'string', 'min:2', 'max:35', 'regex:/^[A-ZÀ-ſ\s\.-]+$/u'],
            'departamento' => ['nullable', 'string', 'min:2', 'max:35', 'regex:/^[A-Z\s\.-]+$/u'],
            'telefono' => ['required', 'string', 'regex:/^[0-9]{7,8}$/'],
            'razonSocial' => ['required', 'string', 'min:2', 'max:70'],
            'documentoIdentidad' => ['required', 'string', 'min:1', 'max:20'],
            'complemento' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z0-9]{2}$/'],
            'tipoDocumentoIdentidad' => ['required', 'integer', 'between:1,5'],
            'correo' => ['required', 'string', 'max:50', 'regex:/^[\w\-.]+@([\w-]+\.)+[\w-]{2,4}$/'],
            'codigoCliente' => ['required', 'string', 'min:2', 'max:35', 'regex:/^[A-Za-z0-9\s\-_]+$/'],
            'codigoExcepcion' => ['nullable', 'integer', 'in:0,1'],
            'montoTotalConciliado' => ['nullable', 'numeric', 'gt:0'],
            'creditoFiscalIva' => ['nullable', 'numeric', 'min:0'],
            'debitoFiscalIva' => ['nullable', 'numeric', 'min:0'],
            'detalle' => ['nullable', 'array', 'min:1', 'max:500'],
            'detalle.*.actividadEconomica' => ['required_with:detalle', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'detalle.*.codigoSin' => ['required_with:detalle', 'string', 'min:5', 'max:7', 'regex:/^[0-9]+$/'],
            'detalle.*.codigo' => ['required_with:detalle', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9\s\-_.]+$/'],
            'detalle.*.cantidad' => ['required_with:detalle', 'numeric', 'gt:0'],
            'detalleConciliacion' => ['nullable', 'array', 'min:1', 'max:500'],
            'detalleConciliacion.*.actividadEconomica' => ['required_with:detalleConciliacion', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'detalleConciliacion.*.codigoSin' => ['required_with:detalleConciliacion', 'string', 'min:5', 'max:7', 'regex:/^[0-9]+$/'],
            'detalleConciliacion.*.codigo' => ['required_with:detalleConciliacion', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9\s\-_.]+$/'],
            'detalleConciliacion.*.montoConciliado' => ['required_with:detalleConciliacion', 'numeric', 'gt:0'],
        ]);

        $validator->after(function ($validator) use ($data) {
            $this->validateCombinedLocation($validator, $data, '');
            $this->validateDocumentoEspecial($validator, $data, '');

            $documentoSector = (int) ($data['documentoSector'] ?? 0);

            if (in_array($documentoSector, [24], true)) {
                if (empty($data['detalle']) || !is_array($data['detalle'])) {
                    $validator->errors()->add('detalle', 'El detalle es obligatorio para documentoSector 24.');
                }
                if (!empty($data['detalleConciliacion'])) {
                    $validator->errors()->add('detalleConciliacion', 'detalleConciliacion no está permitido para documentoSector 24.');
                }
            }

            if (in_array($documentoSector, [29], true)) {
                if (empty($data['detalleConciliacion']) || !is_array($data['detalleConciliacion'])) {
                    $validator->errors()->add('detalleConciliacion', 'El detalleConciliacion es obligatorio para documentoSector 29.');
                }
                if (!array_key_exists('montoTotalConciliado', $data)) {
                    $validator->errors()->add('montoTotalConciliado', 'montoTotalConciliado es obligatorio para documentoSector 29.');
                }
                if (!array_key_exists('creditoFiscalIva', $data)) {
                    $validator->errors()->add('creditoFiscalIva', 'creditoFiscalIva es obligatorio para documentoSector 29.');
                }
                if (!array_key_exists('debitoFiscalIva', $data)) {
                    $validator->errors()->add('debitoFiscalIva', 'debitoFiscalIva es obligatorio para documentoSector 29.');
                }
                if (!empty($data['detalle'])) {
                    $validator->errors()->add('detalle', 'detalle no está permitido para documentoSector 29.');
                }
            }
        });

        return $validator->validate();
    }

    public function validateAcceptedIndividualResponse(array $data): array
    {
        return Validator::make($data, [
            'finalizado' => ['required', 'boolean', 'accepted'],
            'mensaje' => ['required', 'string'],
            'datos' => ['required', 'array'],
            'datos.codigoSeguimiento' => ['required', 'string', 'regex:/^[0-9]+$/'],
        ])->validate();
    }

    public function validateReceivedIndividualResponse(array $data): array
    {
        return Validator::make($data, [
            'finalizado' => ['required', 'boolean'],
            'mensaje' => ['required', 'string'],
            'datos' => ['required', 'array'],
            'datos.codigoSeguimiento' => ['required', 'string', 'regex:/^[0-9]+$/'],
        ])->validate();
    }

    public function validateRejectedResponse(array $data): array
    {
        return Validator::make($data, [
            'finalizado' => ['required', 'boolean'],
            'codigo' => ['nullable', 'integer'],
            'timestamp' => ['nullable'],
            'mensaje' => ['required', 'string'],
            'datos' => ['required', 'array'],
            'datos.errores' => ['required', 'array', 'min:1'],
            'datos.errores.*' => ['required', 'string'],
        ])->validate();
    }

    public function validateAcceptedMassiveResponse(array $data): array
    {
        $validator = Validator::make($data, [
            'finalizado' => ['required', 'boolean'],
            'mensaje' => ['required', 'string'],
            'datos' => ['required', 'array'],
            'datos.codigoSeguimientoPaquete' => ['nullable', 'string'],
            'datos.detalle' => ['required', 'array'],
            'datos.detalle.*.posicion' => ['required', 'integer', 'min:0'],
            'datos.detalle.*.codigoSeguimiento' => ['required', 'string'],
            'datos.detalle.*.documentoIdentidad' => ['required', 'string'],
            'datos.rechazados' => ['required', 'array'],
            'datos.rechazados.*.posicion' => ['required', 'integer', 'min:0'],
            'datos.rechazados.*.documentoIdentidad' => ['required', 'string'],
        ]);

        $validator->after(function ($validator) use ($data) {
            foreach (Arr::get($data, 'datos.rechazados', []) as $index => $rechazado) {
                $observacion = $rechazado['observacion'] ?? null;
                if (!is_string($observacion) && !is_array($observacion)) {
                    $validator->errors()->add("datos.rechazados.$index.observacion", 'La observación rechazada debe ser string o array.');
                }
            }
        });

        return $validator->validate();
    }

    public function validateAcceptedContingenciaCafcResponse(array $data): array
    {
        $validator = Validator::make($data, [
            'finalizado' => ['required', 'boolean'],
            'mensaje' => ['required', 'string'],
            'datos' => ['required', 'array'],
            'datos.codigoSeguimientoPaquete' => ['nullable', 'string'],
            'datos.detalle' => ['required', 'array'],
            'datos.detalle.*.codigoSeguimiento' => ['required', 'string'],
            'datos.detalle.*.nroFactura' => ['required'],
            'datos.detalle.*.documentoIdentidad' => ['required', 'string'],
            'datos.detalle.*.fechaEmision' => ['required', 'string'],
            'datos.rechazados' => ['present', 'array'],
            'datos.rechazados.*.nroFactura' => ['required'],
            'datos.rechazados.*.documentoIdentidad' => ['required', 'string'],
            'datos.rechazados.*.fechaEmision' => ['required', 'string'],
            'datos.rechazados.*.observacion' => ['required'],
        ]);

        return $validator->validate();
    }

    public function validateAcceptedAnulacionResponse(array $data): array
    {
        return Validator::make($data, [
            'finalizado' => ['required', 'boolean', 'accepted'],
            'mensaje' => ['required', 'string'],
            'datos' => ['required', 'array'],
            'datos.cuf' => ['required', 'string', 'min:10'],
        ])->validate();
    }

    public function validateConsultaFacturaResponse(array $data): array
    {
        $validator = Validator::make($data, [
            'estado' => ['required', 'string'],
            'codigoEstadoImpuestos' => ['nullable', 'integer'],
            'codigoOrden' => ['nullable', 'string'],
            'codFactura' => ['nullable', 'string'],
            'cuf' => ['nullable', 'string'],
            'nroFactura' => ['nullable'],
            'tipoEvento' => ['nullable', 'string'],
            'detalleFactura' => ['nullable', 'array'],
            'detalleFactura.cabecera' => ['nullable', 'array'],
            'detalleFactura.detalle' => ['nullable', 'array'],
        ]);

        return $validator->validate();
    }

    public function validateConsultaPaqueteResponse(array $data): array
    {
        return Validator::make($data, [
            'cantidadFacturas' => ['required', 'integer', 'min:0'],
            'estado' => ['required', 'string'],
            'tipoEvento' => ['required', 'string'],
            'codEmision' => ['required', 'string'],
            'fechaRecepcion' => ['required', 'string'],
            'tipoDocumentoSector' => ['required', 'integer'],
            'tipoFactura' => ['required'],
            'codigoEstado' => ['required'],
        ])->validate();
    }

    public function validateNotification(array $data, ?string $expectedCodigoSeguimiento = null): array
    {
        $validator = Validator::make($data, [
            'finalizado' => ['required', 'boolean'],
            'estado' => ['required', 'string', 'in:' . implode(',', self::NOTIFICATION_STATES)],
            'fuente' => ['required', 'string', 'in:SUFE,PPE'],
            'codigoSeguimiento' => ['required', 'string'],
            'fecha' => ['required', 'string', 'regex:/^\d{2}\/\d{2}\/\d{4}\s\d{1,2}:\d{2}:\d{2}\s(?:AM|PM)$/'],
            'mensaje' => ['required', 'string'],
            'detalle' => ['required', 'array'],
            'detalle.tipoEmision' => ['required', 'string', 'in:' . implode(',', self::NOTIFICATION_TYPES)],
            'detalle.nit' => ['required', 'string'],
            'detalle.cuf' => ['required_unless:detalle.tipoEmision,ANULACION', 'nullable', 'string'],
            'detalle.nroFactura' => ['required', 'string'],
            'detalle.codigoEstadoImpuestos' => ['nullable', 'integer'],
            'detalle.urlPdf' => ['nullable', 'string'],
            'detalle.urlXml' => ['nullable', 'string'],
            'observacion' => ['nullable', 'string'],
            'detalle.observacion' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($data, $expectedCodigoSeguimiento) {
            if ($expectedCodigoSeguimiento !== null && ($data['codigoSeguimiento'] ?? null) !== $expectedCodigoSeguimiento) {
                $validator->errors()->add('codigoSeguimiento', 'El codigoSeguimiento del body no coincide con la URL notificada.');
            }

            $tipo = Arr::get($data, 'detalle.tipoEmision');
            $estado = $data['estado'] ?? null;
            $observacion = $data['observacion'] ?? Arr::get($data, 'detalle.observacion');

            if ($estado === 'OBSERVADO' && blank($observacion)) {
                $validator->errors()->add('observacion', 'Las notificaciones observadas deben incluir observación.');
            }

            if ($estado === 'EXITO' && in_array($tipo, ['EMISION', 'MULTIPLE', 'MASIVO', 'CONTINGENCIA', 'CONTINGENCIA_CAFC'], true)) {
                if (blank(Arr::get($data, 'detalle.urlPdf'))) {
                    $validator->errors()->add('detalle.urlPdf', 'Las notificaciones exitosas deben incluir urlPdf.');
                }
                if (blank(Arr::get($data, 'detalle.urlXml'))) {
                    $validator->errors()->add('detalle.urlXml', 'Las notificaciones exitosas deben incluir urlXml.');
                }
            }

            if ($estado === 'CREADO' && $tipo !== 'CONTINGENCIA') {
                $validator->errors()->add('estado', 'El estado CREADO solo es válido para contingencia.');
            }
        });

        return $validator->validate();
    }

    private function invoiceRules(string $prefix, int $razonSocialMax, bool $withDateTime, bool $withDateOnly): array
    {
        $rules = [
            $prefix . 'codigoOrden' => ['required', 'string', 'min:1', 'max:64'],
            $prefix . 'municipio' => ['required', 'string', 'min:2', 'max:25', 'regex:/^[A-ZÀ-ſ\s\.-]+$/u'],
            $prefix . 'departamento' => ['nullable', 'string', 'min:2', 'max:15', 'regex:/^[A-Z\s\.-]+$/u'],
            $prefix . 'telefono' => ['required', 'string', 'regex:/^[0-9]{7,8}$/'],
            $prefix . 'razonSocial' => ['required', 'string', 'min:2', 'max:' . $razonSocialMax],
            $prefix . 'documentoIdentidad' => ['required', 'string', 'min:1', 'max:20'],
            $prefix . 'complemento' => ['nullable', 'string', 'size:2', 'regex:/^([0-9][A-Z]|[0-9]{2})$/'],
            $prefix . 'tipoDocumentoIdentidad' => ['required', 'integer', 'between:1,5'],
            $prefix . 'correo' => ['required', 'string', 'max:50', 'regex:/^[\w\-.]+@([\w-]+\.)+[\w-]{2,4}$/'],
            $prefix . 'codigoCliente' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9\s\-_]+$/'],
            $prefix . 'metodoPago' => ['required', 'integer', 'between:1,308'],
            $prefix . 'numeroTarjeta' => ['nullable', 'string'],
            $prefix . 'codigoMoneda' => ['nullable', 'integer', 'between:1,153'],
            $prefix . 'tipoCambio' => ['nullable', 'numeric', 'gt:0'],
            $prefix . 'montoTotal' => ['required', 'numeric', 'gt:0'],
            $prefix . 'montoGiftcard' => ['nullable', 'numeric', 'min:0'],
            $prefix . 'montoDescuentoAdicional' => ['nullable', 'numeric', 'min:0'],
            $prefix . 'formatoFactura' => ['required', 'string', 'in:rollo,pagina'],
            $prefix . 'anchoFactura' => ['nullable', 'integer', 'between:70,110'],
            $prefix . 'codigoExcepcion' => ['nullable', 'integer', 'in:0,1'],
            $prefix . 'detalle' => ['required', 'array', 'min:1', 'max:500'],
            $prefix . 'detalle.*.actividadEconomica' => ['required', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            $prefix . 'detalle.*.codigoSin' => ['required', 'string', 'min:5', 'max:7', 'regex:/^[0-9]+$/'],
            $prefix . 'detalle.*.codigo' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9\s\-_.]+$/'],
            $prefix . 'detalle.*.descripcion' => ['required', 'string', 'min:5', 'max:500'],
            $prefix . 'detalle.*.precioUnitario' => ['required', 'numeric', 'gt:0'],
            $prefix . 'detalle.*.montoDescuento' => ['nullable', 'numeric', 'gt:0'],
            $prefix . 'detalle.*.cantidad' => ['required', 'numeric', 'gt:0'],
            $prefix . 'detalle.*.unidadMedida' => ['required', 'integer', 'min:1'],
            $prefix . 'detalle.*.numeroSerie' => ['nullable', 'string', 'min:2', 'max:150', 'regex:/^[A-Za-z0-9\s\-_.\/]+$/'],
            $prefix . 'detalle.*.numeroImei' => ['nullable', 'string', 'min:2', 'max:150', 'regex:/^[A-Za-z0-9\s\-_.\/]+$/'],
        ];

        if ($withDateTime) {
            $rules[$prefix . 'fechaEmision'] = ['required', 'date_format:Y-m-d H:i:s'];
        }

        if ($withDateOnly) {
            $rules[$prefix . 'fechaEmision'] = ['required', 'date_format:Y-m-d'];
        }

        return $rules;
    }

    private function applyInvoicesCollectionRules($validator, array $data, string $key, bool $subtractGiftCard): void
    {
        foreach (Arr::get($data, $key, []) as $index => $factura) {
            $prefix = $key . '.' . $index . '.';
            $this->applyInvoiceBusinessRules($validator, $factura, $prefix, $subtractGiftCard);
        }
    }

    private function applyInvoiceBusinessRules($validator, array $data, string $errorPrefix, bool $subtractGiftCard): void
    {
        $validator->after(function ($validator) use ($data, $errorPrefix, $subtractGiftCard) {
            $this->validateCombinedLocation($validator, $data, $errorPrefix);
            $this->validateComplemento($validator, $data, $errorPrefix);
            $this->validateDocumentoEspecial($validator, $data, $errorPrefix);
            $this->validatePaymentFields($validator, $data, $errorPrefix);
            $this->validateFormatoFactura($validator, $data, $errorPrefix);
            $this->validateTotal($validator, $data, $errorPrefix, $subtractGiftCard);
        });
    }

    private function applyCombinedLocationValidation($validator, array $data, string $municipioKey, string $departamentoKey): void
    {
        $validator->after(function ($validator) use ($data, $municipioKey, $departamentoKey) {
            $municipio = trim((string) Arr::get($data, $municipioKey, ''));
            $departamento = trim((string) Arr::get($data, $departamentoKey, ''));

            if ($departamento !== '' && strcasecmp($municipio, $departamento) !== 0) {
                $combinedLength = mb_strlen($municipio . '-' . $departamento);
                if ($combinedLength > 25) {
                    $validator->errors()->add($departamentoKey, 'La longitud combinada de municipio y departamento no puede superar 25 caracteres.');
                }
            }
        });
    }

    private function applyContingencyWindowValidation($validator, array $data): void
    {
        $validator->after(function ($validator) use ($data) {
            try {
                $inicio = Carbon::createFromFormat('Y-m-d H:i:s', $data['fechaInicio'] ?? '');
                $fin = Carbon::createFromFormat('Y-m-d H:i:s', $data['fechaFin'] ?? '');
            } catch (\Throwable) {
                return;
            }

            if ($fin->lt($inicio)) {
                $validator->errors()->add('fechaFin', 'La fechaFin no puede ser menor a fechaInicio.');
            }

            foreach ($data['facturas'] ?? [] as $index => $factura) {
                try {
                    $fechaEmision = Carbon::createFromFormat('Y-m-d', $factura['fechaEmision'] ?? '');
                } catch (\Throwable) {
                    continue;
                }

                if ($fechaEmision->lt($inicio->copy()->startOfDay()) || $fechaEmision->gt($fin->copy()->endOfDay())) {
                    $validator->errors()->add("facturas.$index.fechaEmision", 'La fechaEmision debe estar dentro del rango del evento de contingencia.');
                }
            }
        });
    }

    private function validateCombinedLocation($validator, array $data, string $prefix): void
    {
        $municipio = trim((string) ($data['municipio'] ?? ''));
        $departamento = trim((string) ($data['departamento'] ?? ''));

        if ($departamento !== '' && strcasecmp($municipio, $departamento) !== 0) {
            $combinedLength = mb_strlen($municipio . '-' . $departamento);
            if ($combinedLength > 25) {
                $validator->errors()->add($prefix . 'departamento', 'La longitud combinada de municipio y departamento no puede superar 25 caracteres.');
            }
        }
    }

    private function validateComplemento($validator, array $data, string $prefix): void
    {
        $tipo = (int) ($data['tipoDocumentoIdentidad'] ?? 0);
        $complemento = $data['complemento'] ?? null;

        if ($tipo !== 1 && filled($complemento)) {
            $validator->errors()->add($prefix . 'complemento', 'El complemento solo está permitido cuando tipoDocumentoIdentidad es 1.');
        }
    }

    private function validateDocumentoEspecial($validator, array $data, string $prefix): void
    {
        $documento = (string) ($data['documentoIdentidad'] ?? '');
        $tipo = (int) ($data['tipoDocumentoIdentidad'] ?? 0);

        if (in_array($documento, ['99001', '99002', '99003'], true) && $tipo !== 5) {
            $validator->errors()->add($prefix . 'tipoDocumentoIdentidad', 'Los documentos especiales 99001, 99002 y 99003 requieren tipoDocumentoIdentidad 5.');
        }
    }

    private function validatePaymentFields($validator, array $data, string $prefix): void
    {
        $metodoPago = (int) ($data['metodoPago'] ?? 0);
        $numeroTarjeta = $data['numeroTarjeta'] ?? null;
        $montoGiftcard = $data['montoGiftcard'] ?? null;

        $requiresCard = in_array($metodoPago, self::CARD_METHODS, true);
        $requiresGiftCard = in_array($metodoPago, self::GIFT_CARD_METHODS, true);

        if ($requiresCard) {
            if (!is_string($numeroTarjeta) || !preg_match('/^[0-9]{13,16}$/', $numeroTarjeta)) {
                $validator->errors()->add($prefix . 'numeroTarjeta', 'El numeroTarjeta es obligatorio y debe tener entre 13 y 16 dígitos.');
            }
        } elseif (filled($numeroTarjeta)) {
            $validator->errors()->add($prefix . 'numeroTarjeta', 'El numeroTarjeta no está permitido para el método de pago seleccionado.');
        }

        if ($requiresGiftCard) {
            if (!is_numeric($montoGiftcard) || (float) $montoGiftcard < 0) {
                $validator->errors()->add($prefix . 'montoGiftcard', 'El montoGiftcard es obligatorio cuando el método de pago incluye GiftCard.');
            }
        } elseif (filled($montoGiftcard)) {
            $validator->errors()->add($prefix . 'montoGiftcard', 'El montoGiftcard no está permitido para el método de pago seleccionado.');
        }
    }

    private function validateFormatoFactura($validator, array $data, string $prefix): void
    {
        $formato = $data['formatoFactura'] ?? null;
        $ancho = $data['anchoFactura'] ?? null;

        if ($formato !== 'rollo' && filled($ancho)) {
            $validator->errors()->add($prefix . 'anchoFactura', 'El anchoFactura solo está permitido cuando formatoFactura es rollo.');
        }
    }

    private function validateTotal($validator, array $data, string $prefix, bool $subtractGiftCard): void
    {
        $detalles = $data['detalle'] ?? [];
        $subtotal = collect($detalles)->sum(function ($detalle) {
            $cantidad = (float) ($detalle['cantidad'] ?? 0);
            $precioUnitario = (float) ($detalle['precioUnitario'] ?? 0);
            $montoDescuento = (float) ($detalle['montoDescuento'] ?? 0);

            return ($cantidad * $precioUnitario) - $montoDescuento;
        });

        $expected = $subtotal - (float) ($data['montoDescuentoAdicional'] ?? 0);

        if ($subtractGiftCard) {
            $expected -= (float) ($data['montoGiftcard'] ?? 0);
        }

        $expected = round($expected, 2);
        $received = round((float) ($data['montoTotal'] ?? 0), 2);

        if ($expected !== $received) {
            $validator->errors()->add($prefix . 'montoTotal', 'El montoTotal no coincide con el cálculo esperado del protocolo.');
        }
    }
}
